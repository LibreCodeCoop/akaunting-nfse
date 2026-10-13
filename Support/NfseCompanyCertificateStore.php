<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;

/**
 * Only the tenant-specific immutable revision is stored. The PFX password
 * lives in Vault; the binary lives in a private local directory.
 *
 * All errors are deliberately free of passwords, certificates and Vault replies.
 */
final class NfseCompanyCertificateStore
{
    public function __construct(
        private readonly SecretStoreInterface|\Closure $secrets,
        private readonly VaultDiagnostics $diagnostics,
    ) {
    }

    public function assertOperational(int $companyId, string $reference): void
    {
        try {
            $path = NfseCompanyCertificatePath::vaultPath($companyId, $reference);
            $result = $this->diagnostics->checkCertificatePath(VaultConfig::secretStoreConfig(), $path);
        } catch (\Throwable) {
            throw new \DomainException('Vault certificate storage cannot be verified.');
        }

        if ($result['state'] !== 'operational') {
            throw new \DomainException('Vault is not operational for this company certificate path.');
        }
    }

    public function put(int $companyId, string $reference, string $content, string $password): void
    {
        $this->assertOperational($companyId, $reference);
        $filePath = NfseCompanyCertificatePath::filePath($companyId, $reference);
        $directory = dirname($filePath);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to prepare protected certificate storage.');
        }

        $handle = @fopen($filePath, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('Certificate revision already exists or storage is unavailable.');
        }

        try {
            if (!chmod($filePath, 0o600)) {
                throw new \RuntimeException('Cannot restrict certificate file permissions.');
            }
            $length = strlen($content);
            $written = 0;

            while ($written < $length) {
                $count = fwrite($handle, substr($content, $written));
                if ($count === false || $count === 0) {
                    throw new \RuntimeException('Cannot persist the certificate file.');
                }
                $written += $count;
            }

            if (!fflush($handle)) {
                throw new \RuntimeException('Cannot flush the certificate file.');
            }
        } finally {
            fclose($handle);
        }

        $path = NfseCompanyCertificatePath::vaultPath($companyId, $reference);
        $secretStore = $this->secretStore();
        $secretStore->put($path, ['password' => $password, 'pfx_path' => $filePath]);
        $confirmed = $secretStore->get($path);

        if (($confirmed['pfx_path'] ?? null) !== $filePath
            || !is_string($confirmed['password'] ?? null)
            || !hash_equals($password, $confirmed['password'])) {
            throw new \RuntimeException('Vault certificate write could not be verified.');
        }
    }

    public function delete(int $companyId, string $reference): void
    {
        $this->assertOperational($companyId, $reference);
        $this->secretStore()->delete(NfseCompanyCertificatePath::vaultPath($companyId, $reference));

        $filePath = NfseCompanyCertificatePath::filePath($companyId, $reference);
        if (is_file($filePath) && !unlink($filePath)) {
            throw new \RuntimeException('Certificate secret was removed but local cleanup remains pending.');
        }
    }

    /**
     * Metadata-only profile updates must never instantiate Vault credentials.
     * Resolve the service only for an authorized certificate write or removal.
     */
    private function secretStore(): SecretStoreInterface
    {
        return $this->secrets instanceof \Closure ? ($this->secrets)() : $this->secrets;
    }
}

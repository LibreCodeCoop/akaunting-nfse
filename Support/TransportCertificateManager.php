<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\CertConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\PfxImportException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\SecretStoreException;

final class TransportCertificateManager
{
    /** @var \Closure(): string */
    private \Closure $temporaryDirectoryResolver;

    /** @var \Closure(string, string, string): array{string, string} */
    private \Closure $pfxImporter;

    /** @var \Closure(string, string, string): void */
    private \Closure $pemValidator;

    /**
     * @param (callable(): string)|null $temporaryDirectoryResolver
     * @param (callable(string, string, string): array{string, string})|null $pfxImporter
     * @param (callable(string, string, string): void)|null $pemValidator
     */
    public function __construct(
        ?callable $temporaryDirectoryResolver = null,
        ?callable $pfxImporter = null,
        ?callable $pemValidator = null,
    )
    {
        $this->temporaryDirectoryResolver = $temporaryDirectoryResolver !== null
            ? \Closure::fromCallable($temporaryDirectoryResolver)
            : static function (): string {
                // Prefer in-memory directory for security, but fallback to system temp dir if unavailable
                $sharedMemoryDirectory = '/dev/shm';

                if (is_dir($sharedMemoryDirectory) && is_writable($sharedMemoryDirectory)) {
                    return $sharedMemoryDirectory;
                }

                $systemTempDirectory = sys_get_temp_dir();

                if (is_dir($systemTempDirectory) && is_writable($systemTempDirectory)) {
                    return $systemTempDirectory;
                }

                throw new PfxImportException(
                    'No suitable temporary directory for mTLS transport artifacts. '
                    . 'Checked: /dev/shm, ' . $systemTempDirectory . '.'
                );
            };
        $this->pfxImporter = $pfxImporter !== null
            ? \Closure::fromCallable($pfxImporter)
            : $this->importPfx(...);
        $this->pemValidator = $pemValidator !== null
            ? \Closure::fromCallable($pemValidator)
            : $this->assertPemMaterial(...);
    }

    /**
     * @return array{0: string, 1: string, 2: \Closure(): void}
     */
    public function prepare(CertConfig $cert, SecretStoreInterface $secretStore): array
    {
        $secretPath = $cert->vaultPath !== '' ? $cert->vaultPath : 'pfx/' . $cert->cnpj;
        $secret = $secretStore->get($secretPath);
        $password = trim((string) ($secret['password'] ?? ''));

        if ($password === '') {
            throw new SecretStoreException('Missing PFX password in OpenBao secret "' . $secretPath . '" for CNPJ ' . $cert->cnpj);
        }

        $pfxPath = trim((string) ($secret['pfx_path'] ?? $cert->pfxPath));

        if ($pfxPath === '' || !is_file($pfxPath)) {
            throw new PfxImportException('PFX file not found for CNPJ ' . $cert->cnpj . ' at path ' . ($pfxPath !== '' ? $pfxPath : '[empty]'));
        }

        $pfxContent = file_get_contents($pfxPath);

        if ($pfxContent === false) {
            throw new PfxImportException('Cannot read PFX file for CNPJ ' . $cert->cnpj . ' at path ' . $pfxPath);
        }

        [$privateKeyPem, $certificatePem] = ($this->pfxImporter)($pfxContent, $password, $cert->cnpj);
        ($this->pemValidator)($certificatePem, $privateKeyPem, $cert->cnpj);

        return $this->writeTemporaryArtifacts($certificatePem, $privateKeyPem, $cert->cnpj);
    }

    /**
     * @return array{0: string, 1: string, 2: \Closure(): void}
     */
    private function writeTemporaryArtifacts(string $certificatePem, string $privateKeyPem, string $cnpj): array
    {
        $temporaryDirectory = ($this->temporaryDirectoryResolver)();
        $certificatePath = $this->createTemporaryFile($temporaryDirectory, 'nfse_tls_cert_');
        $privateKeyPath = $this->createTemporaryFile($temporaryDirectory, 'nfse_tls_key_');

        try {
            if (file_put_contents($certificatePath, $certificatePem) === false) {
                throw new PfxImportException('Failed to write transport certificate PEM for CNPJ ' . $cnpj);
            }

            if (file_put_contents($privateKeyPath, $privateKeyPem) === false) {
                throw new PfxImportException('Failed to write transport private key PEM for CNPJ ' . $cnpj);
            }

            chmod($certificatePath, 0o600);
            chmod($privateKeyPath, 0o600);

            return [
                $certificatePath,
                $privateKeyPath,
                static function () use ($certificatePath, $privateKeyPath): void {
                    if (is_file($certificatePath)) {
                        unlink($certificatePath);
                    }

                    if (is_file($privateKeyPath)) {
                        unlink($privateKeyPath);
                    }
                },
            ];
        } catch (\Throwable $throwable) {
            if (is_file($certificatePath)) {
                unlink($certificatePath);
            }

            if (is_file($privateKeyPath)) {
                unlink($privateKeyPath);
            }

            if ($throwable instanceof PfxImportException) {
                throw $throwable;
            }

            throw new PfxImportException('Failed to prepare temporary transport PEM artifacts for CNPJ ' . $cnpj, previous: $throwable);
        }
    }

    private function createTemporaryFile(string $directory, string $prefix): string
    {
        $path = tempnam($directory, $prefix);

        if ($path === false) {
            throw new PfxImportException('Failed to allocate memory-backed temporary PEM file in ' . $directory);
        }

        return $path;
    }

    private function assertPemMaterial(string $certificatePem, string $privateKeyPem, string $cnpj): void
    {
        if (!function_exists('openssl_x509_read') || !function_exists('openssl_pkey_get_private') || !function_exists('openssl_x509_check_private_key')) {
            throw new PfxImportException('PHP OpenSSL extension is required to validate transport PEM artifacts for CNPJ ' . $cnpj);
        }

        $certificate = $this->withoutPhpWarnings(static fn () => openssl_x509_read($certificatePem));

        if ($certificate === false) {
            throw new PfxImportException('Extracted PEM certificate is invalid for CNPJ ' . $cnpj);
        }

        $privateKey = $this->withoutPhpWarnings(static fn () => openssl_pkey_get_private($privateKeyPem));

        if ($privateKey === false) {
            throw new PfxImportException('Extracted PEM private key is invalid for CNPJ ' . $cnpj);
        }

        if (!openssl_x509_check_private_key($certificate, $privateKey)) {
            throw new PfxImportException('Extracted PEM certificate/private key pair is invalid for CNPJ ' . $cnpj);
        }
    }

    /**
     * @return array{string, string} [privateKeyPem, certificatePem]
     */
    private function importPfx(string $pfxContent, string $password, string $cnpj): array
    {
        $bundle = (new PfxBundleReader())->read($pfxContent, $password, $cnpj);

        return [$bundle['private_key_pem'], $bundle['certificate_pem']];
    }

    private function withoutPhpWarnings(callable $callback): mixed
    {
        set_error_handler(static function (): bool {
            return true;
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}

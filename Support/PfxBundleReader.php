<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\PfxImportException;

/**
 * Imports the certificate/private-key pair from an ICP-Brasil PKCS#12 archive.
 *
 * Native PHP OpenSSL is preferred. The CLI fallback keeps compatibility with
 * legacy PKCS#12 ciphers that OpenSSL 3 disables by default.
 */
final class PfxBundleReader
{
    /**
     * @return array{private_key_pem: string, certificate_pem: string}
     */
    public function read(string $pfxContent, string $password, string $cnpj = '[unknown]'): array
    {
        if (!function_exists('openssl_pkcs12_read')) {
            throw new PfxImportException('PHP OpenSSL extension is required to import the PFX for CNPJ ' . $cnpj);
        }

        $certs = [];
        $ok = $this->withoutPhpWarnings(
            static function () use ($pfxContent, $password, &$certs): bool {
                return openssl_pkcs12_read($pfxContent, $certs, $password);
            },
        );

        if (!$ok) {
            $nativeErrors = $this->drainOpenSslErrors();

            try {
                [$privateKeyPem, $certificatePem] = $this->readUsingLegacyCli($pfxContent, $password, $cnpj);
            } catch (PfxImportException $cliException) {
                $nativeError = $nativeErrors !== [] ? implode(' | ', $nativeErrors) : 'unknown OpenSSL error';

                throw new PfxImportException(
                    'Failed to import PFX for CNPJ ' . $cnpj . ': ' . $nativeError
                    . ' (CLI fallback failed: ' . $cliException->getMessage() . ')',
                    previous: $cliException,
                );
            }
        } else {
            $privateKeyPem = isset($certs['pkey']) && is_string($certs['pkey']) ? trim($certs['pkey']) : '';
            $certificatePem = isset($certs['cert']) && is_string($certs['cert']) ? trim($certs['cert']) : '';

            if ($privateKeyPem === '' || $certificatePem === '') {
                throw new PfxImportException('PFX import did not expose certificate/private key material for CNPJ ' . $cnpj);
            }
        }

        $this->assertMatchingPair($certificatePem, $privateKeyPem, $cnpj);

        return [
            'private_key_pem' => $privateKeyPem,
            'certificate_pem' => $certificatePem,
        ];
    }

    /**
     * @return array{string, string} [privateKeyPem, certificatePem]
     */
    private function readUsingLegacyCli(string $pfxContent, string $password, string $cnpj): array
    {
        $opensslBinary = trim((string) shell_exec('command -v openssl'));

        if ($opensslBinary === '') {
            throw new PfxImportException('openssl CLI binary is unavailable for legacy PFX fallback');
        }

        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'nfse-pfx-bundle-' . bin2hex(random_bytes(8));
        $pfxPath = $base . '.pfx';
        $passPath = $base . '.pass';
        $pemPath = $base . '.pem';

        try {
            if (file_put_contents($pfxPath, $pfxContent) === false) {
                throw new PfxImportException('Failed to stage legacy PFX fallback input for CNPJ ' . $cnpj);
            }

            if (file_put_contents($passPath, $password) === false) {
                throw new PfxImportException('Failed to stage legacy PFX fallback password for CNPJ ' . $cnpj);
            }

            chmod($pfxPath, 0o600);
            chmod($passPath, 0o600);

            $command = sprintf(
                '%s pkcs12 -legacy -in %s -passin file:%s -nodes -out %s 2>/dev/null',
                escapeshellarg($opensslBinary),
                escapeshellarg($pfxPath),
                escapeshellarg($passPath),
                escapeshellarg($pemPath),
            );

            $status = 1;
            exec($command, $output, $status);

            if ($status !== 0 || !is_file($pemPath)) {
                throw new PfxImportException('openssl CLI legacy fallback failed with exit code ' . $status);
            }

            $pemBundle = file_get_contents($pemPath);

            if ($pemBundle === false || trim($pemBundle) === '') {
                throw new PfxImportException('openssl CLI legacy fallback produced empty PEM output for CNPJ ' . $cnpj);
            }

            return $this->extractPemParts($pemBundle, $cnpj);
        } finally {
            foreach ([$pfxPath, $passPath, $pemPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * @return array{string, string} [privateKeyPem, certificatePem]
     */
    private function extractPemParts(string $pemBundle, string $cnpj): array
    {
        $privateKeyMatched = preg_match(
            '/-----BEGIN(?: RSA| EC| ENCRYPTED)? PRIVATE KEY-----.*?-----END(?: RSA| EC| ENCRYPTED)? PRIVATE KEY-----/s',
            $pemBundle,
            $privateKeyMatches,
        ) === 1;

        $certificateMatched = preg_match(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $pemBundle,
            $certificateMatches,
        ) === 1;

        if (!$privateKeyMatched || !$certificateMatched) {
            throw new PfxImportException('Failed to extract PEM certificate/private key from legacy PFX for CNPJ ' . $cnpj);
        }

        return [trim($privateKeyMatches[0]), trim($certificateMatches[0])];
    }

    private function assertMatchingPair(string $certificatePem, string $privateKeyPem, string $cnpj): void
    {
        $certificate = $this->withoutPhpWarnings(static fn () => openssl_x509_read($certificatePem));
        $privateKey = $this->withoutPhpWarnings(static fn () => openssl_pkey_get_private($privateKeyPem));

        if ($certificate === false || $privateKey === false || !openssl_x509_check_private_key($certificate, $privateKey)) {
            throw new PfxImportException('Extracted PEM certificate/private key pair is invalid for CNPJ ' . $cnpj);
        }
    }

    /**
     * @return list<string>
     */
    private function drainOpenSslErrors(): array
    {
        $errors = [];

        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors;
    }

    private function withoutPhpWarnings(callable $callback): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}

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
        if (!function_exists('openssl_pkcs12_read')) {
            throw new PfxImportException('PHP OpenSSL extension is required to import the PFX for CNPJ ' . $cnpj);
        }

        $certs = [];
        $ok = openssl_pkcs12_read($pfxContent, $certs, $password);

        if (!$ok) {
            $nativeErrors = $this->drainOpenSslErrors();

            try {
                return $this->extractLegacyPemMaterial($pfxContent, $password, $cnpj);
            } catch (PfxImportException $cliException) {
                $nativeError = $nativeErrors !== [] ? implode(' | ', $nativeErrors) : 'unknown OpenSSL error';

                throw new PfxImportException(
                    'Failed to import PFX for CNPJ ' . $cnpj . ': ' . $nativeError . ' (CLI fallback failed: ' . $cliException->getMessage() . ')',
                    previous: $cliException,
                );
            }
        }

        $privateKeyPem = isset($certs['pkey']) && is_string($certs['pkey']) ? trim($certs['pkey']) : '';
        $certificatePem = isset($certs['cert']) && is_string($certs['cert']) ? trim($certs['cert']) : '';

        if ($privateKeyPem === '' || $certificatePem === '') {
            throw new PfxImportException('PFX import did not expose certificate/private key material for CNPJ ' . $cnpj);
        }

        return [$privateKeyPem, $certificatePem];
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

    /**
     * @return array{string, string} [privateKeyPem, certificatePem]
     */
    private function extractLegacyPemMaterial(string $pfxContent, string $password, string $cnpj): array
    {
        $opensslBinary = trim((string) shell_exec('command -v openssl'));

        if ($opensslBinary === '') {
            throw new PfxImportException('openssl CLI binary is unavailable for legacy PFX fallback');
        }

        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'nfse-transport-' . bin2hex(random_bytes(8));
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
            if (is_file($pfxPath)) {
                unlink($pfxPath);
            }

            if (is_file($passPath)) {
                unlink($passPath);
            }

            if (is_file($pemPath)) {
                unlink($pemPath);
            }
        }
    }

    /**
     * @return array{string, string} [privateKeyPem, certificatePem]
     */
    private function extractPemParts(string $pemBundle, string $cnpj): array
    {
        $privateKeyMatched = preg_match(
            '/-----BEGIN(?: ENCRYPTED)? PRIVATE KEY-----.*?-----END(?: ENCRYPTED)? PRIVATE KEY-----/s',
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

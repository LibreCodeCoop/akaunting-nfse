<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

class PfxReader
{
    /** @var \Closure(string, string): ?string */
    private \Closure $nativeReader;

    /** @var \Closure(string, string): ?string */
    private \Closure $legacyReader;

    /**
     * @param (callable(string, string): ?string)|null $nativeReader
     * @param (callable(string, string): ?string)|null $legacyReader
     */
    public function __construct(?callable $nativeReader = null, ?callable $legacyReader = null)
    {
        $this->nativeReader = $nativeReader !== null
            ? $nativeReader(...)
            : self::readUsingPhp(...);

        $this->legacyReader = $legacyReader !== null
            ? $legacyReader(...)
            : self::readUsingLegacyCli(...);
    }

    /**
     * Returns the PEM certificate extracted from a PKCS#12 archive.
     *
     * First tries PHP native OpenSSL APIs. If that fails (common with
     * legacy PFX algorithms under OpenSSL 3), falls back to CLI mode with
     * `openssl pkcs12 -legacy`.
     *
     * @throws \RuntimeException
     */
    public function extractCertificatePem(string $pfxContent, string $password): string
    {
        $pem = ($this->nativeReader)($pfxContent, $password);

        if ($pem !== null) {
            return $pem;
        }

        $pem = ($this->legacyReader)($pfxContent, $password);

        if ($pem !== null) {
            return $pem;
        }

        throw new \RuntimeException('Invalid PFX content or wrong password.');
    }

    public static function readCertificatePem(string $pfxContent, string $password): string
    {
        return (new self())->extractCertificatePem($pfxContent, $password);
    }

    private static function readUsingPhp(string $pfxContent, string $password): ?string
    {
        $certs = [];

        if (!openssl_pkcs12_read($pfxContent, $certs, $password)) {
            return null;
        }

        if (!isset($certs['cert']) || !is_string($certs['cert']) || trim($certs['cert']) === '') {
            return null;
        }

        return $certs['cert'];
    }

    private static function readUsingLegacyCli(string $pfxContent, string $password): ?string
    {
        $bin = trim((string) shell_exec('command -v openssl'));

        if ($bin === '') {
            return null;
        }

        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'nfse-pfx-' . bin2hex(random_bytes(8));
        $pfxPath = $base . '.pfx';
        $passPath = $base . '.pass';
        $pemPath = $base . '.pem';

        try {
            if (file_put_contents($pfxPath, $pfxContent) === false) {
                return null;
            }

            if (file_put_contents($passPath, $password) === false) {
                return null;
            }

            $command = sprintf(
                '%s pkcs12 -legacy -in %s -clcerts -nokeys -passin file:%s -out %s 2>/dev/null',
                escapeshellarg($bin),
                escapeshellarg($pfxPath),
                escapeshellarg($passPath),
                escapeshellarg($pemPath),
            );

            $status = 1;
            exec($command, $output, $status);

            if ($status !== 0 || !is_file($pemPath)) {
                return null;
            }

            $pem = file_get_contents($pemPath);

            return ($pem === false || trim($pem) === '') ? null : $pem;
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
}

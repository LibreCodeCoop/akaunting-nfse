<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Support;

final class SyntheticPkcs12
{
    /**
     * @return array{path:string,password:string}
     */
    public static function create(string $directory, string $password = 'nfse-test-password'): array
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('OpenSSL extension is required for synthetic PKCS#12 tests.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create synthetic certificate directory.');
        }

        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            throw new \RuntimeException('Unable to create synthetic private key.');
        }

        $csr = openssl_csr_new([
            'countryName' => 'BR',
            'stateOrProvinceName' => 'RJ',
            'localityName' => 'Niteroi',
            'organizationName' => 'LibreCode Test',
            'commonName' => 'NFS-e deterministic test certificate',
        ], $privateKey, ['digest_alg' => 'sha256']);

        if ($csr === false) {
            throw new \RuntimeException('Unable to create synthetic certificate request.');
        }

        $certificate = openssl_csr_sign($csr, null, $privateKey, 2, ['digest_alg' => 'sha256']);

        if ($certificate === false) {
            throw new \RuntimeException('Unable to self-sign synthetic certificate.');
        }

        $pkcs12 = '';
        if (!openssl_pkcs12_export($certificate, $pkcs12, $privateKey, $password)) {
            throw new \RuntimeException('Unable to export synthetic PKCS#12 certificate.');
        }

        $path = $directory . '/synthetic-nfse-test.p12';

        if (file_put_contents($path, $pkcs12) === false) {
            throw new \RuntimeException('Unable to write synthetic PKCS#12 certificate.');
        }

        chmod($path, 0o600);

        return [
            'path' => $path,
            'password' => $password,
        ];
    }
}

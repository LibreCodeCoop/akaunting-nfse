<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Support;

/**
 * Test-only self-signed certificate with a synthetically embedded CNPJ.
 * Never represents an ICP-Brasil identity or permits real NFS-e issuance.
 */
final class SyntheticCompanyPkcs12
{
    public const PASSWORD = 'synthetic-pfx-password';

    public static function bytes(string $cnpj = '12345678000195'): string
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if ($privateKey === false) {
            throw new \RuntimeException('Synthetic private key generation failed.');
        }

        $request = openssl_csr_new([
            'countryName' => 'BR',
            'organizationName' => 'NFS-e Synthetic Fixtures',
            'commonName' => 'SYNTHETIC CERTIFICATE:' . $cnpj,
        ], $privateKey, ['digest_alg' => 'sha256']);

        if ($request === false) {
            throw new \RuntimeException('Synthetic certificate request failed.');
        }

        $certificate = openssl_csr_sign($request, null, $privateKey, 2, ['digest_alg' => 'sha256']);
        $pfx = '';
        if ($certificate === false
            || !openssl_pkcs12_export($certificate, $pfx, $privateKey, self::PASSWORD)) {
            throw new \RuntimeException('Synthetic PKCS#12 creation failed.');
        }

        return $pfx;
    }
}

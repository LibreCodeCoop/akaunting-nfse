<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

/**
 * Reads a PKCS#12 (PFX) file and extracts business data embedded by the
 * certificate authority.
 *
 * Brazilian ICP-Brasil A1/A3 certificates store the CNPJ (14 digits) of the
 * legal entity in one or more Subject fields:
 *   - CommonName (CN):     "NOME DA EMPRESA:12345678000195"
 *   - SerialNumber:        "12345678000195"
 *
 * This class tries each known location in order and returns the first match.
 */
class PfxParser
{
    private const CNPJ_PATTERN = '/\b(\d{14})\b/';

    /**
     * @param  string $pfxContent  Raw binary content of the PKCS#12 file.
     * @param  string $password    Password protecting the PKCS#12 archive.
     * @return array{cnpj: string|null}
     *
     * @throws \RuntimeException if the content cannot be read (bad file or wrong password).
     */
    public static function extractFromContent(string $pfxContent, string $password): array
    {
        $certs = [];

        if (!openssl_pkcs12_read($pfxContent, $certs, $password)) {
            throw new \RuntimeException('Invalid PFX content or wrong password.');
        }

        if (!isset($certs['cert'])) {
            return ['cnpj' => null];
        }

        $x509 = openssl_x509_read($certs['cert']);

        if ($x509 === false) {
            return ['cnpj' => null];
        }

        $info = openssl_x509_parse($x509, true);

        if ($info === false) {
            return ['cnpj' => null];
        }

        return ['cnpj' => self::findCnpj($info)];
    }

    /**
     * Walk through the Subject fields that Brazilian CAs use and return the
     * first 14-digit sequence found.
     *
     * @param  array<string, mixed> $certInfo  Result of openssl_x509_parse().
     */
    private static function findCnpj(array $certInfo): ?string
    {
        $subject = is_array($certInfo['subject'] ?? null) ? $certInfo['subject'] : [];

        foreach (['CN', 'serialNumber', 'O', 'OU'] as $field) {
            $value = isset($subject[$field]) && is_string($subject[$field])
                ? $subject[$field]
                : '';

            if ($value !== '' && preg_match(self::CNPJ_PATTERN, $value, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}

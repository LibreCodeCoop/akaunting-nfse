<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\PfxImportException;

final class PfxInspector
{
    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly PfxBundleReader $bundleReader = new PfxBundleReader(),
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn (): int => time();
    }

    /**
     * @return array{
     *   cnpj: string|null,
     *   valid_from: int,
     *   valid_to: int,
     *   valid_from_iso: string,
     *   valid_to_iso: string,
     *   days_until_expiry: int,
     *   is_currently_valid: bool,
     *   expires_soon: bool,
     *   fingerprint_sha256: string
     * }
     */
    public function inspect(string $pfxContent, string $password): array
    {
        $bundle = $this->bundleReader->read($pfxContent, $password);
        $certificatePem = $bundle['certificate_pem'];

        $x509 = openssl_x509_read($certificatePem);

        if ($x509 === false) {
            throw new PfxImportException('Extracted A1 certificate is invalid.');
        }

        $info = openssl_x509_parse($x509, true);

        if (!is_array($info)) {
            throw new PfxImportException('Unable to parse A1 certificate metadata.');
        }

        $validFrom = isset($info['validFrom_time_t']) ? (int) $info['validFrom_time_t'] : 0;
        $validTo = isset($info['validTo_time_t']) ? (int) $info['validTo_time_t'] : 0;

        if ($validFrom <= 0 || $validTo <= 0 || $validTo <= $validFrom) {
            throw new PfxImportException('A1 certificate validity period is missing or invalid.');
        }

        $fingerprint = openssl_x509_fingerprint($x509, 'sha256');

        if (!is_string($fingerprint) || $fingerprint === '') {
            throw new PfxImportException('Unable to calculate A1 certificate fingerprint.');
        }

        $now = ($this->clock)();
        $daysUntilExpiry = (int) floor(($validTo - $now) / 86400);
        $cnpjData = PfxParser::extractFromCertificatePem($certificatePem);

        return [
            'cnpj' => $cnpjData['cnpj'],
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'valid_from_iso' => gmdate(DATE_ATOM, $validFrom),
            'valid_to_iso' => gmdate(DATE_ATOM, $validTo),
            'days_until_expiry' => $daysUntilExpiry,
            'is_currently_valid' => $now >= $validFrom && $now <= $validTo,
            'expires_soon' => $daysUntilExpiry >= 0 && $daysUntilExpiry <= 30,
            'fingerprint_sha256' => strtoupper(str_replace(':', '', $fingerprint)),
        ];
    }
}

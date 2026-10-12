<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Categorizes actual withholding entries by tax. The total of PIS, COFINS
 * and CSLL selected by tpRetPisCofins populates vRetCSLL (NT007/2026).
 */
final class FederalSocialRetentionCalculator
{
    /**
     * @param list<array<string,mixed>> $withholdingRows
     * @return array{pis:string,cofins:string,csll:string,total:string}
     */
    public function calculate(string $retentionType, array $withholdingRows): array
    {
        $allowed = self::allowed($retentionType);
        $cents = ['pis' => 0, 'cofins' => 0, 'csll' => 0];
        $classifier = new FederalTaxSnapshotBuilder();

        foreach ($withholdingRows as $row) {
            $type = trim((string) ($row['tax_type'] ?? 'withholding'));
            if ($type !== 'withholding') {
                continue;
            }

            $bucket = $classifier->bucketFromName((string) ($row['name'] ?? ''));
            if ($bucket === null || !in_array($bucket, $allowed, true)) {
                continue;
            }

            $amount = $row['amount'] ?? null;
            if (!is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }

            $cents[$bucket] += (int) round((float) $amount * 100);
        }

        $totalCents = array_sum($cents);
        $formatted = [];
        foreach ($cents as $bucket => $value) {
            $formatted[$bucket] = $value > 0 ? number_format($value / 100, 2, '.', '') : '';
        }

        $formatted['total'] = $totalCents > 0
            ? number_format($totalCents / 100, 2, '.', '')
            : '';

        return $formatted;
    }
    /**
     * Production-only NT007 arithmetic from confirmed, persisted tax facts.
     * A configured retention code cannot conceal an incompatible tax amount.
     *
     * @param array{pis:int,cofins:int,csll:int} $retainedCents
     * @return array{pis:string,cofins:string,csll:string,total:string}
     */
    public function calculateConfirmed(string $retentionType, array $retainedCents): array
    {
        $allowed = self::allowed($retentionType);
        $total = 0;
        $result = ['pis' => '', 'cofins' => '', 'csll' => ''];

        foreach ($result as $code => $_) {
            $cents = $retainedCents[$code] ?? null;
            if (!is_int($cents) || $cents < 0) {
                throw new \DomainException('Social withholding must have verified nonnegative integer cents.');
            }
            if ($cents > 0 && !in_array($code, $allowed, true)) {
                throw new \DomainException('NFSE-TAX-RETENTION-TYPE-CONFLICT: observed withholding is not selected by tpRetPisCofins.');
            }
            if ($cents > PHP_INT_MAX - $total) {
                throw new \OverflowException('Social withholding total exceeds integer-cent capacity.');
            }
            $total += $cents;
            if ($cents > 0) {
                $result[$code] = \Modules\Nfse\Support\ExactTaxDecimal::formatCents($cents);
            }
        }

        $result['total'] = $total > 0
            ? \Modules\Nfse\Support\ExactTaxDecimal::formatCents($total)
            : '';

        return $result;
    }

    /** @return list<'pis'|'cofins'|'csll'> */
    private static function allowed(string $retentionType): array
    {
        return match ($retentionType) {
            '1', '4' => ['pis', 'cofins'],
            '3' => ['pis', 'cofins', 'csll'],
            '5' => ['pis'],
            '6' => ['cofins'],
            '7' => ['cofins', 'csll'],
            '8' => ['csll'],
            '9' => ['pis', 'csll'],
            default => [],
        };
    }
}

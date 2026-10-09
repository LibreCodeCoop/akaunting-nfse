<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Maps actual Akaunting withholding entries to the consolidated vRetCSLL
 * required by NT SE/CGNFS-e 007/2026. CSLL may be zero and PIS/COFINS retained.
 */
final class FederalSocialRetentionCalculator
{
    /**
     * @param list<array<string,mixed>> $withholdingRows
     * @return array{pis:string,cofins:string,csll:string,total:string}
     */
    public function calculate(string $retentionType, array $withholdingRows): array
    {
        $allowed = match ($retentionType) {
            '1', '4' => ['pis', 'cofins'],
            '3' => ['pis', 'cofins', 'csll'],
            '5' => ['pis'],
            '6' => ['cofins'],
            '7' => ['cofins', 'csll'],
            '8' => ['csll'],
            '9' => ['pis', 'csll'],
            default => [],
        };
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
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Pure classification, deduplication, and math for federal taxes attached to invoice items.
 *
 * Framework-specific rate lookup is supplied by the caller.
 */
final class FederalTaxSnapshotBuilder
{
    /**
     * @param list<array<string,mixed>> $items
     * @param list<int>|null $documentItemIds
     * @param callable(mixed):?float $taxRateResolver
     * @return array{pis_value:string,pis_rate:string,cofins_value:string,cofins_rate:string,irrf_value:string,csll_value:string,federal_percent:string}
     */
    public function build(
        array $items,
        float $baseAmount,
        ?array $documentItemIds,
        callable $taxRateResolver,
    ): array {
        $totals = [
            'pis' => 0.0,
            'cofins' => 0.0,
            'irrf' => 0.0,
            'csll' => 0.0,
            'cp' => 0.0,
        ];
        $rateTotals = $totals;
        $seenTaxes = [];
        $seenRateKeys = [];

        foreach ($items as $item) {
            $documentItemId = is_numeric($item['id'] ?? null) ? (int) $item['id'] : 0;

            if ($documentItemIds !== null && !in_array($documentItemId, $documentItemIds, true)) {
                continue;
            }

            $taxes = array_merge(
                is_array($item['taxes'] ?? null) ? $item['taxes'] : [],
                is_array($item['item_taxes'] ?? null) ? $item['item_taxes'] : [],
            );

            foreach ($taxes as $tax) {
                $name = trim((string) (is_array($tax) ? ($tax['name'] ?? '') : ($tax->name ?? '')));
                $amountRaw = is_array($tax) ? ($tax['amount'] ?? null) : ($tax->amount ?? null);

                if ($name === '' || !is_numeric($amountRaw)) {
                    continue;
                }

                $amount = (float) $amountRaw;

                if ($amount <= 0) {
                    continue;
                }

                $signature = strtolower($name) . '|' . number_format($amount, 4, '.', '');

                if (isset($seenTaxes[$signature])) {
                    continue;
                }

                $seenTaxes[$signature] = true;
                $bucket = $this->bucketFromName($name);

                if ($bucket === null) {
                    continue;
                }

                $totals[$bucket] += $amount;
                $resolvedRate = $taxRateResolver($tax);

                if ($resolvedRate === null || $resolvedRate <= 0) {
                    continue;
                }

                $rateKey = $this->rateDedupKey($tax, $bucket, $name, $resolvedRate);

                if (isset($seenRateKeys[$rateKey])) {
                    continue;
                }

                $seenRateKeys[$rateKey] = true;
                $rateTotals[$bucket] += $resolvedRate;
            }
        }

        // Approximate federal tax burden is distinct from withholding.
        // IRRF/CSLL/CP are retention amounts and must not inflate pTotTribFed.
        $federalTotal = $totals['pis'] + $totals['cofins'];
        $federalRateTotal = $rateTotals['pis'] + $rateTotals['cofins'];

        return [
            'pis_value' => $this->positiveDecimal($totals['pis']),
            'pis_rate' => $rateTotals['pis'] > 0
                ? number_format($rateTotals['pis'], 2, '.', '')
                : $this->rateFromAmount($totals['pis'], $baseAmount),
            'cofins_value' => $this->positiveDecimal($totals['cofins']),
            'cofins_rate' => $rateTotals['cofins'] > 0
                ? number_format($rateTotals['cofins'], 2, '.', '')
                : $this->rateFromAmount($totals['cofins'], $baseAmount),
            'irrf_value' => $this->positiveDecimal($totals['irrf']),
            'csll_value' => $this->positiveDecimal($totals['csll']),
            'federal_percent' => $federalRateTotal > 0
                ? number_format($federalRateTotal, 2, '.', '')
                : $this->rateFromAmount($federalTotal, $baseAmount),
        ];
    }

    public function bucketFromName(string $name): ?string
    {
        $normalizedName = $this->normalize($name);

        if ($normalizedName === '') {
            return null;
        }

        if (
            preg_match('/\b(?:cod|codigo|cst)\s*[:\-]?\s*(pis|cofins|irrf|csll|inss|cp)\b/', $normalizedName, $matches) === 1
            || preg_match('/\[(pis|cofins|irrf|csll|inss|cp)\]/', $normalizedName, $matches) === 1
        ) {
            return match ($matches[1]) {
                'pis' => 'pis',
                'cofins' => 'cofins',
                'irrf' => 'irrf',
                'csll' => 'csll',
                'inss', 'cp' => 'cp',
                default => null,
            };
        }

        $terms = [
            'cofins' => [
                'cofins',
                'contribuicao para o financiamento da seguridade social',
                'financiamento da seguridade social',
            ],
            'irrf' => [
                'irrf',
                'imposto de renda retido na fonte',
                'renda retida na fonte',
                'imposto de renda fonte',
            ],
            'csll' => [
                'csll',
                'contribuicao social sobre o lucro liquido',
                'contribuicao social lucro liquido',
            ],
            'cp' => [
                'inss',
                'contribuicao previd',
                'contribuicao previdenciaria',
                'previdencia social',
            ],
            'pis' => [
                'pis',
                'pasep',
                'programa de integracao social',
                'programa de formacao do patrimonio do servidor publico',
            ],
        ];

        foreach ($terms as $bucket => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($normalizedName, $alias)) {
                    return $bucket;
                }
            }
        }

        return null;
    }

    private function rateDedupKey(mixed $tax, string $bucket, string $name, float $rate): string
    {
        $taxIdRaw = is_array($tax) ? ($tax['tax_id'] ?? null) : ($tax->tax_id ?? null);

        if (is_numeric($taxIdRaw) && (int) $taxIdRaw > 0) {
            return $bucket . '|tax_id:' . (int) $taxIdRaw;
        }

        return $bucket . '|name:' . strtolower($name) . '|rate:' . number_format($rate, 4, '.', '');
    }

    private function normalize(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = strtr($normalized, [
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ç' => 'c',
        ]);

        $collapsed = preg_replace('/[^a-z0-9\[\]\-:\s]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $collapsed) ?? $collapsed);
    }

    private function positiveDecimal(float $value): string
    {
        return $value > 0 ? number_format($value, 2, '.', '') : '';
    }

    private function rateFromAmount(float $taxValue, float $baseAmount): string
    {
        if ($taxValue <= 0 || $baseAmount <= 0) {
            return '';
        }

        return number_format(($taxValue / $baseAmount) * 100, 2, '.', '');
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Support\Lc116Code;

/**
 * Selects the fiscal signature represented by Akaunting invoice line items.
 *
 * Database/framework lookups stay in the adapter. This class only resolves
 * already-loaded fiscal profiles and tax rates into the emission input.
 */
final class InvoiceFiscalProfileSelector
{
    /**
     * @param list<array<string, mixed>> $items
     * @param array<int, array{item_lista_servico:string,codigo_tributacao_nacional:string,codigo_tributacao_municipal:string}> $profileMap
     * @param array<int, string> $taxRateMap
     * @return array{item_lista_servico:string,codigo_tributacao_nacional:string,codigo_tributacao_municipal:string,aliquota:string,line_items:list<string>,requires_split:bool}
     */
    public function select(
        array $items,
        array $profileMap,
        array $taxRateMap,
        string $defaultServiceCode,
        string $defaultNationalCode,
        string $defaultRate,
        string $unnamedItemLabel,
    ): array {
        $defaultServiceCode = Lc116Code::normalize($defaultServiceCode);
        $lineItems = [];
        $signatures = [];
        $selected = [
            'item_lista_servico' => $defaultServiceCode,
            'codigo_tributacao_nacional' => $defaultNationalCode,
            'codigo_tributacao_municipal' => '',
            'aliquota' => $defaultRate,
        ];

        foreach ($items as $item) {
            $itemId = is_numeric($item['item_id'] ?? null) ? (int) $item['item_id'] : 0;
            $itemName = trim((string) ($item['name'] ?? ''));
            $itemName = $itemName !== '' ? $itemName : $unnamedItemLabel;

            $profile = $itemId > 0 ? ($profileMap[$itemId] ?? null) : null;
            $serviceCode = Lc116Code::normalize($profile['item_lista_servico'] ?? '');

            if ($serviceCode === '') {
                $serviceCode = $defaultServiceCode;
            }

            $nationalCode = preg_replace('/\D+/', '', (string) ($profile['codigo_tributacao_nacional'] ?? '')) ?: '';

            if ($nationalCode === '') {
                $nationalCode = $defaultNationalCode;
            }

            $municipalCode = preg_replace('/\D+/', '', (string) ($profile['codigo_tributacao_municipal'] ?? '')) ?: '';

            $rate = $itemId > 0 ? ($taxRateMap[$itemId] ?? '') : '';

            if ($rate === '') {
                $rate = $defaultRate;
            }

            $lineItems[] = $serviceCode !== ''
                ? '[' . $serviceCode . '] ' . $itemName
                : $itemName;

            $signature = $serviceCode . '|' . $nationalCode . '|' . $municipalCode . '|' . $rate;

            if ($signature === '||') {
                continue;
            }

            $signatures[$signature] = true;

            if ($selected['item_lista_servico'] === '' || $selected['item_lista_servico'] === $defaultServiceCode) {
                $selected = [
                    'item_lista_servico' => $serviceCode,
                    'codigo_tributacao_nacional' => $nationalCode,
                    'codigo_tributacao_municipal' => $municipalCode,
                    'aliquota' => $rate,
                ];
            }
        }

        return [
            'item_lista_servico' => $selected['item_lista_servico'] !== '' ? $selected['item_lista_servico'] : $defaultServiceCode,
            'codigo_tributacao_nacional' => $selected['codigo_tributacao_nacional'] !== '' ? $selected['codigo_tributacao_nacional'] : $defaultNationalCode,
            'codigo_tributacao_municipal' => $selected['codigo_tributacao_municipal'] ?? '',
            'aliquota' => $selected['aliquota'] !== '' ? $selected['aliquota'] : $defaultRate,
            'line_items' => $lineItems,
            'requires_split' => count($signatures) > 1,
        ];
    }
}

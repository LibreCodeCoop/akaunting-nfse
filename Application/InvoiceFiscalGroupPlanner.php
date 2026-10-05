<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Support\Lc116Code;

/**
 * Plans independent NFS-e emission groups from Akaunting invoice items.
 *
 * Items with the same service code, national taxation code and ISS rate remain
 * in one fiscal document. Amounts are summed from the persisted item totals;
 * no rounding value is redistributed between groups.
 */
final class InvoiceFiscalGroupPlanner
{
    /**
     * @param list<array<string, mixed>> $items
     * @param array<int, array{item_lista_servico:string,codigo_tributacao_nacional:string}> $profileMap
     * @param array<int, string> $taxRateMap
     * @return list<array{
     *   key:string,
     *   item_lista_servico:string,
     *   codigo_tributacao_nacional:string,
     *   aliquota:string,
     *   item_ids:list<int>,
     *   line_items:list<string>,
     *   amount:string
     * }>
     */
    public function plan(
        array $items,
        array $profileMap,
        array $taxRateMap,
        string $defaultServiceCode,
        string $defaultNationalCode,
        string $defaultRate,
        string $unnamedItemLabel,
    ): array {
        $groups = [];
        $defaultServiceCode = Lc116Code::normalize($defaultServiceCode);

        foreach ($items as $item) {
            $itemId = is_numeric($item['item_id'] ?? null) ? (int) $item['item_id'] : 0;
            $itemName = trim((string) ($item['name'] ?? ''));
            $itemName = $itemName !== '' ? $itemName : $unnamedItemLabel;
            $profile = $itemId > 0 ? ($profileMap[$itemId] ?? null) : null;

            $serviceCode = Lc116Code::normalize($profile['item_lista_servico'] ?? '');
            $serviceCode = $serviceCode !== '' ? $serviceCode : $defaultServiceCode;

            $nationalCode = preg_replace('/\D+/', '', (string) ($profile['codigo_tributacao_nacional'] ?? '')) ?: '';
            $nationalCode = $nationalCode !== '' ? $nationalCode : $defaultNationalCode;

            $rate = $itemId > 0 ? ($taxRateMap[$itemId] ?? '') : '';
            $rate = $rate !== '' ? $rate : $defaultRate;

            $key = hash('sha256', $serviceCode . '|' . $nationalCode . '|' . $rate);
            $line = $serviceCode !== '' ? '[' . $serviceCode . '] ' . $itemName : $itemName;
            $amountCents = $this->moneyToCents($item['total'] ?? 0);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'item_lista_servico' => $serviceCode,
                    'codigo_tributacao_nacional' => $nationalCode,
                    'aliquota' => $rate,
                    'item_ids' => [],
                    'line_items' => [],
                    'amount_cents' => 0,
                ];
            }

            if ($itemId > 0) {
                $groups[$key]['item_ids'][] = $itemId;
            }

            $groups[$key]['line_items'][] = $line;
            $groups[$key]['amount_cents'] += $amountCents;
        }

        return array_values(array_map(
            static fn (array $group): array => [
                'key' => $group['key'],
                'item_lista_servico' => $group['item_lista_servico'],
                'codigo_tributacao_nacional' => $group['codigo_tributacao_nacional'],
                'aliquota' => $group['aliquota'],
                'item_ids' => $group['item_ids'],
                'line_items' => $group['line_items'],
                'amount' => number_format($group['amount_cents'] / 100, 2, '.', ''),
            ],
            $groups,
        ));
    }

    private function moneyToCents(mixed $value): int
    {
        $normalized = str_replace(',', '.', trim((string) $value));

        if ($normalized === '' || !is_numeric($normalized)) {
            return 0;
        }

        return (int) round((float) $normalized * 100);
    }
}

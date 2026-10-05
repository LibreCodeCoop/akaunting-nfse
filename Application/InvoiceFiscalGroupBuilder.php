<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Support\Lc116Code;

/**
 * Groups persisted Akaunting invoice lines by the exact fiscal signature used
 * for NFS-e issuance.
 *
 * The class only organizes already-resolved inputs. It does not mutate the
 * accounting invoice, infer missing fiscal data or redistribute rounding.
 */
final class InvoiceFiscalGroupBuilder
{
    /**
     * @param list<array<string,mixed>> $items
     * @param array<int,array{item_lista_servico:string,codigo_tributacao_nacional:string}> $profileMap
     * @param array<int,string> $taxRateMap
     * @return list<array{
     *   key:string,
     *   item_lista_servico:string,
     *   codigo_tributacao_nacional:string,
     *   aliquota:string,
     *   amount:string,
     *   items:list<array{document_item_id:int,item_id:int,name:string,amount:string}>
     * }>
     */
    public function build(
        array $items,
        array $profileMap,
        array $taxRateMap,
        string $defaultServiceCode,
        string $defaultNationalCode,
        string $defaultRate,
    ): array {
        $defaultServiceCode = Lc116Code::normalize($defaultServiceCode);
        $groups = [];

        foreach ($items as $item) {
            $itemId = is_numeric($item['item_id'] ?? null) ? (int) $item['item_id'] : 0;
            $documentItemId = is_numeric($item['id'] ?? null) ? (int) $item['id'] : 0;
            $profile = $itemId > 0 ? ($profileMap[$itemId] ?? null) : null;

            $serviceCode = Lc116Code::normalize($profile['item_lista_servico'] ?? '');
            if ($serviceCode === '') {
                $serviceCode = $defaultServiceCode;
            }

            $nationalCode = preg_replace(
                '/\D+/',
                '',
                (string) ($profile['codigo_tributacao_nacional'] ?? ''),
            ) ?: '';
            if ($nationalCode === '') {
                $nationalCode = preg_replace('/\D+/', '', $defaultNationalCode) ?: '';
            }

            $rate = $itemId > 0 ? trim((string) ($taxRateMap[$itemId] ?? '')) : '';
            if ($rate === '') {
                $rate = trim($defaultRate);
            }

            $key = 'service:' . $serviceCode . '|tax:' . $nationalCode . '|rate:' . $rate;
            $minor = $this->decimalToMinor($item['total'] ?? null);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'item_lista_servico' => $serviceCode,
                    'codigo_tributacao_nacional' => $nationalCode,
                    'aliquota' => $rate,
                    'amount_minor' => 0,
                    'items' => [],
                ];
            }

            $groups[$key]['amount_minor'] += $minor;
            $groups[$key]['items'][] = [
                'document_item_id' => $documentItemId,
                'item_id' => $itemId,
                'name' => trim((string) ($item['name'] ?? '')),
                'amount' => $this->formatMinor($minor),
            ];
        }

        return array_values(array_map(function (array $group): array {
            $amountMinor = (int) $group['amount_minor'];
            unset($group['amount_minor']);
            $group['amount'] = $this->formatMinor($amountMinor);

            return $group;
        }, $groups));
    }

    private function decimalToMinor(mixed $value): int
    {
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException('Invoice item total must be a decimal value.');
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        if (preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized) !== 1) {
            throw new \InvalidArgumentException(
                'Invoice item total must use at most two decimal places.',
            );
        }

        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$minor : $minor;
    }

    private function formatMinor(int $minor): string
    {
        $negative = $minor < 0;
        $minor = abs($minor);
        $formatted = intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);

        return $negative ? '-' . $formatted : $formatted;
    }
}

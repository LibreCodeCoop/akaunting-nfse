<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Application\ItemMunicipalParameterValidator;

final class ItemMunicipalValidationResolver
{
    /**
     * @return array<string,mixed>
     */
    public function resolve(
        int $itemId,
        int $companyId,
        string $nationalCode,
        string $municipioIbge,
        bool $sandboxMode,
    ): array {
        $results = $this->resolveMany(
            itemNationalCodes: [$itemId => $nationalCode],
            companyId: $companyId,
            municipioIbge: $municipioIbge,
            sandboxMode: $sandboxMode,
        );

        return $results[$itemId] ?? (new ItemMunicipalParameterValidator())->validate(null, null);
    }

    /**
     * Resolve a visible page of items with a bounded query count.
     *
     * @param array<int,string> $itemNationalCodes
     * @return array<int,array<string,mixed>>
     */
    public function resolveMany(
        array $itemNationalCodes,
        int $companyId,
        string $municipioIbge,
        bool $sandboxMode,
    ): array {
        if ($companyId <= 0 || preg_match('/^\d{7}$/', $municipioIbge) !== 1 || $itemNationalCodes === []) {
            return [];
        }

        $normalizedCodes = [];
        foreach ($itemNationalCodes as $itemId => $nationalCode) {
            $code = preg_replace('/\D+/', '', $nationalCode) ?: '';
            if ((int) $itemId > 0 && $code !== '') {
                $normalizedCodes[(int) $itemId] = $code;
            }
        }

        if ($normalizedCodes === []) {
            return [];
        }

        $ratesByItem = $this->itemRates(array_keys($normalizedCodes), $companyId);
        $environment = $sandboxMode ? 'sandbox' : 'production';
        $snapshotsByCode = $this->snapshots(
            array_values(array_unique($normalizedCodes)),
            $companyId,
            $municipioIbge,
            $environment,
        );

        $validator = new ItemMunicipalParameterValidator();
        $results = [];

        foreach ($normalizedCodes as $itemId => $code) {
            $snapshot = $snapshotsByCode[$code] ?? null;

            if (!is_array($snapshot)) {
                $results[$itemId] = $validator->validate($ratesByItem[$itemId] ?? null, null);
                continue;
            }

            $results[$itemId] = $validator->validate(
                $ratesByItem[$itemId] ?? null,
                is_array($snapshot['payload'] ?? null) ? $snapshot['payload'] : null,
                [
                    'source' => 'cache',
                    'stale' => false,
                    'fetched_at' => (string) ($snapshot['fetched_at'] ?? ''),
                    'environment' => $environment,
                ],
            );
        }

        return $results;
    }

    /**
     * @param list<int> $itemIds
     * @return array<int,string>
     */
    private function itemRates(array $itemIds, int $companyId): array
    {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $bindings = array_merge([$companyId], $itemIds, [$companyId]);

        try {
            $rows = DB::select(
                'SELECT it.item_id, t.rate, t.type FROM item_taxes it'
                . ' INNER JOIN taxes t ON t.id = it.tax_id'
                . ' WHERE it.company_id = ? AND it.item_id IN (' . $placeholders . ')'
                . ' AND t.company_id = ? AND t.deleted_at IS NULL',
                $bindings,
            );
        } catch (\Throwable) {
            return [];
        }

        $totals = [];
        $seen = [];

        foreach ($rows as $row) {
            $itemId = is_numeric($row->item_id ?? null) ? (int) $row->item_id : 0;
            $type = strtolower(trim((string) ($row->type ?? 'normal')));

            if (
                $itemId <= 0
                || in_array($type, ['fixed', 'withholding'], true)
                || !is_numeric($row->rate ?? null)
            ) {
                continue;
            }

            $totals[$itemId] = ($totals[$itemId] ?? 0.0) + (float) $row->rate;
            $seen[$itemId] = true;
        }

        $rates = [];
        foreach ($totals as $itemId => $rate) {
            if (isset($seen[$itemId]) && $rate > 0) {
                $rates[$itemId] = number_format($rate, 2, '.', '');
            }
        }

        return $rates;
    }

    /**
     * @param list<string> $serviceCodes
     * @return array<string,array{payload:array<string,mixed>,fetched_at:string}>
     */
    private function snapshots(
        array $serviceCodes,
        int $companyId,
        string $municipioIbge,
        string $environment,
    ): array {
        $placeholders = implode(',', array_fill(0, count($serviceCodes), '?'));
        $bindings = array_merge([$companyId, $environment, $municipioIbge], $serviceCodes);

        try {
            $rows = DB::select(
                'SELECT service_code, payload, fetched_at FROM nfse_municipal_parameter_snapshots'
                . ' WHERE company_id = ? AND environment = ? AND municipio_ibge = ?'
                . ' AND service_code IN (' . $placeholders . ')'
                . ' ORDER BY fetched_at DESC, id DESC',
                $bindings,
            );
        } catch (\Throwable) {
            return [];
        }

        $snapshots = [];

        foreach ($rows as $row) {
            $code = trim((string) ($row->service_code ?? ''));

            if ($code === '' || isset($snapshots[$code])) {
                continue;
            }

            $payload = is_string($row->payload ?? null)
                ? json_decode((string) $row->payload, true)
                : null;

            if (!is_array($payload)) {
                continue;
            }

            $snapshots[$code] = [
                'payload' => $payload,
                'fetched_at' => is_scalar($row->fetched_at ?? null) ? (string) $row->fetched_at : '',
            ];
        }

        return $snapshots;
    }
}

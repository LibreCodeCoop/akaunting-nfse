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
        $nationalCode = preg_replace('/\D+/', '', $nationalCode) ?: '';

        if (
            $itemId <= 0
            || $companyId <= 0
            || $nationalCode === ''
            || preg_match('/^\d{7}$/', $municipioIbge) !== 1
        ) {
            return (new ItemMunicipalParameterValidator())->validate(null, null);
        }

        $configuredRate = $this->itemRate($itemId, $companyId);
        $environment = $sandboxMode ? 'sandbox' : 'production';

        try {
            $rows = DB::select(
                'SELECT payload, fetched_at, competence_date'
                . ' FROM nfse_municipal_parameter_snapshots'
                . ' WHERE company_id = ? AND environment = ?'
                . ' AND municipio_ibge = ? AND service_code = ?'
                . ' ORDER BY fetched_at DESC, id DESC LIMIT 1',
                [$companyId, $environment, $municipioIbge, $nationalCode],
            );
        } catch (\Throwable) {
            $rows = [];
        }

        $snapshot = $rows[0] ?? null;

        if (!is_object($snapshot)) {
            return (new ItemMunicipalParameterValidator())->validate($configuredRate, null);
        }

        $payload = is_string($snapshot->payload ?? null)
            ? json_decode((string) $snapshot->payload, true)
            : null;

        return (new ItemMunicipalParameterValidator())->validate(
            $configuredRate,
            is_array($payload) ? $payload : null,
            [
                'source' => 'cache',
                'stale' => false,
                'fetched_at' => is_scalar($snapshot->fetched_at ?? null)
                    ? (string) $snapshot->fetched_at
                    : '',
                'environment' => $environment,
            ],
        );
    }

    private function itemRate(int $itemId, int $companyId): ?string
    {
        try {
            $taxRows = DB::select(
                'SELECT t.rate, t.type FROM item_taxes it'
                . ' INNER JOIN taxes t ON t.id = it.tax_id'
                . ' WHERE it.company_id = ? AND it.item_id = ?'
                . ' AND t.company_id = ? AND t.deleted_at IS NULL',
                [$companyId, $itemId, $companyId],
            );
        } catch (\Throwable) {
            return null;
        }

        $rate = 0.0;
        $hasComparableRate = false;

        foreach ($taxRows as $row) {
            $type = strtolower(trim((string) ($row->type ?? 'normal')));

            if (
                in_array($type, ['fixed', 'withholding'], true)
                || !is_numeric($row->rate ?? null)
            ) {
                continue;
            }

            $rate += (float) $row->rate;
            $hasComparableRate = true;
        }

        return $hasComparableRate && $rate > 0
            ? number_format($rate, 2, '.', '')
            : null;
    }
}

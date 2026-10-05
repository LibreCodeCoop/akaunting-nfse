<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\BulkEmissionRun;
use Modules\Nfse\Models\BulkEmissionUnit;

/**
 * Persists one operator bulk request and its stable fiscal emission units.
 *
 * It does not execute issuance. Queue/HTTP adapters consume the persisted units
 * so progress and per-unit failures remain auditable.
 */
final class BulkEmissionRunRecorder
{
    /**
     * @param list<array{invoice_id:int,emission_group_key:string}> $units
     * @return array{run:BulkEmissionRun,units:list<BulkEmissionUnit>}
     */
    public function create(
        int $companyId,
        ?int $requestedBy,
        array $units,
    ): array {
        if ($companyId <= 0) {
            throw new \InvalidArgumentException('Company is required for a bulk emission run.');
        }

        $normalized = $this->normalizeUnits($units);

        if ($normalized === []) {
            throw new \InvalidArgumentException('At least one fiscal emission unit is required.');
        }

        return DB::transaction(function () use ($companyId, $requestedBy, $normalized): array {
            $selectionHash = $this->selectionHash($companyId, $normalized);
            $existing = BulkEmissionRun::query()
                ->where('company_id', $companyId)
                ->where('selection_hash', $selectionHash)
                ->latest('id')
                ->first();

            if ($existing instanceof BulkEmissionRun && $this->isReusableRunStatus((string) $existing->status)) {
                $units = BulkEmissionUnit::query()
                    ->where('run_id', (int) $existing->id)
                    ->latest('id')
                    ->get()
                    ->all();

                return [
                    'run' => $existing,
                    'units' => array_values(array_filter(
                        $units,
                        static fn (mixed $unit): bool => $unit instanceof BulkEmissionUnit,
                    )),
                ];
            }

            $run = BulkEmissionRun::query()->create([
                'company_id' => $companyId,
                'requested_by' => $requestedBy !== null && $requestedBy > 0 ? $requestedBy : null,
                'status' => 'queued',
                'selection_hash' => $selectionHash,
            ]);

            $persisted = [];

            foreach ($normalized as $unit) {
                $persisted[] = BulkEmissionUnit::query()->create([
                    'run_id' => (int) $run->id,
                    'invoice_id' => $unit['invoice_id'],
                    'emission_group_key' => $unit['emission_group_key'],
                    'status' => 'queued',
                ]);
            }

            return [
                'run' => $run,
                'units' => $persisted,
            ];
        });
    }

    /**
     * @param list<array{invoice_id:int,emission_group_key:string}> $units
     * @return list<array{invoice_id:int,emission_group_key:string}>
     */
    private function normalizeUnits(array $units): array
    {
        $normalized = [];

        foreach ($units as $unit) {
            $invoiceId = (int) ($unit['invoice_id'] ?? 0);
            $groupKey = trim((string) ($unit['emission_group_key'] ?? ''));

            if ($invoiceId <= 0 || $groupKey === '') {
                continue;
            }

            $dedupeKey = $invoiceId . '|' . $groupKey;
            $normalized[$dedupeKey] = [
                'invoice_id' => $invoiceId,
                'emission_group_key' => $groupKey,
            ];
        }

        ksort($normalized);

        return array_values($normalized);
    }

    private function isReusableRunStatus(string $status): bool
    {
        return in_array($status, [
            BulkEmissionStatusPolicy::QUEUED,
            BulkEmissionStatusPolicy::PROCESSING,
            BulkEmissionStatusPolicy::RETRYABLE_READ_ERROR,
            'partial_retryable',
        ], true);
    }

    /**
     * @param list<array{invoice_id:int,emission_group_key:string}> $units
     */
    private function selectionHash(int $companyId, array $units): string
    {
        return hash(
            'sha256',
            $companyId . '|' . json_encode($units, JSON_THROW_ON_ERROR),
        );
    }
}

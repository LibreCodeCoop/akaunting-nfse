<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\BulkEmissionRun;
use Modules\Nfse\Models\BulkEmissionUnit;

final class BulkEmissionUnitState
{
    public function __construct(
        private readonly BulkEmissionStatusPolicy $policy = new BulkEmissionStatusPolicy(),
    ) {
    }

    public function transition(
        int $unitId,
        string $target,
        ?int $receiptId = null,
        ?string $errorType = null,
        ?string $errorMessage = null,
    ): BulkEmissionUnit {
        if ($unitId <= 0) {
            throw new \InvalidArgumentException('Bulk emission unit is required.');
        }

        return DB::transaction(function () use (
            $unitId,
            $target,
            $receiptId,
            $errorType,
            $errorMessage,
        ): BulkEmissionUnit {
            $unit = BulkEmissionUnit::query()
                ->whereKey($unitId)
                ->lockForUpdate()
                ->first();

            if (!$unit instanceof BulkEmissionUnit) {
                throw new \RuntimeException('Bulk emission unit not found.');
            }

            $current = trim((string) $unit->status);

            if ($current !== $target && !$this->policy->canTransition($current, $target)) {
                throw new \LogicException(
                    'Illegal bulk emission transition: ' . $current . ' -> ' . $target,
                );
            }

            $unit->update([
                'status' => $target,
                'receipt_id' => $receiptId,
                'error_type' => $errorType,
                'error_message' => $errorMessage,
            ]);

            $this->refreshRunStatus((int) $unit->run_id);

            return $unit;
        });
    }

    public function refreshRunStatus(int $runId): string
    {
        $run = BulkEmissionRun::query()
            ->whereKey($runId)
            ->lockForUpdate()
            ->first();

        if (!$run instanceof BulkEmissionRun) {
            throw new \RuntimeException('Bulk emission run not found.');
        }

        $statuses = BulkEmissionUnit::query()
            ->where('run_id', $runId)
            ->get()
            ->map(static fn ($unit): string => trim((string) ($unit->status ?? '')))
            ->filter(static fn (string $status): bool => $status !== '')
            ->values()
            ->all();

        $aggregate = $this->policy->aggregate($statuses);
        $run->update(['status' => $aggregate]);

        return $aggregate;
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Jobs\ProcessBulkEmissionUnit;
use Modules\Nfse\Models\BulkEmissionRun;
use Modules\Nfse\Models\BulkEmissionUnit;

/**
 * Persists a planned bulk selection and dispatches each new fiscal unit once.
 *
 * Reused active runs are not blindly redispatched. Their existing queue/state
 * remains authoritative until an explicit recovery action is implemented.
 */
final class BulkEmissionDispatcher
{
    /** @var \Closure(int):void */
    private readonly \Closure $dispatchUnit;

    /**
     * @param (\Closure(int):void)|null $dispatchUnit
     */
    public function __construct(
        private readonly BulkEmissionRunRecorder $recorder = new BulkEmissionRunRecorder(),
        ?\Closure $dispatchUnit = null,
    ) {
        $this->dispatchUnit = $dispatchUnit
            ?? static function (int $unitId): void {
                ProcessBulkEmissionUnit::dispatch($unitId);
            };
    }

    /**
     * @param list<array{invoice_id:int,emission_group_key:string}> $units
     * @return array{
     *   run:BulkEmissionRun,
     *   units:list<BulkEmissionUnit>,
     *   reused:bool,
     *   dispatched:int
     * }
     */
    public function dispatch(
        int $companyId,
        ?int $requestedBy,
        array $units,
    ): array {
        $recorded = $this->recorder->create(
            companyId: $companyId,
            requestedBy: $requestedBy,
            units: $units,
        );

        if ($recorded['reused']) {
            return [
                ...$recorded,
                'dispatched' => 0,
            ];
        }

        $dispatched = 0;

        foreach ($recorded['units'] as $unit) {
            if (
                (int) $unit->id <= 0
                || trim((string) $unit->status) !== BulkEmissionStatusPolicy::QUEUED
            ) {
                continue;
            }

            ($this->dispatchUnit)((int) $unit->id);
            $dispatched++;
        }

        return [
            ...$recorded,
            'dispatched' => $dispatched,
        ];
    }
}

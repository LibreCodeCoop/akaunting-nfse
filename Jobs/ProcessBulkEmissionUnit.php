<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Nfse\Application\BulkEmissionUnitProcessor;

final class ProcessBulkEmissionUnit implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $unitId)
    {
        if ($unitId <= 0) {
            throw new \InvalidArgumentException('Bulk emission unit is required.');
        }
    }

    public function handle(BulkEmissionUnitProcessor $processor): void
    {
        $processor->process($this->unitId);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\View\View;
use Modules\Nfse\Models\BulkEmissionRun;
use Modules\Nfse\Models\BulkEmissionUnit;

final class BulkEmissionController extends Controller
{
    public function index(): View
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        $runs = $companyId > 0
            ? BulkEmissionRun::query()
                ->where('company_id', $companyId)
                ->latest('id')
                ->get()
            : collect();

        $unitsByRun = [];

        foreach ($runs as $run) {
            $runId = (int) ($run->id ?? 0);

            if ($runId <= 0) {
                continue;
            }

            $unitsByRun[$runId] = BulkEmissionUnit::query()
                ->where('run_id', $runId)
                ->latest('id')
                ->get();
        }

        return view('nfse::bulk.index', [
            'runs' => $runs,
            'unitsByRun' => $unitsByRun,
        ]);
    }
}

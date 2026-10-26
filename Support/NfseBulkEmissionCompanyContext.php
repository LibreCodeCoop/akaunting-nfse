<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Validate the run/invoice ownership chain before a queued bulk unit may
 * resolve settings, certificates or fiscal history from a company.
 */
final class NfseBulkEmissionCompanyContext
{
    public function run(int $unitId, Closure $action): mixed
    {
        if ($unitId <= 0) {
            throw new \DomainException('Bulk emission unit has no verified company owner.');
        }

        $companyId = DB::table('nfse_bulk_emission_units as units')
            ->join('nfse_bulk_emission_runs as runs', 'runs.id', '=', 'units.run_id')
            ->join('documents as invoices', 'invoices.id', '=', 'units.invoice_id')
            ->where('units.id', $unitId)
            ->where('invoices.type', 'invoice')
            ->whereColumn('runs.company_id', 'invoices.company_id')
            ->value('runs.company_id');

        if (!is_numeric($companyId) || (int) $companyId <= 0) {
            throw new \DomainException('Bulk emission unit has no verified company owner.');
        }

        return (new NfseCompanyJobContext())->run((int) $companyId, $action);
    }
}

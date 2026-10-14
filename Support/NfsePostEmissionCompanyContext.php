<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use App\Models\Common\Company;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Restore the invoice's persisted company context around each queued task.
 * A worker's previous tenant must never supply WebDAV or email settings.
 */
final class NfsePostEmissionCompanyContext
{
    public function run(int $invoiceId, int $receiptId, Closure $action): mixed
    {
        $companyId = DB::table('nfse_receipts as receipts')
            ->join('documents as invoices', 'invoices.id', '=', 'receipts.invoice_id')
            ->where('receipts.id', $receiptId)
            ->where('invoices.id', $invoiceId)
            ->value('invoices.company_id');

        if (!is_numeric($companyId) || (int) $companyId <= 0) {
            throw new \DomainException('Post-emission operation has no verified company owner.');
        }

        $original = Company::getCurrent();
        if ((int) ($original?->id ?? 0) === (int) $companyId) {
            return $action();
        }

        $company = Company::query()->findOrFail((int) $companyId);
        $company->makeCurrent();

        try {
            return $action();
        } finally {
            if ($original instanceof Company) {
                $original->makeCurrent();
            } else {
                Company::forgetCurrent();
            }
        }
    }
}

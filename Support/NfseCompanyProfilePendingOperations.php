<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Support\Facades\DB;

/**
 * A pending fiscal identity belongs to its invoice/issuer, not to a certificate.
 * Certificate rotation and profile deletion must never discard its recovery path.
 */
final class NfseCompanyProfilePendingOperations
{
    public function assertNone(int $companyId): void
    {
        if (DB::table('nfse_emission_attempts')
            ->where('company_id', $companyId)
            ->whereIn('status', ['pending', 'ambiguous'])
            ->exists()) {
            $this->reject();
        }

        if (DB::table('nfse_bulk_emission_runs')
            ->where('company_id', $companyId)
            ->whereNotIn('status', ['issued', 'blocked', 'rejected', 'completed', 'partial_rejected', 'partial_blocked'])
            ->exists()) {
            $this->reject();
        }

        if (DB::table('nfse_receipts')
            ->join('documents', 'documents.id', '=', 'nfse_receipts.invoice_id')
            ->where('documents.company_id', $companyId)
            ->whereIn('nfse_receipts.status', ['pending', 'processing', 'unknown'])
            ->exists()) {
            $this->reject();
        }

        // A reserved DPS without a resolved attempt must not lose its issuer context.
        if (DB::table('nfse_dps_reservations as r')
            ->where('r.company_id', $companyId)
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('nfse_emission_attempts as a')
                    ->whereColumn('a.dps_reservation_id', 'r.id')
                    ->whereIn('a.status', ['authorized', 'rejected', 'technical_failure']);
            })->exists()) {
            $this->reject();
        }
    }

    private function reject(): never
    {
        throw new \DomainException('Pending fiscal operations must be reconciled before changing the certificate or deleting the profile.');
    }
}

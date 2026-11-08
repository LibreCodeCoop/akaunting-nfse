<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Append-only operational decisions. Neither legal non-applicability nor
 * commercial invoice dates are inferred from categories or missing receipts.
 */
final class InvoiceFiscalDecision
{
    public const DECISIONS = ['do_not_issue', 'not_applicable', 'reversed'];

    public const REASONS = [
        'operational_review',
        'external_issuance',
        'non_service_revenue',
        'tax_recovery',
        'financial_adjustment',
        'other',
        'reconsidered',
    ];

    public function history(int $companyId, int $invoiceId): array
    {
        return DB::table('nfse_invoice_decisions')
            ->where('company_id', $companyId)->where('invoice_id', $invoiceId)
            ->orderByDesc('id')->get()->map(static fn ($row): array => (array) $row)->all();
    }

    public function current(int $companyId, int $invoiceId): ?array
    {
        $row = DB::table('nfse_invoice_decisions')
            ->where('company_id', $companyId)->where('invoice_id', $invoiceId)
            ->orderByDesc('id')->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * The document lock serializes concurrent decisions for one company/invoice.
     * This operation never touches documents, receipts, payment or DPS records.
     */
    public function record(
        Document $invoice,
        int $companyId,
        int $actorId,
        string $decision,
        string $reason,
        string $justification,
        ?string $evidenceReference,
    ): array {
        if ($companyId <= 0 || $actorId <= 0 || (int) $invoice->company_id !== $companyId
            || (string) $invoice->type !== 'invoice') {
            throw new \InvalidArgumentException('A valid company-owned invoice and operator are required.');
        }

        if (!in_array($decision, self::DECISIONS, true)
            || !in_array($reason, self::REASONS, true)
            || mb_strlen(trim($justification)) < 12
            || ($decision === 'not_applicable' && mb_strlen(trim((string) $evidenceReference)) < 5)
            || ($decision === 'reversed' && $reason !== 'reconsidered')) {
            throw ValidationException::withMessages(['decision' => 'Invalid fiscal decision, justification or supporting evidence.']);
        }

        return DB::transaction(function () use (
            $invoice, $companyId, $actorId, $decision, $reason, $justification, $evidenceReference,
        ): array {
            $document = DB::table('documents')->where('id', $invoice->id)
                ->where('company_id', $companyId)->where('type', 'invoice')
                ->lockForUpdate()->first();
            if ($document === null) {
                throw new \InvalidArgumentException('Invoice not found in the active company.');
            }

            $current = $this->current($companyId, (int) $invoice->id);
            if ($decision === 'reversed' && ($current === null || $current['decision'] === 'reversed')) {
                throw ValidationException::withMessages(['decision' => 'No active fiscal decision to reverse.']);
            }

            // A locally authorized receipt must be reconciled explicitly before
            // any exemption or operational suppression can be recorded.
            if ($decision !== 'reversed' && DB::table('nfse_receipts')
                ->where('invoice_id', $invoice->id)
                ->where('status', 'emitted')->exists()) {
                throw ValidationException::withMessages([
                    'decision' => 'An authorized NFS-e is linked. Review fiscal evidence and reconcile the receipt before classifying this invoice.',
                ]);
            }

            $id = DB::table('nfse_invoice_decisions')->insertGetId([
                'company_id' => $companyId,
                'invoice_id' => $invoice->id,
                'decision' => $decision,
                'reason' => $reason,
                'justification' => trim($justification),
                'evidence_reference' => trim((string) $evidenceReference) ?: null,
                'actor_id' => $actorId,
                'recorded_at' => now(),
            ]);

            return (array) DB::table('nfse_invoice_decisions')->where('id', $id)->first();
        });
    }
}

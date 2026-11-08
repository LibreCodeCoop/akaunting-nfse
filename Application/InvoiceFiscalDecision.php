<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Append-only operator decisions. Category settings supply operational
 * defaults for future invoices, never proof of legal non-applicability.
 */
final class InvoiceFiscalDecision
{
    public const DECISIONS = ['do_not_issue', 'not_applicable', 'issue', 'reversed'];

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
     * Effective operational choice for issuance guards and presentation.
     * Explicit operator actions override a category default. Reversing an
     * operator action restores the category default in force when the invoice
     * was created. Read operations never alter financial/fiscal records.
     *
     * @return array<string,mixed>|null
     */
    public function effective(Document $invoice): ?array
    {
        $explicit = $this->current((int) $invoice->company_id, (int) $invoice->id);
        if ($explicit !== null && $explicit['decision'] !== 'reversed') {
            $explicit['source'] = 'operator';
            return $explicit;
        }

        $policy = (new CategoryIssuancePolicy())->forInvoice($invoice);
        if (($policy['mode'] ?? null) === 'do_not_issue') {
            return [
                'company_id' => (int) $invoice->company_id,
                'invoice_id' => (int) $invoice->id,
                'decision' => 'do_not_issue',
                'source' => 'category',
                'category_id' => (int) $policy['category_id'],
                'category_policy_id' => (int) $policy['id'],
                'reason' => 'category_policy',
                'justification' => $policy['justification'],
                'actor_id' => (int) $policy['actor_id'],
                'recorded_at' => $policy['recorded_at'],
            ];
        }

        return null;
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

            // An invoice-level "issue" exception may override an older
            // operational default, never the current category allowlist.
            if ($decision === 'issue' && !(new CategoryIssuancePolicy())->canIssue($invoice)) {
                throw ValidationException::withMessages([
                    'decision' => 'The invoice category is not authorized for NFS-e issuance.',
                ]);
            }

            $current = $this->current($companyId, (int) $invoice->id);
            if ($decision === 'reversed' && ($current === null || $current['decision'] === 'reversed')) {
                throw ValidationException::withMessages(['decision' => 'No active fiscal decision to reverse.']);
            }

            // A locally authorized receipt must be reconciled explicitly before
            // any exemption or operational suppression can be recorded.
            $receiptStatuses = DB::table('nfse_receipts')
                ->where('invoice_id', $invoice->id)->pluck('status')->all();
            if (!(new InvoiceFiscalDecisionPolicy())->mayClassify($decision, $receiptStatuses)) {
                throw ValidationException::withMessages([
                    'decision' => 'An authorized or unresolved NFS-e is linked. Reconcile its fiscal state before classifying this invoice.',
                ]);
            }

            // Pending or ambiguous DPS attempts may already have been posted
            // to SEFIN without a local authorized receipt. Reconcile them
            // before accepting an invoice-wide decision not to issue.
            if ($decision !== 'reversed' && DB::table('nfse_emission_attempts')
                ->where('company_id', $companyId)->where('invoice_id', $invoice->id)
                ->whereIn('status', ['pending', 'ambiguous'])->exists()) {
                throw ValidationException::withMessages([
                    'decision' => 'An unresolved DPS attempt is linked. Reconcile the attempt before recording a non-issuance decision.',
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

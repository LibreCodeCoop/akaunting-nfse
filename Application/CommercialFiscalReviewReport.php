<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Models\NfseEmissionAttempt;
use Modules\Nfse\Support\InvoiceFiscalContextResolver;

/**
 * Candidate review by commercial invoice month, NEVER a declaration of tax due dates.
 */
final class CommercialFiscalReviewReport
{
    /** @return array{rows:list<array<string,mixed>>,page:\Illuminate\Contracts\Pagination\LengthAwarePaginator} */
    public function forInvoiceMonth(int $companyId, string $month): array
    {
        if ($companyId <= 0 || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new \InvalidArgumentException('Fiscal review requires an active company and a valid month.');
        }

        $start = $month . '-01';
        $end = (new \DateTimeImmutable($start))->modify('first day of next month')->format('Y-m-d');
        $page = Document::query()->invoice()->where('company_id', $companyId)
            ->where('issued_at', '>=', $start)->where('issued_at', '<', $end)
            ->with('items')->orderBy('id')->paginate(30, ['*'], 'commercial_page');

        $ids = $page->getCollection()->pluck('id')->all();
        $receiptsByInvoice = $ids === [] ? collect() : NfseReceipt::query()
            ->whereIn('invoice_id', $ids)
            ->whereHas('invoice', static function ($query) use ($companyId): void {
                $query->where('company_id', $companyId);
            })->get()->groupBy('invoice_id');

        // An unresolved first POST is not the same as a missing local receipt.
        // Its existence requires read-only reconciliation, even if the category
        // was subsequently disabled or an operator recorded a fiscal decision.
        $unresolvedAttemptInvoiceIds = $ids === [] ? [] : array_fill_keys(
            DB::table('nfse_emission_attempts')
                ->where('company_id', $companyId)->whereIn('invoice_id', $ids)
                ->whereIn('status', [EmissionAttemptJournal::PENDING, EmissionAttemptJournal::AMBIGUOUS])
                ->distinct()->pluck('invoice_id')->all(),
            true,
        );

        $decisions = [];
        if ($ids !== []) {
            $decisionRows = DB::table('nfse_invoice_decisions')
                ->where('company_id', $companyId)->whereIn('invoice_id', $ids)
                ->orderByDesc('id')->get();
            foreach ($decisionRows as $decisionRow) {
                $id = (int) $decisionRow->invoice_id;
                if (!isset($decisions[$id])) {
                    $decisions[$id] = (array) $decisionRow;
                }
            }
        }

        $rows = [];
        $categoryPolicies = new CategoryIssuancePolicy();
        foreach ($page->getCollection() as $invoice) {
            $receipts = $receiptsByInvoice->get($invoice->id, collect());
            $manual = $decisions[(int) $invoice->id] ?? null;
            $effective = $manual;
            if ($manual === null || ($manual['decision'] ?? '') === 'reversed') {
                $category = $categoryPolicies->forInvoice($invoice);
                $effective = ($category['mode'] ?? null) === 'do_not_issue'
                    ? ['decision' => 'do_not_issue', 'source' => 'category']
                    : null;
            }
            if (($manual['decision'] ?? '') === 'issue') {
                $effective = null;
            }
            $inIssuanceScope = $categoryPolicies->canIssue($invoice);
            $requiresReconciliation = isset($unresolvedAttemptInvoiceIds[(int) $invoice->id]);
            $date = $invoice->issued_at;
            $rows[] = [
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => (string) ($invoice->document_number ?? ''),
                'issued_at' => $date instanceof \DateTimeInterface
                    ? $date->format('Y-m-d') : substr((string) $date, 0, 10),
                'financial_status' => (string) ($invoice->status ?? ''),
                'local_receipts' => $receipts->count(),
                'status' => $requiresReconciliation
                    ? 'reconciliation_required'
                    : ($receipts->isEmpty() && !$inIssuanceScope && $effective === null
                        ? 'outside_issuance_scope'
                        : $this->status($invoice, $receipts, $effective)),
                'fiscal_decision' => $effective['decision'] ?? null,
                'decision_source' => $receipts->isEmpty() && !$inIssuanceScope && $effective === null
                    ? 'category_authorization'
                    : ($effective['source'] ?? ($manual === null ? null : 'operator')),
            ];
        }

        return ['rows' => $rows, 'page' => $page];
    }

    /** @param Collection<int,NfseReceipt> $receipts */
    private function status(Document $invoice, Collection $receipts, ?array $decision): string
    {
        if ($receipts->isEmpty()) {
            return in_array($decision['decision'] ?? '', ['do_not_issue', 'not_applicable'], true)
                ? (string) $decision['decision'] : 'not_linked';
        }
        if ($receipts->contains(static fn (NfseReceipt $receipt): bool =>
            in_array($receipt->status, ['pending', 'processing'], true))) {
            return 'reconciliation_required';
        }
        if (!$receipts->contains(static fn (NfseReceipt $receipt): bool => $receipt->status === 'emitted')) {
            return 'review_required';
        }

        try {
            $groups = (new InvoiceFiscalContextResolver())->groups($invoice);
        } catch (\InvalidArgumentException) {
            return 'review_required';
        }
        if ($groups === []) {
            return 'review_required';
        }
        if (count($groups) > 1) {
            $byId = $receipts->keyBy('id');
            foreach ((new FiscalGroupReceiptState())->annotate((int) $invoice->id, $groups) as $group) {
                $receipt = $byId->get($group['receipt_id'] ?? 0);
                if (!$receipt instanceof NfseReceipt || $receipt->status !== 'emitted') {
                    return 'partially_linked';
                }
            }
        }

        return 'authorized_locally'; // Not proof of legal compliance.
    }
}

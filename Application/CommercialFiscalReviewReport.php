<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document;
use Illuminate\Support\Collection;
use Modules\Nfse\Models\NfseReceipt;
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

        $rows = [];
        foreach ($page->getCollection() as $invoice) {
            $receipts = $receiptsByInvoice->get($invoice->id, collect());
            $date = $invoice->issued_at;
            $rows[] = [
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => (string) ($invoice->document_number ?? ''),
                'issued_at' => $date instanceof \DateTimeInterface
                    ? $date->format('Y-m-d') : substr((string) $date, 0, 10),
                'financial_status' => (string) ($invoice->status ?? ''),
                'local_receipts' => $receipts->count(),
                'status' => $this->status($invoice, $receipts),
            ];
        }

        return ['rows' => $rows, 'page' => $page];
    }

    /** @param Collection<int,NfseReceipt> $receipts */
    private function status(Document $invoice, Collection $receipts): string
    {
        if ($receipts->isEmpty()) {
            return 'not_linked';
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

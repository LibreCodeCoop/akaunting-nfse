<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API-first dashboard adapter. Reuse the existing company-scoped filtering,
 * sorting, totals, and receipt query instead of duplicating fiscal SQL.
 */
class DashboardJsonController extends InvoiceController
{
    private ?Request $dashboardRequest = null;

    public function dashboard(?Request $request = null): \Illuminate\View\View
    {
        $filters = $this->dashboardFilters($this->currentRequest($request));

        // Never embed fiscal rows or financial totals in the HTML response.
        return view('nfse::dashboard.json', compact('filters'));
    }

    public function dashboardData(?Request $request = null): JsonResponse
    {
        $this->dashboardRequest = $this->currentRequest($request);
        $receipts = [];
        foreach ($this->dashboardRecentReceipts() as $receipt) {
            $invoiceId = (int) $receipt->invoice_id;
            $status = (string) ($receipt->status ?: 'unknown');
            $receipts[] = [
                'invoice_id' => $invoiceId,
                'nfse_number' => (string) ($receipt->nfse_number ?: ''),
                'customer_name' => (string) ($receipt->customer_name ?: ''),
                'issue_date' => (string) ($receipt->data_emissao ?: ''),
                'amount_label' => $this->receiptAmountLabel($receipt),
                'status' => $status,
                'status_label' => (string) trans('nfse::general.native_invoice.status_' . $status),
                'invoice_url' => (string) parse_url(route('invoices.show', $invoiceId), PHP_URL_PATH),
            ];
        }

        return response()->json(['data' => [
            'stats' => $this->dashboardStats(),
            'recent_receipts' => $receipts,
        ]]);
    }

    protected function currentRequest(?Request $request = null): ?Request
    {
        return parent::currentRequest($request ?? $this->dashboardRequest);
    }

    protected function receiptAmountLabel(object $receipt): string
    {
        return (string) money(
            $receipt->amount,
            $receipt->currency_code ?: default_currency(),
            true,
        );
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Nfse\Application\FiscalLedgerCatalog;
use Modules\Nfse\Models\NfseReceipt;

/** Read-only native and session JSON representation of fiscal receipts. */
final class FiscalLedgerApiController extends Controller
{
    public function index(Request $request, FiscalLedgerCatalog $catalog): JsonResponse
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        $page = $catalog->page($companyId, $request);

        return response()->json([
            'data' => collect($page->items())->map(static fn (NfseReceipt $row): array => [
                'id' => (int) $row->id,
                'invoice_id' => (int) $row->invoice_id,
                'invoice_number' => $row->invoice?->document_number,
                'nfse_number' => $row->nfse_number,
                'customer' => $row->invoice?->contact?->name,
                'issue_date' => $row->data_emissao?->format('Y-m-d'),
                'status' => $row->status,
                'replaces_receipt_id' => $row->replaces_receipt_id,
                'can_download' => $row->status === 'emitted',
            ])->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Native artifact transport uses the existing receipt-scoped controller. */
    public function artifact(
        int $receipt,
        string $artifact,
        InvoiceController $invoices,
    ): Response|JsonResponse {
        if (!in_array($artifact, ['xml', 'danfse'], true)) {
            return response()->json(['message' => 'Unsupported fiscal artifact.'], 422);
        }

        $file = $invoices->downloadLedgerArtifact($receipt, $artifact);
        if ($file instanceof RedirectResponse) {
            return response()->json(['message' => 'Fiscal artifact is not available.'], 409);
        }

        return $file;
    }
}

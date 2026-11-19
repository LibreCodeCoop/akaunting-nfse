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
                'invoice_id' => $row->invoice_id === null ? null : (int) $row->invoice_id,
                'invoice_number' => $row->invoice?->document_number,
                'nfse_number' => $row->nfse_number,
                'customer' => $row->invoice?->contact?->name
                    ?? (((int) $row->recipientContact?->company_id === $companyId
                        && $row->recipientContact?->type === 'customer'
                        && $row->recipientContact?->deleted_at === null)
                        ? $row->recipientContact->name : null)
                    ?? ($row->archive_evidence['recipient_name'] ?? null)
                    ?? ($row->archive_evidence['recipient_tax'] ?? null),
                'issue_date' => $row->data_emissao?->format('Y-m-d'),
                'status' => $row->status,
                'replaces_receipt_id' => $row->replaces_receipt_id,
                'historical_unlinked' => $row->invoice_id === null,
                'archive_page_number' => $row->archive_page_number,
                'can_download' => $row->status === 'emitted',
                'can_download_xml' => $row->invoice_id !== null && $row->status === 'emitted',
                'can_download_danfse' => $row->invoice_id === null
                    ? (trim((string) $row->danfse_webdav_path) !== ''
                        && (int) $row->archive_page_number > 0
                        && preg_match('/^[a-f0-9]{64}$/D', (string) $row->archive_pdf_sha256) === 1)
                    : (trim((string) $row->danfse_webdav_path) !== '' || $row->status === 'emitted'),
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

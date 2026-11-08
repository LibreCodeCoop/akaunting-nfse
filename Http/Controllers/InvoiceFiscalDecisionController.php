<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Models\Document\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Nfse\Application\InvoiceFiscalDecision;

final class InvoiceFiscalDecisionController extends Controller
{
    public function show(Document $invoice, InvoiceFiscalDecision $decisions): JsonResponse
    {
        $companyId = $this->companyInvoice($invoice);

        return response()->json(['data' => [
            'current' => $decisions->current($companyId, (int) $invoice->id),
            'history' => $decisions->history($companyId, (int) $invoice->id),
        ]]);
    }

    public function store(Request $request, Document $invoice, InvoiceFiscalDecision $decisions): JsonResponse|RedirectResponse
    {
        $companyId = $this->companyInvoice($invoice);
        $actorId = (int) auth()->id();
        abort_if($actorId <= 0, 403, 'An authenticated operator is required.');

        $validated = $request->validate([
            'decision' => 'required|in:do_not_issue,not_applicable,reversed',
            'reason' => 'required|in:operational_review,external_issuance,non_service_revenue,tax_recovery,financial_adjustment,other,reconsidered',
            'justification' => 'required|string|min:12|max:4000',
            'evidence_reference' => 'nullable|string|max:255',
        ]);

        $record = $decisions->record(
            $invoice,
            $companyId,
            $actorId,
            $validated['decision'],
            $validated['reason'],
            $validated['justification'],
            $validated['evidence_reference'] ?? null,
        );

        if ($request->expectsJson()) {
            return response()->json(['data' => $record], 201);
        }

        return redirect()->route('invoices.show', $invoice->id)
            ->with('success', trans('nfse::general.invoice_decision.saved'));
    }

    private function companyInvoice(Document $invoice): int
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        abort_unless($companyId > 0 && (int) $invoice->company_id === $companyId
            && (string) $invoice->type === 'invoice', 404);

        return $companyId;
    }
}

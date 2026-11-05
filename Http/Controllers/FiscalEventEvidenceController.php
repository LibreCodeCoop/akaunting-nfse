<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Models\Document\Document as Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Nfse\Support\InvoiceFiscalEventEvidence;
use Modules\Nfse\Support\InvoiceFiscalEventOverview;

/**
 * A fiscal event is attested independently of the commercial invoice date.
 * Read-only previews are explicitly not a final DPS compliance assessment.
 */
final class FiscalEventEvidenceController extends Controller
{
    public function index(Invoice $invoice, InvoiceFiscalEventOverview $overview): JsonResponse
    {
        $this->assertCompany($invoice);

        return response()->json([
            'data' => $overview->forInvoice($invoice),
            'scope' => 'rtc_calendar_preview',
            'final_dps_checked' => false,
        ]);
    }

    public function store(
        Request $request,
        Invoice $invoice,
        InvoiceFiscalEventEvidence $evidence,
    ): JsonResponse|RedirectResponse {
        $this->assertCompany($invoice);
        $actorId = (int) auth()->id();
        abort_if($actorId <= 0, 403, 'An authenticated operator is required.');

        $values = $request->validate([
            'fiscal_group_key' => ['required', 'string', 'max:191'],
            'taxable_event_date' => ['required', 'date_format:Y-m-d'],
            'source_reference' => ['required', 'string', 'min:5', 'max:255'],
            'acknowledge' => ['required', 'accepted'],
        ]);

        try {
            $evidence->attest(
                $invoice,
                (string) $values['fiscal_group_key'],
                (string) $values['taxable_event_date'],
                (string) $values['source_reference'],
                $actorId,
            );
        } catch (\DomainException|\InvalidArgumentException|\LogicException $error) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $error->getMessage()], 422);
            }

            return redirect()->route('invoices.show', $invoice)
                ->with('error', $error->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'attested'], 201);
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', trans('nfse::general.fiscal_event.saved'));
    }

    private function assertCompany(Invoice $invoice): void
    {
        $active = function_exists('company_id') ? (int) company_id() : 0;
        abort_unless(
            $active > 0
                && (int) ($invoice->company_id ?? 0) === $active
                && (string) ($invoice->type ?? '') === 'invoice',
            404,
        );
    }
}

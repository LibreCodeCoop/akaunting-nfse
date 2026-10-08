<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Models\Document\Document as Invoice;
use Illuminate\Http\JsonResponse;
use Modules\Nfse\Application\EmissionAttemptJournal;
use Modules\Nfse\Models\NfseEmissionAttempt;

/**
 * Bounded read-only diagnostics; no fiscal mutation or raw upstream payload.
 */
final class EmissionAttemptController extends Controller
{
    public function index(Invoice $invoice, EmissionAttemptJournal $journal): JsonResponse
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;

        if ($companyId <= 0 || (int) $invoice->company_id !== $companyId ||
            (string) $invoice->type !== Invoice::INVOICE_TYPE) {
            abort(404);
        }

        $records = $journal->forInvoice($companyId, (int) $invoice->id);

        return response()->json([
            'data' => $records->map(static fn (NfseEmissionAttempt $attempt): array => [
                'id' => (int) $attempt->id,
                'dps_identifier' => (string) $attempt->dps_identifier,
                'group_key' => $attempt->emission_group_key,
                'attempt_number' => (int) $attempt->attempt_number,
                'environment' => (int) $attempt->environment,
                'municipio_ibge' => $attempt->municipio_ibge,
                'codigo_servico' => $attempt->codigo_servico,
                'competence_date' => $attempt->competence_date?->format('Y-m-d'),
                'origin' => $attempt->origin,
                'status' => $attempt->status,
                'official_code' => $attempt->official_code,
                'official_message' => $attempt->official_message,
                'http_status' => $attempt->http_status,
                'failure_class' => $attempt->failure_class,
                'receipt_id' => $attempt->receipt_id,
                'started_at' => $attempt->started_at?->toIso8601String(),
                'resolved_at' => $attempt->resolved_at?->toIso8601String(),
                'reconciled_at' => $attempt->reconciled_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}

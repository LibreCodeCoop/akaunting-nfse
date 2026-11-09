<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Models\Document\Document as Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Nfse\Support\CustomerEmailPreferences;

final class CustomerEmailPreferencesController extends Controller
{
    public function store(Invoice $invoice, Request $request): JsonResponse
    {
        $companyId = (int) company_id();
        abort_unless((int) $invoice->company_id === $companyId
            && (string) $invoice->type === Invoice::INVOICE_TYPE
            && (int) $invoice->contact_id > 0
            && (int) $invoice->contact?->company_id === $companyId, 404);

        $values = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:12000',
        ]);

        $saved = (new CustomerEmailPreferences())->save(
            $companyId,
            (int) $invoice->contact_id,
            $companyId,
            $values['subject'],
            $values['body'],
        );

        return response()->json(['success' => true, 'scope' => 'customer', 'subject' => $saved['subject']]);
    }
}

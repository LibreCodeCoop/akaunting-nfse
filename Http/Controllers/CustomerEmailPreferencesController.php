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
    public function show(Invoice $invoice): JsonResponse
    {
        $companyId = (int) company_id();
        $contactId = $this->ownedContactId($invoice, $companyId);
        $saved = (new CustomerEmailPreferences())->read($companyId, $contactId, $companyId);

        return response()->json(['data' => $saved === null
            ? ['configured' => false, 'subject' => '', 'body' => '']
            : ['configured' => true, 'subject' => $saved['subject'], 'body' => $saved['body']]]);
    }

    public function store(Invoice $invoice, Request $request): JsonResponse
    {
        $companyId = (int) company_id();
        $contactId = $this->ownedContactId($invoice, $companyId);

        $values = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:12000',
        ]);

        $saved = (new CustomerEmailPreferences())->save(
            $companyId,
            $contactId,
            $companyId,
            $values['subject'],
            $values['body'],
        );

        return response()->json(['success' => true, 'scope' => 'customer', 'subject' => $saved['subject']]);
    }

    private function ownedContactId(Invoice $invoice, int $companyId): int
    {
        abort_unless((int) $invoice->company_id === $companyId
            && (string) $invoice->type === Invoice::INVOICE_TYPE
            && (int) $invoice->contact_id > 0
            && (int) $invoice->contact?->company_id === $companyId, 404);

        return (int) $invoice->contact_id;
    }
}

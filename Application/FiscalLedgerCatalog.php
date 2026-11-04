<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document as Invoice;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Nfse\Models\NfseReceipt;

/**
 * Read-only fiscal ledger query. The invoice is the native accounting record,
 * while every authorized fiscal receipt preserves its own immutable identity.
 */
final class FiscalLedgerCatalog
{
    /** @return LengthAwarePaginator<NfseReceipt> */
    public function page(int $companyId, Request $request): LengthAwarePaginator
    {
        abort_if($companyId <= 0, 403, 'An active company is required for the fiscal ledger.');

        $params = $request->validate([
            'status' => 'nullable|in:all,emitted,processing,cancelled,substituted',
            'search' => 'nullable|string|max:150',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'limit' => 'nullable|integer|between:1,100',
            'page' => 'nullable|integer|min:1|max:100000',
        ]);

        $status = (string) ($params['status'] ?? 'all');
        $search = trim((string) ($params['search'] ?? ''));
        $query = NfseReceipt::query()
            ->with('invoice.contact')
            ->whereHas('invoice', static fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('type', Invoice::INVOICE_TYPE));

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $query->where(static fn ($query) => $query
                ->where('nfse_number', 'like', '%' . $search . '%')
                ->orWhere('chave_acesso', 'like', '%' . $search . '%')
                ->orWhereHas('invoice.contact', static fn ($contact) => $contact
                    ->where('name', 'like', '%' . $search . '%')));
        }
        if (!empty($params['from'])) {
            $query->whereDate('data_emissao', '>=', $params['from']);
        }
        if (!empty($params['to'])) {
            $query->whereDate('data_emissao', '<=', $params['to']);
        }

        return $query->orderByDesc('id')->paginate((int) ($params['limit'] ?? 25));
    }
}

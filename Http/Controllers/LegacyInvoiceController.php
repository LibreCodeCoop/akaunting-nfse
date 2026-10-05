<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Models\Document\Document as Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Compatibility redirects for the former parallel NFS-e invoice UI.
 *
 * Fiscal mutation routes stay on InvoiceController; these GET routes only
 * preserve old deep links while making Akaunting's native invoice screens
 * the operational UI.
 */
final class LegacyInvoiceController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('invoices.index', array_filter([
            'search' => $this->normalizedSearch($request->query('search', $request->query('q'))),
        ], static fn ($value): bool => $value !== null && $value !== ''));
    }

    public function pending(Request $request): RedirectResponse
    {
        return $this->index($request);
    }

    public function show(Invoice $invoice): RedirectResponse
    {
        return redirect()->route('invoices.show', $invoice);
    }

    private function normalizedSearch(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}

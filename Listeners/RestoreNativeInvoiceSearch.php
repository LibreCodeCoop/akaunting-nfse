<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Events\Common\SearchStringApplied;

/** Restore the visible search chips after the native SQL parser has run. */
final class RestoreNativeInvoiceSearch
{
    public function handle(SearchStringApplied $event): void
    {
        if (!request()->routeIs('invoices.index')) {
            return;
        }

        $original = request()->attributes->get(ApplyNativeInvoiceFiscalFilter::ORIGINAL_SEARCH);
        if (!is_string($original)) {
            return;
        }

        request()->merge(['search' => $original]);
        request()->attributes->remove(ApplyNativeInvoiceFiscalFilter::ORIGINAL_SEARCH);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Events\Common\SearchStringApplying;
use App\Models\Document\Document;

/**
 * Apply fiscal filtering to the native invoice query before Akaunting paginates.
 * Multiple fiscal receipts are resolved by the most recent persisted receipt.
 */
final class ApplyNativeInvoiceFiscalFilter
{
    public function handle(SearchStringApplying $event): void
    {
        if (!request()->routeIs('invoices.index')) {
            return;
        }

        $status = request()->query('nfse_status');
        if (!is_string($status) || !in_array($status, ['emitted', 'processing', 'cancelled', 'substituted', 'absent', 'unknown'], true)) {
            return;
        }

        // Akaunting inserts the default unpaid-tab search before reaching this event.
        // A fiscal-only filter must span all commercial invoice statuses.
        // Preserve searches and tabs explicitly chosen by the operator.
        if (request()->input('programmatic') === '1') {
            request()->merge(['search' => '', 'list_records' => 'all']);
        }

        $query = $event->query;
        if (!$query->getModel() instanceof Document) {
            return;
        }

        $table = $query->getModel()->getTable();
        if ($status === 'absent') {
            $query->whereRaw(
                'NOT EXISTS (SELECT 1 FROM nfse_receipts r WHERE r.invoice_id = ' . $table . '.id)',
            );

            return;
        }

        if ($status === 'unknown') {
            $query->whereRaw(
                '(SELECT r.status FROM nfse_receipts r WHERE r.invoice_id = ' . $table
                . '.id ORDER BY r.id DESC LIMIT 1) NOT IN (?, ?, ?, ?, ?)',
                ['pending', 'processing', 'emitted', 'cancelled', 'substituted'],
            );

            return;
        }

        $query->whereRaw(
            '(SELECT r.status FROM nfse_receipts r WHERE r.invoice_id = ' . $table
            . '.id ORDER BY r.id DESC LIMIT 1) = ?',
            [$status],
        );
    }
}

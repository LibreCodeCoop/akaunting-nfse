<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Traits\Modules;
use Modules\Nfse\Models\NfseReceipt;

final class OverrideInvoiceEmailRoute
{
    use Modules;

    public function handle(object $event): void
    {
        if (!$this->moduleIsEnabled('nfse')) {
            return;
        }

        $route = $event->route ?? null;

        if (!is_object($route) || !method_exists($route, 'getName')) {
            return;
        }

        if ($route->getName() !== 'invoices.show') {
            return;
        }

        if (!method_exists($route, 'parameter')) {
            return;
        }

        $invoice = $route->parameter('invoice');

        $this->overrideForInvoice($invoice);
    }

    public function overrideForInvoice(mixed $invoice): void
    {
        if (!$this->shouldManageInvoiceSendFlow($invoice)) {
            return;
        }

        config([
            'type.document.invoice.route.emails.create' => 'nfse.modals.invoices.emails.create',
            'type.document.invoice.translation.send_mail' => $this->sendButtonTranslationKey($invoice),
        ]);
    }

    protected function shouldManageInvoiceSendFlow(mixed $invoice): bool
    {
        if (!is_object($invoice)) {
            return false;
        }

        if (($invoice->type ?? '') !== 'invoice') {
            return false;
        }

        $receiptStatus = $this->latestReceiptStatus($invoice);

        if ($receiptStatus !== null) {
            return true;
        }

        if (!$this->invoiceHasLineItems($invoice)) {
            return false;
        }

        return true;
    }

    protected function invoiceHasLineItems(object $invoice): bool
    {
        if (method_exists($invoice, 'loadMissing')) {
            try {
                $invoice->loadMissing(['items']);
            } catch (\Throwable) {
                // Ignore relation loading failures in degraded contexts.
            }
        }

        $items = $invoice->items ?? null;

        if (is_array($items)) {
            return count($items) > 0;
        }

        if (is_object($items) && method_exists($items, 'count')) {
            return (int) $items->count() > 0;
        }

        if (method_exists($invoice, 'items')) {
            try {
                $relation = $invoice->items();

                if (is_object($relation) && method_exists($relation, 'exists')) {
                    return (bool) $relation->exists();
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    protected function sendButtonTranslationKey(object $invoice): string
    {
        $receiptStatus = $this->latestReceiptStatus($invoice);

        if ($receiptStatus === 'emitted') {
            return 'nfse::general.invoices.cancel';
        }

        if ($receiptStatus === 'cancelled') {
            return 'nfse::general.invoices.reemit';
        }

        return 'nfse::general.invoices.emit_now';
    }

    protected function latestReceiptStatus(object $invoice): ?string
    {
        $invoiceId = (int) ($invoice->id ?? 0);

        if ($invoiceId <= 0) {
            return null;
        }

        try {
            $status = NfseReceipt::where('invoice_id', $invoiceId)
                ->latest('id')
                ->value('status');

            return is_string($status) && $status !== '' ? $status : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

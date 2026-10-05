<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Models\NfseReceipt;

/**
 * Scopes Akaunting's native invoice send action to the NFS-e modal while
 * rendering a fiscal invoice. Nothing is changed globally at provider boot.
 */
final class NativeInvoiceSendOverride
{
    public function apply(mixed $invoice): void
    {
        if (!$this->shouldManage($invoice)) {
            return;
        }

        config([
            'type.document.invoice.route.emails.create' => 'nfse.modals.invoices.emails.create',
            'type.document.invoice.translation.send_mail' => $this->translationKey($invoice),
        ]);
    }

    private function shouldManage(mixed $invoice): bool
    {
        if (!is_object($invoice) || ($invoice->type ?? '') !== 'invoice') {
            return false;
        }

        if ($this->latestReceiptStatus($invoice) !== null) {
            return true;
        }

        if (method_exists($invoice, 'loadMissing')) {
            try {
                $invoice->loadMissing(['items']);
            } catch (\Throwable) {
                // Fall through to the relation/object checks below.
            }
        }

        $items = $invoice->items ?? null;

        if (is_array($items)) {
            return $items !== [];
        }

        if (is_object($items) && method_exists($items, 'count')) {
            return (int) $items->count() > 0;
        }

        if (method_exists($invoice, 'items')) {
            try {
                $relation = $invoice->items();

                return is_object($relation)
                    && method_exists($relation, 'exists')
                    && (bool) $relation->exists();
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    private function translationKey(object $invoice): string
    {
        return match ($this->latestReceiptStatus($invoice)) {
            'emitted' => 'nfse::general.invoices.cancel',
            'cancelled' => 'nfse::general.invoices.reemit',
            default => 'nfse::general.invoices.emit_now',
        };
    }

    private function latestReceiptStatus(object $invoice): ?string
    {
        $invoiceId = is_numeric($invoice->id ?? null) ? (int) $invoice->id : 0;

        if ($invoiceId <= 0) {
            return null;
        }

        try {
            $receipt = NfseReceipt::query()
                ->where('invoice_id', $invoiceId)
                ->latest('id')
                ->first();
            $status = is_object($receipt) ? trim((string) ($receipt->status ?? '')) : '';

            return $status !== '' ? $status : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

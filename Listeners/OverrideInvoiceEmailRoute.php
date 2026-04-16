<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Traits\Modules;
use Modules\Nfse\Models\CompanyService;
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
            'type.document.invoice.translation.send_mail' => 'nfse::general.invoices.emit_now',
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

        if ($receiptStatus === 'emitted') {
            return false;
        }

        if ($receiptStatus !== null) {
            return true;
        }

        return $this->hasActiveCompanyService($invoice);
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

    protected function hasActiveCompanyService(object $invoice): bool
    {
        $companyId = (int) ($invoice->company_id ?? 0);

        if ($companyId <= 0) {
            return false;
        }

        try {
            return CompanyService::where('company_id', $companyId)
                ->where('is_active', true)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Traits\Modules;
use LibreCodeCoop\NfsePHP\SecretStore\OpenBaoSecretStore;
use Modules\Nfse\Models\CompanyService;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\VaultConfig;

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

        if (!$this->isOperationalSetupReadyForInvoice($invoice)) {
            return false;
        }

        return $this->hasExistingReceipt($invoice) || $this->hasActiveCompanyService($invoice);
    }

    protected function isOperationalSetupReadyForInvoice(object $invoice): bool
    {
        $cnpj = trim((string) setting('nfse.cnpj_prestador', ''));
        $municipioIbge = trim((string) setting('nfse.municipio_ibge', ''));
        $hasDefaultService = $this->hasDefaultActiveCompanyService($invoice);
        $certificatePath = $cnpj !== '' ? storage_path('app/nfse/pfx/' . $cnpj . '.pfx') : '';
        $transportCertificatePath = $this->projectRootPath('client.crt.pem');
        $transportPrivateKeyPath = $this->projectRootPath('client.key.pem');

        return $cnpj !== ''
            && $municipioIbge !== ''
            && $hasDefaultService
            && $certificatePath !== ''
            && is_file($certificatePath)
            && is_file($transportCertificatePath)
            && is_file($transportPrivateKeyPath)
            && $this->hasCertificateSecret($cnpj);
    }

    protected function hasDefaultActiveCompanyService(object $invoice): bool
    {
        $companyId = (int) ($invoice->company_id ?? 0);

        if ($companyId <= 0) {
            return false;
        }

        try {
            return CompanyService::where('company_id', $companyId)
                ->where('is_default', true)
                ->where('is_active', true)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function hasCertificateSecret(string $cnpj): bool
    {
        if ($cnpj === '') {
            return false;
        }

        try {
            $config = VaultConfig::secretStoreConfig();

            $secret = (new OpenBaoSecretStore(
                addr: $config['addr'],
                mount: $config['mount'],
                token: $config['token'],
                roleId: $config['roleId'],
                secretId: $config['secretId'],
            ))->get('pfx/' . $cnpj);

            return (($secret['password'] ?? '') !== '') && (($secret['pfx_path'] ?? '') !== '');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function projectRootPath(string $relativePath): string
    {
        if (function_exists('app')) {
            try {
                $application = app();

                if (is_object($application) && method_exists($application, 'basePath')) {
                    return $application->basePath($relativePath);
                }
            } catch (\Throwable) {
                // Fall through to module-relative fallback.
            }
        }

        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
    }

    protected function hasExistingReceipt(object $invoice): bool
    {
        $invoiceId = (int) ($invoice->id ?? 0);

        if ($invoiceId <= 0) {
            return false;
        }

        try {
            return NfseReceipt::where('invoice_id', $invoiceId)->exists();
        } catch (\Throwable) {
            return false;
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

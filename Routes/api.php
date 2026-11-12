<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Nfse\Http\Controllers\EmissionAttemptController;
use Modules\Nfse\Http\Controllers\FiscalEventEvidenceController;
use Modules\Nfse\Http\Controllers\DpsReadinessController;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Modules\Nfse\Http\Controllers\InvoiceFiscalDecisionController;
use Modules\Nfse\Http\Controllers\CategoryIssuancePolicyController;
use Modules\Nfse\Http\Controllers\ApiCapabilityController;
use Modules\Nfse\Http\Controllers\NationalServicesController;
use Modules\Nfse\Http\Controllers\SettingsController;
use Modules\Nfse\Http\Controllers\TaxAssociationController;
use Modules\Nfse\Http\Controllers\ClosingController;

// The Core applies its configured API authentication, read-api and company
// identification middleware. Do not share the browser's session on this route.
use Modules\Nfse\Http\Controllers\CompanyProfileController;
use Modules\Nfse\Http\Controllers\CustomerEmailPreferencesController;
use Modules\Nfse\Http\Controllers\Modals\InvoiceEmails;
use Modules\Nfse\Http\Controllers\CertificateController;
use Modules\Nfse\Http\Controllers\FiscalLedgerApiController;
use Modules\Nfse\Http\Controllers\BulkEmissionApiController;
use Modules\Nfse\Http\Controllers\AdnController;
Route::api('nfse', function (): void {
    // Authenticated API capability listing, never an arbitrary route executor.
    Route::get('discovery', [ApiCapabilityController::class, 'index'])
        ->name('discovery.index');
    Route::get('dashboard', [InvoiceController::class, 'dashboardData'])
        ->middleware('permission:read-sales-invoices')
        ->defaults('mcp_read_contract', [
            'version' => 1, 'scope' => 'company',
            'response_paths' => ['/data/stats', '/data/recent_receipts'],
            'query' => [],
        ])->name('dashboard.index');

    // Status-only fiscal sequence diagnostics and official read-only DPS lookup.
    // These operations do not provision, reserve, transmit or activate.
    Route::get('dps/readiness', [DpsReadinessController::class, 'index'])
        ->middleware('permission:read-nfse-settings')->name('dps.readiness');
    Route::get('dps/lookup', [DpsReadinessController::class, 'lookup'])
        ->middleware('permission:read-nfse-settings')->name('dps.lookup');
    Route::post('dps/advance', [DpsReadinessController::class, 'advance'])
        ->middleware('permission:update-nfse-settings')->name('dps.advance');

    // Company-scoped fiscal event evidence, never an emission POST.
    Route::get('invoices/{invoice}/fiscal-assessment', [FiscalEventEvidenceController::class, 'index'])
        ->middleware('permission:read-sales-invoices')->name('invoices.fiscal-assessment');
    Route::post('invoices/{invoice}/fiscal-event-evidence', [FiscalEventEvidenceController::class, 'store'])
        ->middleware('permission:update-sales-invoices')->name('invoices.fiscal-event-evidence.store');

    Route::get('invoices/{invoice}/fiscal-decision', [InvoiceFiscalDecisionController::class, 'show'])
        ->middleware('permission:read-sales-invoices')
        ->defaults('mcp_read_contract', [
            'version' => 1,
            'scope' => 'invoice',
            'response_paths' => ['/data/current', '/data/effective', '/data/history'],
            'query' => [],
        ])->name('invoices.fiscal-decision.show');
    Route::post('invoices/{invoice}/fiscal-decision', [InvoiceFiscalDecisionController::class, 'store'])
        ->middleware('permission:update-sales-invoices')->name('invoices.fiscal-decision.store');

    // Native API issuance reuses the exact manual issuance handler, DPS
    // journal and recovery services; no second fiscal pipeline is introduced.
    Route::post('invoices/{invoice}/emit', [InvoiceController::class, 'emit'])
        ->middleware('permission:update-sales-invoices')
        ->name('invoices.emit');
    Route::post('invoices/{invoice}/reemit', [InvoiceController::class, 'reemit'])
        ->middleware('permission:update-sales-invoices')->name('invoices.reemit');
    Route::post('invoices/{invoice}/substitute', [InvoiceController::class, 'substitute'])
        ->middleware('permission:update-sales-invoices')->name('invoices.substitute');
    Route::post('invoices/{invoice}/refresh', [InvoiceController::class, 'refresh'])
        ->middleware('permission:update-sales-invoices')->name('invoices.refresh');
    Route::post('invoices/refresh-all', [InvoiceController::class, 'refreshAll'])
        ->middleware('permission:update-sales-invoices')->name('invoices.refresh-all');
    Route::get('invoices/{invoice}/email-preferences', [CustomerEmailPreferencesController::class, 'show'])
        ->middleware('permission:read-sales-invoices')
        ->defaults('mcp_read_contract', [
            'version' => 1, 'scope' => 'invoice',
            'response_paths' => ['/data/configured', '/data/subject', '/data/body'],
            'query' => [],
        ])->name('invoices.email-preferences.show');
    Route::post('invoices/{invoice}/email-preferences', [CustomerEmailPreferencesController::class, 'store'])
        ->middleware('permission:update-sales-invoices')->name('invoices.email-preferences.store');
    // The same send lifecycle as Akaunting's browser modal; never a second fiscal pipeline.
    Route::post('invoices/{invoice}/emails', [InvoiceEmails::class, 'store'])
        ->middleware('permission:update-sales-invoices')->name('invoices.emails.store');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'cancel'])
        ->middleware('permission:update-sales-invoices')->name('invoices.cancel');

    Route::get('invoices/{invoice}/service-preview', [InvoiceController::class, 'servicePreview'])
        ->middleware('permission:read-sales-invoices')
        ->name('invoices.service-preview');
    Route::get('invoices/{invoice}/post-emission-status', [InvoiceController::class, 'postEmissionStatus'])
        ->middleware('permission:read-sales-invoices')
        ->name('invoices.post-emission-status');

    Route::post('bulk/plan', [BulkEmissionApiController::class, 'plan'])
        ->middleware('permission:read-sales-invoices')->name('bulk.plan');
    Route::post('bulk/dispatch', [BulkEmissionApiController::class, 'enqueueJson'])
        ->middleware('permission:update-sales-invoices')->name('bulk.dispatch');
    Route::get('bulk/runs', [BulkEmissionApiController::class, 'runs'])
        ->middleware('permission:read-sales-invoices')->name('bulk.runs');
    Route::get('bulk/runs/{run}', [BulkEmissionApiController::class, 'show'])
        ->middleware('permission:read-sales-invoices')->name('bulk.runs.show');

    // ADN shares the native HTTP authentication and active-company context.
    Route::get('adn/review-options', [AdnController::class, 'reviewOptions'])
        ->middleware('permission:read-purchases-bills')
        ->name('adn.review-options');
    Route::get('adn/documents', [AdnController::class, 'documents'])
        ->middleware('permission:read-purchases-bills')
        ->name('adn.documents.index');
    Route::get('adn/documents/{document}/review-history', [AdnController::class, 'reviewHistory'])
        ->middleware('permission:read-purchases-bills')
        ->name('adn.documents.review-history');
    Route::get('adn/documents/{document}/preview', [AdnController::class, 'preview'])
        ->middleware('permission:read-purchases-bills')
        ->name('adn.documents.preview');
    Route::get('adn/distribution', [AdnController::class, 'distribution'])
        ->middleware('permission:read-nfse-settings')
        ->name('adn.distribution');
    Route::get('invoices/{invoice}/adn-events', [AdnController::class, 'events'])
        ->middleware('permission:read-sales-invoices')
        ->name('invoices.adn-events');
    Route::post('adn/documents/{document}/ignore', [AdnController::class, 'ignore'])
        ->middleware('permission:update-purchases-bills')
        ->name('adn.documents.ignore');
    Route::post('adn/documents/{document}/import', [AdnController::class, 'importDraft'])
        ->middleware('permission:create-purchases-bills')
        ->name('adn.documents.import');


    Route::get('ledger', [FiscalLedgerApiController::class, 'index'])
        ->middleware('permission:read-sales-invoices')
        ->name('ledger.index');
    Route::get('receipts/{receipt}/artifacts/{artifact}', [FiscalLedgerApiController::class, 'artifact'])
        ->middleware('permission:read-sales-invoices')
        ->name('receipts.artifacts.download');


    Route::get('closing/export', [ClosingController::class, 'export'])
        ->middleware('permission:read-sales-invoices')
        ->name('closing.export');
    Route::get('closing/ibs-cbs-evidence', [ClosingController::class, 'exportIbsCbs'])
        ->middleware('permission:read-sales-invoices')
        ->name('closing.ibs-cbs-evidence');
    Route::get('closing', [ClosingController::class, 'data'])
        ->middleware('permission:read-sales-invoices')
        ->name('closing.index');


    // One set of company profile, certificate and infrastructure controllers
    // for native clients and authenticated browser sessions.
    Route::get('settings/company-profiles', [CompanyProfileController::class, 'index'])
        ->middleware('permission:read-nfse-settings')
        ->name('settings.company-profiles.index');
    Route::post('settings/company-profiles', [CompanyProfileController::class, 'store'])
        ->middleware('permission:update-nfse-settings')
        ->name('settings.company-profiles.store');
    Route::patch('settings/company-profiles/{companyId}', [CompanyProfileController::class, 'update'])
        ->middleware('permission:update-nfse-settings')
        ->name('settings.company-profiles.update');
    Route::delete('settings/company-profiles/{companyId}', [CompanyProfileController::class, 'destroy'])
        ->middleware('permission:delete-nfse-settings')
        ->name('settings.company-profiles.destroy');
    Route::post('settings/company-profiles/{companyId}/cleanup', [CompanyProfileController::class, 'cleanup'])
        ->middleware('permission:delete-nfse-settings')
        ->name('settings.company-profiles.cleanup');
    Route::get('settings/category-issuance', [CategoryIssuancePolicyController::class, 'show'])
        ->middleware('permission:read-nfse-settings')
        ->defaults('mcp_read_contract', [
            'version' => 1,
            'scope' => 'company',
            'response_paths' => ['/data'],
            'query' => [],
        ])->name('settings.category-issuance.index');
    Route::post('settings/category-issuance', [CategoryIssuancePolicyController::class, 'store'])
        ->middleware('permission:update-nfse-settings')->name('settings.category-issuance.store');
    Route::get('settings/artifacts', [SettingsController::class, 'artifactSettings'])
        ->middleware('permission:read-nfse-settings')->name('settings.artifacts.show');
    Route::patch('settings/artifacts', [SettingsController::class, 'updateArtifacts'])
        ->middleware('permission:update-nfse-settings')->name('settings.artifacts.update');
    Route::get('settings/email-footer', [SettingsController::class, 'emailFooter'])
        ->middleware('permission:read-nfse-settings')
        ->defaults('mcp_read_contract', [
            'version' => 1, 'scope' => 'company',
            'response_paths' => ['/data/footer_message', '/data/footer_url'],
            'query' => [],
        ])->name('settings.email-footer.show');
    Route::patch('settings/email-footer', [SettingsController::class, 'updateEmailFooter'])
        ->middleware('permission:update-nfse-settings')->name('settings.email-footer.update');
    Route::get('settings/vault', [SettingsController::class, 'vaultStatus'])
        ->middleware('permission:read-nfse-settings')
        ->name('settings.vault.show');
    Route::patch('settings/vault', [SettingsController::class, 'updateVault'])
        ->middleware('permission:update-nfse-settings')
        ->name('settings.vault.update');
    Route::post('settings/vault/diagnostics', [SettingsController::class, 'diagnoseVault'])
        ->middleware('permission:read-nfse-settings')
        ->name('settings.vault.diagnostics');
    Route::post('certificates/inspect', [CertificateController::class, 'parsePfx'])
        ->middleware('permission:update-nfse-settings')
        ->name('certificates.inspect');

    Route::get('settings/tax-associations', [TaxAssociationController::class, 'catalog'])
        ->middleware('permission:read-nfse-settings')->name('settings.tax-associations.index');
    Route::post('settings/tax-associations', [TaxAssociationController::class, 'store'])
        ->middleware('permission:update-nfse-settings')->name('settings.tax-associations.store');
    Route::patch('settings/tax-associations/{associationId}', [TaxAssociationController::class, 'revise'])
        ->whereNumber('associationId')
        ->middleware('permission:update-nfse-settings')->name('settings.tax-associations.revise');
    Route::get('settings/approximate-taxes', [SettingsController::class, 'approximateTaxSources'])
        ->middleware('permission:read-nfse-settings')->name('settings.approximate-taxes.index');
    Route::post('settings/approximate-taxes', [SettingsController::class, 'storeApproximateTaxes'])
        ->middleware('permission:update-nfse-settings')->name('settings.approximate-taxes.store');

    Route::get('national-services', [NationalServicesController::class, 'nationalServices'])
        ->middleware('permission:read-nfse-settings')
        ->name('national-services.index');
    Route::get('ibge/ufs', [SettingsController::class, 'ufs'])
        ->middleware('permission:read-nfse-settings')
        ->defaults('mcp_read_contract', [
            'version' => 1, 'scope' => 'company',
            'response_paths' => ['/data'], 'query' => [],
        ])->name('ibge.ufs');
    Route::get('ibge/municipalities/{uf}', [SettingsController::class, 'municipalities'])
        ->middleware('permission:read-nfse-settings')
        ->defaults('mcp_read_contract', [
            'version' => 1, 'scope' => 'company',
            'response_paths' => ['/data'], 'query' => [],
        ])->name('ibge.municipalities');
    Route::get('lc116/services', [SettingsController::class, 'lc116Services'])
        ->middleware('permission:read-nfse-settings')
        ->defaults('mcp_read_contract', [
            'version' => 1, 'scope' => 'company',
            'response_paths' => ['/data'],
            'query' => ['q' => 'short_text', 'limit' => 'positive_integer'],
        ])->name('lc116.services');
    // MunicipalParameterSnapshotStore may update its advisory cache on GET.
    // Keep the native HTTP resource, but never advertise it as side-effect-free to MCP.
    Route::get('municipal-parameters', [SettingsController::class, 'municipalParameters'])
        ->middleware('permission:read-nfse-settings')->name('municipal-parameters.index');

    Route::get('invoices/{invoice}/emission-attempts', [EmissionAttemptController::class, 'index'])
        ->middleware('permission:read-sales-invoices')
        ->name('invoices.emission-attempts.index');
});

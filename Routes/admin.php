<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Nfse\Http\Controllers\AdnController;
use Modules\Nfse\Http\Controllers\BulkEmissionController;
use Modules\Nfse\Http\Controllers\BulkEmissionApiController;
use Modules\Nfse\Http\Controllers\CertificateController;
use Modules\Nfse\Http\Controllers\CompanyProfileController;
use Modules\Nfse\Http\Controllers\DeprecatedFiscalConfigurationController;
use Modules\Nfse\Http\Controllers\ClosingController;
use Modules\Nfse\Http\Controllers\FiscalLedgerApiController;
use Modules\Nfse\Http\Controllers\EmissionAttemptController;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Modules\Nfse\Http\Controllers\LegacyInvoiceController;
use Modules\Nfse\Http\Controllers\NationalServicesController;
use Modules\Nfse\Http\Controllers\SettingsController;
use Modules\Nfse\Http\Controllers\TaxAssociationController;
use Modules\Nfse\Http\Controllers\Modals\InvoiceEmails;

Route::admin('nfse', function () {
    Route::get('/', [InvoiceController::class, 'dashboard'])->name('dashboard.index');

    // Settings
    Route::group(['prefix' => 'settings', 'as' => 'settings.'], function () {
        Route::get('/', [SettingsController::class, 'edit'])
            ->middleware('permission:read-nfse-settings')->name('edit');
        Route::patch('/', DeprecatedFiscalConfigurationController::class)
            ->middleware('permission:update-nfse-settings')->name('update');
        Route::get('/vault', [SettingsController::class, 'vaultStatus'])
            ->middleware('permission:read-nfse-settings')->name('vault.status');
        Route::patch('/vault', [SettingsController::class, 'updateVault'])
            ->middleware('permission:update-nfse-settings')->name('vault');
        Route::post('/vault/diagnostics', [SettingsController::class, 'diagnoseVault'])
            ->middleware('permission:read-nfse-settings')->name('vault.diagnostics');
        Route::patch('/fiscal', DeprecatedFiscalConfigurationController::class)
            ->middleware('permission:update-nfse-settings')->name('fiscal');
        Route::patch('/federal', DeprecatedFiscalConfigurationController::class)
            ->middleware('permission:update-nfse-settings')->name('federal');
        Route::get('/approximate-taxes', [SettingsController::class, 'approximateTaxes'])
            ->middleware('permission:read-nfse-settings')->name('approximate-taxes');
        Route::post('/approximate-taxes', [SettingsController::class, 'storeApproximateTaxes'])
            ->middleware('permission:update-nfse-settings')->name('approximate-taxes.store');
        Route::get('/approximate-taxes/resources', [SettingsController::class, 'approximateTaxSources'])
            ->middleware('permission:read-nfse-settings')->name('approximate-taxes.resources');
        Route::get('/tax-associations/resources', [TaxAssociationController::class, 'catalog'])
            ->middleware('permission:read-nfse-settings')->name('tax-associations.resources');
        Route::get('/tax-associations', [TaxAssociationController::class, 'index'])
            ->middleware('permission:read-nfse-settings')->name('tax-associations.index');
        Route::post('/tax-associations', [TaxAssociationController::class, 'store'])
            ->middleware('permission:update-nfse-settings')->name('tax-associations.store');
        Route::patch('/artifacts', [SettingsController::class, 'updateArtifacts'])
            ->middleware('permission:update-nfse-settings')->name('artifacts');

        // JSON backend contract for native Akaunting companies (#419/#420).
        Route::get('/company-profiles', [CompanyProfileController::class, 'index'])
            ->middleware('permission:read-nfse-settings')->name('company-profiles.index');
        Route::post('/company-profiles', [CompanyProfileController::class, 'store'])
            ->middleware('permission:update-nfse-settings')->name('company-profiles.store');
        Route::patch('/company-profiles/{companyId}', [CompanyProfileController::class, 'update'])
            ->middleware('permission:update-nfse-settings')->name('company-profiles.update');
        Route::delete('/company-profiles/{companyId}', [CompanyProfileController::class, 'destroy'])
            ->middleware('permission:delete-nfse-settings')->name('company-profiles.destroy');
        Route::post('/company-profiles/{companyId}/cleanup', [CompanyProfileController::class, 'cleanup'])
            ->middleware('permission:delete-nfse-settings')->name('company-profiles.cleanup');
    });

    // IBGE localities lookup
    Route::get('ibge/ufs', [SettingsController::class, 'ufs'])->name('ibge.ufs');
    Route::get('ibge/municipalities/{uf}', [SettingsController::class, 'municipalities'])->name('ibge.municipalities');
    Route::get('lc116/services', [SettingsController::class, 'lc116Services'])->name('lc116.services');
    Route::get('national-services', [NationalServicesController::class, 'nationalServices'])
        ->middleware('permission:read-nfse-settings')->name('national-services');
    Route::get('municipal-parameters', [SettingsController::class, 'municipalParameters'])
        ->middleware('permission:read-nfse-settings')->name('municipal-parameters');

    // Certificate management
    Route::post('certificate', DeprecatedFiscalConfigurationController::class)
        ->middleware('permission:update-nfse-settings')->name('certificate.upload');
    Route::post('certificate/parse', [CertificateController::class, 'parsePfx'])
        ->middleware('permission:update-nfse-settings')->name('certificate.parse');
    Route::delete('certificate', DeprecatedFiscalConfigurationController::class)
        ->middleware('permission:delete-nfse-settings')->name('certificate.destroy');

    // ADN contributor distribution
    Route::get('adn', [AdnController::class, 'index'])->middleware('permission:read-purchases-bills')->name('adn.index');
    Route::get('adn/distribution', [AdnController::class, 'distribution'])
        ->middleware('permission:read-nfse-settings')->name('adn.distribution');
    Route::get('adn/review-options', [AdnController::class, 'reviewOptions'])
        ->middleware('permission:read-purchases-bills')->name('adn.review-options');
    Route::get('adn/documents', [AdnController::class, 'documents'])
        ->middleware('permission:read-purchases-bills')->name('adn.documents.index');
    Route::get('adn/documents/{document}/review-history', [AdnController::class, 'reviewHistory'])
        ->middleware('permission:read-purchases-bills')->name('adn.documents.review-history');
    Route::get('adn/documents/{document}/preview', [AdnController::class, 'preview'])
        ->middleware('permission:read-purchases-bills')->name('adn.documents.preview');
    Route::post('adn/review/{document}/ignore', [AdnController::class, 'ignore'])->middleware('permission:update-purchases-bills')->name('adn.review.ignore');
    Route::post('adn/review/{document}/import', [AdnController::class, 'importDraft'])->middleware('permission:create-purchases-bills')->name('adn.review.import');

    // Controlled bulk emission; browser and external API share the same service.
    Route::get('bulk', [BulkEmissionController::class, 'index'])
        ->middleware('permission:read-sales-invoices')->name('bulk.index');
    Route::post('bulk/dispatch', [BulkEmissionController::class, 'enqueue'])
        ->middleware('permission:update-sales-invoices')->name('bulk.dispatch');
    Route::post('bulk/plan', [BulkEmissionApiController::class, 'plan'])
        ->middleware('permission:read-sales-invoices')->name('bulk.plan');
    Route::get('bulk/runs', [BulkEmissionApiController::class, 'runs'])
        ->middleware('permission:read-sales-invoices')->name('bulk.runs');
    Route::get('bulk/runs/{run}', [BulkEmissionApiController::class, 'show'])
        ->middleware('permission:read-sales-invoices')->name('bulk.runs.show');
    Route::post('bulk/dispatch-json', [BulkEmissionApiController::class, 'enqueueJson'])
        ->middleware('permission:update-sales-invoices')->name('bulk.dispatch-json');

    // Competence closing and reconciliation
    Route::get('closing/data', [ClosingController::class, 'data'])
        ->middleware('permission:read-sales-invoices')->name('closing.data');
    Route::get('closing', [ClosingController::class, 'index'])
        ->middleware('permission:read-sales-invoices')->name('closing.index');
    Route::get('closing/export', [ClosingController::class, 'export'])
        ->middleware('permission:read-sales-invoices')->name('closing.export');

    // Receipt-oriented fiscal ledger; accounting invoices remain native to Akaunting.
    Route::get('ledger/data', [FiscalLedgerApiController::class, 'index'])
        ->middleware('permission:read-sales-invoices')->name('ledger.data');
    Route::get('ledger', [InvoiceController::class, 'fiscalLedger'])
        ->middleware('permission:read-sales-invoices')
        ->name('ledger.index');
    Route::get('ledger/{receipt}/artifacts/{artifact}', [InvoiceController::class, 'downloadLedgerArtifact'])
        ->middleware('permission:read-sales-invoices')
        ->name('ledger.artifacts.download');

    // NFS-e issuance
    Route::get('invoices', [LegacyInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/pending', [LegacyInvoiceController::class, 'pending'])->name('invoices.pending');
    Route::post('invoices/{invoice}/emit', [InvoiceController::class, 'emit'])
        ->middleware('permission:update-sales-invoices')->name('invoices.emit');
    Route::get('invoices/{invoice}/service-preview', [InvoiceController::class, 'servicePreview'])->name('invoices.service-preview');
    Route::post('invoices/refresh-all', [InvoiceController::class, 'refreshAll'])
        ->middleware('permission:update-sales-invoices')->name('invoices.refresh-all');
    Route::post('invoices/{invoice}/refresh', [InvoiceController::class, 'refresh'])
        ->middleware('permission:update-sales-invoices')->name('invoices.refresh');
    Route::post('invoices/{invoice}/reemit', [InvoiceController::class, 'reemit'])
        ->middleware('permission:update-sales-invoices')->name('invoices.reemit');
    Route::post('invoices/{invoice}/substitute', [InvoiceController::class, 'substitute'])
        ->middleware('permission:update-sales-invoices')->name('invoices.substitute');
    Route::get('invoices/{invoice}', [LegacyInvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/emit-success', [InvoiceController::class, 'showEmitSuccess'])->name('invoices.emit-success');
    Route::get('invoices/{invoice}/post-emission-status', [InvoiceController::class, 'postEmissionStatus'])->name('invoices.post-emission-status');
    Route::get('invoices/{invoice}/emission-attempts', [EmissionAttemptController::class, 'index'])
        ->middleware('permission:read-sales-invoices')
        ->name('invoices.emission-attempts');
    Route::get('invoices/{invoice}/adn-events', [AdnController::class, 'events'])->middleware('permission:read-sales-invoices')->name('invoices.adn-events');
    Route::get('invoices/{invoice}/artifacts/{artifact}', [InvoiceController::class, 'downloadArtifact'])->name('invoices.artifacts.download');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'cancel'])
        ->middleware('permission:update-sales-invoices')->name('invoices.cancel');

        // Email modal — replaces the Akaunting core email modal for NFS-e invoices
        Route::get('modals/invoices/{invoice}/emails/create', [InvoiceEmails::class, 'create'])->name('modals.invoices.emails.create');
        Route::post('modals/invoices/{invoice}/emails', [InvoiceEmails::class, 'store'])->name('modals.invoices.emails.store');
});

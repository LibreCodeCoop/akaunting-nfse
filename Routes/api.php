<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Nfse\Http\Controllers\EmissionAttemptController;
use Modules\Nfse\Http\Controllers\NationalServicesController;
use Modules\Nfse\Http\Controllers\SettingsController;
use Modules\Nfse\Http\Controllers\TaxAssociationController;
use Modules\Nfse\Http\Controllers\ClosingController;

// The Core applies its configured API authentication, read-api and company
// identification middleware. Do not share the browser's session on this route.
use Modules\Nfse\Http\Controllers\CompanyProfileController;
use Modules\Nfse\Http\Controllers\CertificateController;
use Modules\Nfse\Http\Controllers\FiscalLedgerApiController;
use Modules\Nfse\Http\Controllers\BulkEmissionApiController;
use Modules\Nfse\Http\Controllers\AdnController;
Route::api('nfse', function (): void {
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
    Route::get('settings/approximate-taxes', [SettingsController::class, 'approximateTaxSources'])
        ->middleware('permission:read-nfse-settings')->name('settings.approximate-taxes.index');
    Route::post('settings/approximate-taxes', [SettingsController::class, 'storeApproximateTaxes'])
        ->middleware('permission:update-nfse-settings')->name('settings.approximate-taxes.store');

    Route::get('national-services', [NationalServicesController::class, 'nationalServices'])
        ->middleware('permission:read-nfse-settings')
        ->name('national-services.index');

    Route::get('invoices/{invoice}/emission-attempts', [EmissionAttemptController::class, 'index'])
        ->middleware('permission:read-sales-invoices')
        ->name('invoices.emission-attempts.index');
});

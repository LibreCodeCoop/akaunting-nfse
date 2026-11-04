<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Nfse\Http\Controllers\EmissionAttemptController;
use Modules\Nfse\Http\Controllers\NationalServicesController;
use Modules\Nfse\Http\Controllers\SettingsController;
use Modules\Nfse\Http\Controllers\TaxAssociationController;

// The Core applies its configured API authentication, read-api and company
// identification middleware. Do not share the browser's session on this route.
use Modules\Nfse\Http\Controllers\FiscalLedgerApiController;
Route::api('nfse', function (): void {
    Route::get('ledger', [FiscalLedgerApiController::class, 'index'])
        ->middleware('permission:read-sales-invoices')
        ->name('ledger.index');
    Route::get('receipts/{receipt}/artifacts/{artifact}', [FiscalLedgerApiController::class, 'artifact'])
        ->middleware('permission:read-sales-invoices')
        ->name('receipts.artifacts.download');


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

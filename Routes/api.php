<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Nfse\Http\Controllers\EmissionAttemptController;
use Modules\Nfse\Http\Controllers\NationalServicesController;
use Modules\Nfse\Http\Controllers\SettingsController;
use Modules\Nfse\Http\Controllers\TaxAssociationController;
use Modules\Nfse\Http\Controllers\AdnController;

// The Core applies its configured API authentication, read-api and company
// identification middleware. Do not share the browser's session on this route.
Route::api('nfse', function (): void {
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

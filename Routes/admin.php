<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Nfse\Http\Controllers\CertificateController;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Modules\Nfse\Http\Controllers\SettingsController;

Route::module('nfse', function () {
    Route::get('/', [InvoiceController::class, 'dashboard'])->name('dashboard.index');

    // Settings
    Route::group(['prefix' => 'settings', 'as' => 'settings.'], function () {
        Route::get('/', [SettingsController::class, 'edit'])->name('edit');
        Route::get('/readiness', [SettingsController::class, 'readiness'])->name('readiness');
        Route::patch('/', [SettingsController::class, 'update'])->name('update');
    });

    // IBGE localities lookup
    Route::get('ibge/ufs', [SettingsController::class, 'ufs'])->name('ibge.ufs');
    Route::get('ibge/municipalities/{uf}', [SettingsController::class, 'municipalities'])->name('ibge.municipalities');
    Route::get('lc116/services', [SettingsController::class, 'lc116Services'])->name('lc116.services');

    // Certificate management
    Route::post('certificate', [CertificateController::class, 'upload'])->name('certificate.upload');
    Route::post('certificate/parse', [CertificateController::class, 'parsePfx'])->name('certificate.parse');
    Route::delete('certificate', [CertificateController::class, 'destroy'])->name('certificate.destroy');

    // NFS-e issuance
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/pending', [InvoiceController::class, 'pending'])->name('invoices.pending');
    Route::post('invoices/{invoice}/emit', [InvoiceController::class, 'emit'])->name('invoices.emit');
    Route::post('invoices/refresh-all', [InvoiceController::class, 'refreshAll'])->name('invoices.refresh-all');
    Route::post('invoices/{invoice}/refresh', [InvoiceController::class, 'refresh'])->name('invoices.refresh');
    Route::post('invoices/{invoice}/reemit', [InvoiceController::class, 'reemit'])->name('invoices.reemit');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
}, [
    'middleware' => ['web', 'auth', 'language', 'company.identify'],
]);

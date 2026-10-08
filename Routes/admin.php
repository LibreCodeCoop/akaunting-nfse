<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Nfse\Http\Controllers\AdnController;
use Modules\Nfse\Http\Controllers\BulkEmissionController;
use Modules\Nfse\Http\Controllers\CertificateController;
use Modules\Nfse\Http\Controllers\ClosingController;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Modules\Nfse\Http\Controllers\LegacyInvoiceController;
use Modules\Nfse\Http\Controllers\SettingsController;
use Modules\Nfse\Http\Controllers\Modals\InvoiceEmails;

Route::admin('nfse', function () {
    Route::get('/', [InvoiceController::class, 'dashboard'])->name('dashboard.index');

    // Settings
    Route::group(['prefix' => 'settings', 'as' => 'settings.'], function () {
        Route::get('/', [SettingsController::class, 'edit'])->name('edit');
        Route::patch('/', [SettingsController::class, 'update'])->name('update');
        Route::patch('/vault', [SettingsController::class, 'updateVault'])->name('vault');
        Route::patch('/fiscal', [SettingsController::class, 'updateFiscal'])->name('fiscal');
        Route::patch('/federal', [SettingsController::class, 'updateFederal'])->name('federal');
        Route::patch('/artifacts', [SettingsController::class, 'updateArtifacts'])->name('artifacts');
    });

    // IBGE localities lookup
    Route::get('ibge/ufs', [SettingsController::class, 'ufs'])->name('ibge.ufs');
    Route::get('ibge/municipalities/{uf}', [SettingsController::class, 'municipalities'])->name('ibge.municipalities');
    Route::get('lc116/services', [SettingsController::class, 'lc116Services'])->name('lc116.services');
    Route::get('municipal-parameters', [SettingsController::class, 'municipalParameters'])->name('municipal-parameters');

    // Certificate management
    Route::post('certificate', [CertificateController::class, 'upload'])->name('certificate.upload');
    Route::post('certificate/parse', [CertificateController::class, 'parsePfx'])->name('certificate.parse');
    Route::delete('certificate', [CertificateController::class, 'destroy'])->name('certificate.destroy');

    // ADN contributor distribution
    Route::get('adn', [AdnController::class, 'index'])->name('adn.index');
    Route::get('adn/distribution', [AdnController::class, 'distribution'])->name('adn.distribution');
    Route::post('adn/review/{document}/ignore', [AdnController::class, 'ignore'])->name('adn.review.ignore');
    Route::post('adn/review/{document}/import', [AdnController::class, 'importDraft'])->name('adn.review.import');

    // Controlled bulk emission
    Route::get('bulk', [BulkEmissionController::class, 'index'])->name('bulk.index');
    Route::post('bulk/dispatch', [BulkEmissionController::class, 'enqueue'])->name('bulk.dispatch');

    // Competence closing and reconciliation
    Route::get('closing', [ClosingController::class, 'index'])->name('closing.index');
    Route::get('closing/export', [ClosingController::class, 'export'])->name('closing.export');

    // Receipt-oriented fiscal ledger; accounting invoices remain native to Akaunting.
    Route::get('ledger', [InvoiceController::class, 'fiscalLedger'])->name('ledger.index');

    // NFS-e issuance
    Route::get('invoices', [LegacyInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/pending', [LegacyInvoiceController::class, 'pending'])->name('invoices.pending');
    Route::post('invoices/{invoice}/emit', [InvoiceController::class, 'emit'])->name('invoices.emit');
    Route::get('invoices/{invoice}/service-preview', [InvoiceController::class, 'servicePreview'])->name('invoices.service-preview');
    Route::post('invoices/refresh-all', [InvoiceController::class, 'refreshAll'])->name('invoices.refresh-all');
    Route::post('invoices/{invoice}/refresh', [InvoiceController::class, 'refresh'])->name('invoices.refresh');
    Route::post('invoices/{invoice}/reemit', [InvoiceController::class, 'reemit'])->name('invoices.reemit');
    Route::post('invoices/{invoice}/substitute', [InvoiceController::class, 'substitute'])->name('invoices.substitute');
    Route::get('invoices/{invoice}', [LegacyInvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/emit-success', [InvoiceController::class, 'showEmitSuccess'])->name('invoices.emit-success');
    Route::get('invoices/{invoice}/post-emission-status', [InvoiceController::class, 'postEmissionStatus'])->name('invoices.post-emission-status');
    Route::get('invoices/{invoice}/adn-events', [AdnController::class, 'events'])->name('invoices.adn-events');
    Route::get('invoices/{invoice}/artifacts/{artifact}', [InvoiceController::class, 'downloadArtifact'])->name('invoices.artifacts.download');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'cancel'])->name('invoices.cancel');

        // Email modal — replaces the Akaunting core email modal for NFS-e invoices
        Route::get('modals/invoices/{invoice}/emails/create', [InvoiceEmails::class, 'create'])->name('modals.invoices.emails.create');
        Route::post('modals/invoices/{invoice}/emails', [InvoiceEmails::class, 'store'])->name('modals.invoices.emails.store');
});

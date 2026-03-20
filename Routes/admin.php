<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::group([
    'prefix'     => 'nfse',
    'middleware' => ['web', 'auth', 'language', 'company'],
    'namespace'  => 'Modules\Nfse\Http\Controllers',
], function () {
    // Settings
    Route::get('settings', 'SettingsController@edit')->name('nfse.settings.edit');
    Route::patch('settings', 'SettingsController@update')->name('nfse.settings.update');

    // Certificate management
    Route::post('certificate', 'CertificateController@upload')->name('nfse.certificate.upload');
    Route::delete('certificate', 'CertificateController@destroy')->name('nfse.certificate.destroy');

    // NFS-e issuance
    Route::get('invoices', 'InvoiceController@index')->name('nfse.invoices.index');
    Route::post('invoices/{invoice}/emit', 'InvoiceController@emit')->name('nfse.invoices.emit');
    Route::get('invoices/{invoice}', 'InvoiceController@show')->name('nfse.invoices.show');
    Route::delete('invoices/{invoice}', 'InvoiceController@cancel')->name('nfse.invoices.cancel');
});

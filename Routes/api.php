<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Nfse\Http\Controllers\NationalServicesController;

// The Core applies its configured API authentication, read-api and company
// identification middleware. Do not share the browser's session on this route.
Route::api('nfse', function (): void {
    Route::get('national-services', [NationalServicesController::class, 'nationalServices'])
        ->middleware('permission:read-nfse-settings')
        ->name('national-services.index');
});

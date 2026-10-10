<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/App/Abstracts/Http/Controller.php';
    require_once __DIR__ . '/Modules/Nfse/Http/Controllers/ControllerIsolationFakeApplication.php';
    require_once __DIR__ . '/Illuminate/Http/Request.php';
    require_once __DIR__ . '/Illuminate/Http/UploadedFile.php';
    require_once __DIR__ . '/Illuminate/Http/RedirectResponse.php';
    require_once __DIR__ . '/Illuminate/Http/JsonResponse.php';
    require_once __DIR__ . '/Illuminate/View/View.php';
    require_once __DIR__ . '/App/Events/Document/DocumentMarkedSent.php';

    if (!function_exists('app')) {
        function app(): \Modules\Nfse\Http\Controllers\ControllerIsolationFakeApplication
        {
            return new \Modules\Nfse\Http\Controllers\ControllerIsolationFakeApplication();
        }
    }
}

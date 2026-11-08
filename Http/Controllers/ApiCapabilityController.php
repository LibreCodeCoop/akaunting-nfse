<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Nfse\Application\ReadApiCapabilities;

final class ApiCapabilityController extends Controller
{
    public function index(ReadApiCapabilities $capabilities): JsonResponse
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        abort_unless($companyId > 0 && auth()->check(), 403);

        // Discovery exposes declarations only: no documents, secrets,
        // metadata from unannotated routes, or mutations.
        return response()->json([
            'version' => 1,
            'company_id' => $companyId,
            'routes' => $capabilities->list(),
        ]);
    }
}

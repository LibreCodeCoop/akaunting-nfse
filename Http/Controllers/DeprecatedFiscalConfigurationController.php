<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Compatibility route names remain registered for clear failure semantics.
 * Old mutation endpoints must never recreate certificate-by-CNPJ state.
 */
final class DeprecatedFiscalConfigurationController extends Controller
{
    public function __invoke(): Response
    {
        return response('Legacy NFS-e fiscal configuration is disabled. Use company profiles.', 410);
    }
}

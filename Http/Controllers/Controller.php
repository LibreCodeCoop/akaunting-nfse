<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

abstract class Controller extends \App\Abstracts\Http\Controller
{
    /**
     * Disable Akaunting automatic CRUD permission middleware mapping for this module.
     *
     * The default resolver would require permissions like read-nfse-settings-controller,
     * which are not part of the module ACL contract and resulted in 403.
     */
    public function assignPermissionsToController(): void
    {
    }
}

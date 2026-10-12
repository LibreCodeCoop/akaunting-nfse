<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Contracts;

/**
 * Governmental sphere observed for the payer; not an IRRF or contribution
 * withholding classification without legal nature and applicable rules.
 */
enum FiscalGovernmentSphere: string
{
    case FEDERAL = 'federal';
    case STATE = 'state';
    case DISTRICT = 'district';
    case MUNICIPAL = 'municipal';
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Contracts;

/**
 * Fiscal responsibility, independent of the Akaunting accounting tax type.
 * Unknown/unverified role is null in FiscalTaxFact, never a guessed value.
 */
enum FiscalTaxRole: string
{
    case PROVIDER_DUE = 'provider_due';
    case WITHHELD_BY_TAKER = 'withheld_by_taker';
    case ESTIMATED_DISCLOSURE = 'estimated_disclosure';
}

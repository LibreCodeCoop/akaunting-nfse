<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Contracts;

/**
 * Fiscal responsibility, independent of the Akaunting accounting tax type.
 * The withholding payer need not be the invoiced taker. An unverified
 * role is null in FiscalTaxFact, never a guessed value.
 */
enum FiscalTaxRole: string
{
    case PROVIDER_DUE = 'provider_due';
    case WITHHELD_BY_PAYER = 'withheld_by_payer';
    case ESTIMATED_DISCLOSURE = 'estimated_disclosure';
}

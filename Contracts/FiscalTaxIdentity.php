<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Contracts;

/** A label assigned to an Akaunting tax, not evidence of legal incidence. */
enum FiscalTaxIdentity: string
{
    case ISSQN = 'issqn';
    case PIS = 'pis';
    case COFINS = 'cofins';
    case CSLL = 'csll';
    case IRRF = 'irrf';
    case CP = 'cp';
    case IBS = 'ibs';
    case CBS = 'cbs';
}

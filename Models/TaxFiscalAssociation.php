<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Confirmed identity/role hint for one Akaunting tax in one company and period.
 *
 * This is not an incidence rule, rate schedule, or retroactive change to
 * document_item_taxes. A changed classification needs a new dated revision.
 */
final class TaxFiscalAssociation extends Model
{
    protected $table = 'nfse_tax_fiscal_associations';

    protected $fillable = [
        'company_id',
        'tax_id',
        'effective_from',
        'effective_until',
        'fiscal_identity',
        'fiscal_role',
        'confirmation_kind',
        'source_reference',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'tax_id' => 'integer',
        'confirmed_by' => 'integer',
        'confirmed_at' => 'datetime',
    ];
}

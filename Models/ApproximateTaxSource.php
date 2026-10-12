<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only source revision for consumer-facing approximate tax information.
 * It is not the assessed tax, and changing sources must never edit receipts.
 */
final class ApproximateTaxSource extends Model
{
    protected $table = 'nfse_approximate_tax_sources';

    protected $fillable = [
        'company_id',
        'national_service_code',
        'simples_option',
        'competence_from',
        'competence_until',
        'source_kind',
        'source_name',
        'source_version',
        'federal_percent',
        'state_percent',
        'municipal_percent',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'simples_option' => 'integer',
        'federal_percent' => 'decimal:2',
        'state_percent' => 'decimal:2',
        'municipal_percent' => 'decimal:2',
    ];
}

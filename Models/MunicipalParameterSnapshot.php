<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;

final class MunicipalParameterSnapshot extends Model
{
    protected $table = 'nfse_municipal_parameter_snapshots';

    protected $fillable = [
        'company_id',
        'environment',
        'municipio_ibge',
        'service_code',
        'competence_date',
        'payload',
        'source_provenance',
        'fetched_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'competence_date' => 'date',
        'payload' => 'array',
        'source_provenance' => 'array',
        'fetched_at' => 'datetime',
    ];
}

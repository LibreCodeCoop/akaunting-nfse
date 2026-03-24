<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyService extends Model
{
    protected $table = 'nfse_company_services';

    protected $fillable = [
        'company_id',
        'item_lista_servico',
        'codigo_tributacao_nacional',
        'aliquota',
        'description',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'aliquota' => 'decimal:2',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}

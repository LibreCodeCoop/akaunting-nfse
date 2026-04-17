<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;

class ItemFiscalProfile extends Model
{
    protected $table = 'nfse_item_fiscal_profiles';

    protected $fillable = [
        'company_id',
        'item_id',
        'item_lista_servico',
        'codigo_tributacao_nacional',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'item_id' => 'integer',
    ];
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;

class ItemServiceMapping extends Model
{
    protected $table = 'nfse_item_service_mappings';

    protected $fillable = [
        'company_id',
        'item_id',
        'company_service_id',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'item_id' => 'integer',
        'company_service_id' => 'integer',
    ];
}

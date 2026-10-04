<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;

final class AdnSyncState extends Model
{
    protected $table = 'nfse_adn_sync_states';

    protected $fillable = [
        'company_id',
        'environment',
        'last_nsu',
        'last_successful_at',
        'last_error',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'last_nsu' => 'integer',
        'last_successful_at' => 'datetime',
    ];
}

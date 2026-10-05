<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;

final class BulkEmissionRun extends Model
{
    protected $table = 'nfse_bulk_emission_runs';

    protected $fillable = [
        'company_id',
        'requested_by',
        'status',
        'selection_hash',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'requested_by' => 'integer',
    ];
}

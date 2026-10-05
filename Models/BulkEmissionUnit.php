<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;

final class BulkEmissionUnit extends Model
{
    protected $table = 'nfse_bulk_emission_units';

    protected $fillable = [
        'run_id',
        'invoice_id',
        'emission_group_key',
        'status',
        'receipt_id',
        'error_type',
        'error_message',
    ];

    protected $casts = [
        'run_id' => 'integer',
        'invoice_id' => 'integer',
        'receipt_id' => 'integer',
    ];
}

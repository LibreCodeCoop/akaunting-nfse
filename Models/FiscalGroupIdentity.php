<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;

final class FiscalGroupIdentity extends Model
{
    protected $table = 'nfse_fiscal_group_identities';

    protected $fillable = [
        'invoice_id',
        'group_key',
    ];

    protected $casts = [
        'invoice_id' => 'integer',
    ];
}

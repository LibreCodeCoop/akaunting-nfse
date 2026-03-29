<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NfseReceipt extends Model
{
    protected $table = 'nfse_receipts';

    protected $fillable = [
        'invoice_id',
        'nfse_number',
        'chave_acesso',
        'data_emissao',
        'codigo_verificacao',
        'status',
    ];

    protected $casts = [
        'data_emissao' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Document\Document::class);
    }
}

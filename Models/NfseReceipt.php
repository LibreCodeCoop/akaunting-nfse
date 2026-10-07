<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NfseReceipt extends Model
{
    protected $table = 'nfse_receipts';

    protected $fillable = [
        'invoice_id',
        'nfse_number',
        'chave_acesso',
        'data_emissao',
        'codigo_verificacao',
        'xml_webdav_path',
        'danfse_webdav_path',
        'status',
        'emission_group_key',
        'replaces_receipt_id',
        'competence_date',
        'authorized_fiscal_snapshot',
    ];

    protected $casts = [
        'data_emissao' => 'datetime',
        'competence_date' => 'date',
        'authorized_fiscal_snapshot' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Document\Document::class);
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_receipt_id');
    }

    public function replacement(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_receipt_id');
    }

    public function payload(): HasOne
    {
        return $this->hasOne(NfseReceiptPayload::class, 'receipt_id');
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One operational DPS POST lifecycle; never an authorized fiscal document.
 * The receipt and its XML remain exclusively in NfseReceipt.
 */
final class NfseEmissionAttempt extends Model
{
    protected $table = 'nfse_emission_attempts';

    protected $fillable = [
        'company_id', 'invoice_id', 'emission_group_key', 'dps_identifier',
        'environment', 'attempt_number', 'payload_fingerprint',
        'municipio_ibge', 'codigo_tributacao_nacional',
        'codigo_tributacao_municipal', 'codigo_servico', 'competence_date',
        'origin', 'status', 'official_code', 'official_message',
        'http_status', 'failure_class', 'receipt_id',
        'started_at', 'resolved_at', 'reconciled_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'invoice_id' => 'integer',
        'environment' => 'integer',
        'attempt_number' => 'integer',
        'receipt_id' => 'integer',
        'http_status' => 'integer',
        'competence_date' => 'date',
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
        'reconciled_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Document\Document::class, 'invoice_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(NfseReceipt::class, 'receipt_id');
    }
}

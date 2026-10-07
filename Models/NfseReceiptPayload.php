<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NfseReceiptPayload extends Model
{
    protected $table = 'nfse_receipt_payloads';

    protected $fillable = [
        'receipt_id',
        'authorized_xml',
        'post_emission_email_sent_at',
        'artifacts_status',
        'email_status',
        'post_processing_error',
        'artifacts_completed_at',
    ];

    protected $casts = [
        'post_emission_email_sent_at' => 'datetime',
        'artifacts_completed_at' => 'datetime',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(NfseReceipt::class, 'receipt_id');
    }
}

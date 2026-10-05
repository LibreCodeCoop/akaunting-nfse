<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;

final class AdnSyncDocument extends Model
{
    protected $table = 'nfse_adn_documents';

    protected $fillable = [
        'company_id',
        'environment',
        'document_key',
        'nsu',
        'chave_acesso',
        'tipo_documento',
        'tipo_evento',
        'data_hora_geracao',
        'xml',
        'recognized_event',
        'fiscal_role',
        'review_status',
        'imported_document_id',
        'ignored_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'nsu' => 'integer',
        'recognized_event' => 'boolean',
        'imported_document_id' => 'integer',
        'ignored_at' => 'datetime',
    ];
}

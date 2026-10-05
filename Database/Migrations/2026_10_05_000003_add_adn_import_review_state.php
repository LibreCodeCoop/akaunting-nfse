<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('nfse_adn_documents', function (Blueprint $table): void {
            $table->string('fiscal_role', 20)->default('unknown')->after('recognized_event');
            $table->string('review_status', 20)->default('not_applicable')->after('fiscal_role');
            $table->unsignedBigInteger('imported_document_id')->nullable()->after('review_status');
            $table->timestamp('ignored_at')->nullable()->after('imported_document_id');

            $table->index(
                ['company_id', 'environment', 'review_status'],
                'nfse_adn_review_queue',
            );
            $table->unique(
                ['company_id', 'chave_acesso', 'imported_document_id'],
                'nfse_adn_import_trace',
            );
        });
    }

    public function down(): void
    {
        Schema::table('nfse_adn_documents', function (Blueprint $table): void {
            $table->dropUnique('nfse_adn_import_trace');
            $table->dropIndex('nfse_adn_review_queue');
            $table->dropColumn([
                'fiscal_role',
                'review_status',
                'imported_document_id',
                'ignored_at',
            ]);
        });
    }
};

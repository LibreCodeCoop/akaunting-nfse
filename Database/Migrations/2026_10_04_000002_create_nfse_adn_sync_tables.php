<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_adn_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('environment', 20);
            $table->unsignedBigInteger('last_nsu')->default(0);
            $table->timestamp('last_successful_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'environment']);
        });

        Schema::create('nfse_adn_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('environment', 20);
            $table->string('document_key', 80);
            $table->unsignedBigInteger('nsu')->nullable();
            $table->string('chave_acesso', 80)->nullable()->index();
            $table->string('tipo_documento', 40);
            $table->string('tipo_evento', 20)->nullable();
            $table->string('data_hora_geracao', 50)->nullable();
            $table->longText('xml')->nullable();
            $table->boolean('recognized_event')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'environment', 'document_key'], 'nfse_adn_document_identity');
            $table->index(['company_id', 'environment', 'nsu'], 'nfse_adn_cursor_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_adn_documents');
        Schema::dropIfExists('nfse_adn_sync_states');
    }
};

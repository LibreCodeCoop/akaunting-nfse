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
        Schema::create('nfse_company_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('item_lista_servico', 10); // LC 116 code (e.g., '0107', '6.01')
            $table->string('codigo_tributacao_nacional', 6)->nullable();
            $table->decimal('aliquota', 5, 2); // ISS rate (e.g., 5.00, 2.50)
            $table->string('description', 255)->nullable(); // Optional service description
            $table->boolean('is_default')->default(false); // Mark which service is default for this company
            $table->boolean('is_active')->default(true); // Soft enable/disable per service
            $table->timestamps();
            $table->unique(['company_id', 'item_lista_servico']); // Prevent duplicate LC 116 per company
            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_company_services');
    }
};

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
        Schema::create('nfse_item_fiscal_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->unsignedInteger('item_id')->index();
            $table->string('item_lista_servico', 10)->nullable();
            $table->string('codigo_tributacao_nacional', 6)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'item_id']);

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->onDelete('cascade');

            $table->foreign('item_id')
                ->references('id')
                ->on('items')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_item_fiscal_profiles');
    }
};

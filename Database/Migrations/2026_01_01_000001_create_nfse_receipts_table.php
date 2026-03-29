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
        Schema::create('nfse_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('invoice_id')->unique()->index();
            $table->string('nfse_number', 50)->nullable();
            $table->string('chave_acesso', 255)->nullable()->index();
            $table->datetime('data_emissao')->nullable();
            $table->string('codigo_verificacao', 100)->nullable();
            $table->string('status', 30)->default('pending');
            $table->timestamps();

            $table->foreign('invoice_id')
                ->references('id')
                ->on('documents')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_receipts');
    }
};

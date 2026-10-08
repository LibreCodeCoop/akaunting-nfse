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
        Schema::create('nfse_emission_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedInteger('invoice_id');
            $table->string('emission_group_key', 255)->nullable();
            $table->string('dps_identifier', 64);
            $table->unsignedTinyInteger('environment');
            $table->unsignedInteger('attempt_number');
            $table->char('payload_fingerprint', 64);
            $table->string('municipio_ibge', 7);
            $table->string('codigo_tributacao_nacional', 6);
            $table->string('codigo_tributacao_municipal', 3)->default('');
            $table->string('codigo_servico', 9)->default('');
            $table->date('competence_date')->nullable();
            $table->string('origin', 32);
            $table->string('status', 24);
            $table->string('official_code', 24)->nullable();
            $table->text('official_message')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('failure_class', 32)->nullable();
            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'environment', 'dps_identifier', 'attempt_number'],
                'nfse_emission_attempt_operation_unique',
            );
            $table->index(['company_id', 'invoice_id', 'created_at'], 'nfse_attempts_company_invoice_index');
            $table->index(['company_id', 'status'], 'nfse_attempts_company_status_index');
            $table->foreign('invoice_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreign('receipt_id')->references('id')->on('nfse_receipts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_emission_attempts');
    }
};

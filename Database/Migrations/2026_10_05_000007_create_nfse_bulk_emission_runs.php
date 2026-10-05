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
        Schema::create('nfse_bulk_emission_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->string('status', 24)->default('queued')->index();
            $table->string('selection_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('nfse_bulk_emission_units', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('run_id')->index();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->string('emission_group_key', 255);
            $table->string('status', 32)->default('queued')->index();
            $table->unsignedBigInteger('receipt_id')->nullable()->index();
            $table->string('error_type', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(
                ['run_id', 'invoice_id', 'emission_group_key'],
                'nfse_bulk_emission_unit_unique',
            );

            $table->foreign('run_id')
                ->references('id')
                ->on('nfse_bulk_emission_runs')
                ->onDelete('cascade');

            $table->foreign('invoice_id')
                ->references('id')
                ->on('documents')
                ->onDelete('cascade');

            $table->foreign('receipt_id')
                ->references('id')
                ->on('nfse_receipts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_bulk_emission_units');
        Schema::dropIfExists('nfse_bulk_emission_runs');
    }
};

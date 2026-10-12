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
        Schema::create('nfse_dps_series', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('environment');
            $table->char('issuer_municipality', 7);
            $table->unsignedTinyInteger('issuer_registration_type');
            $table->char('issuer_registration', 14);
            $table->char('series', 5);
            $table->unsignedBigInteger('last_number')->default(0);
            // A row cannot be activated implicitly on a clean installation.
            $table->string('status', 16)->default('inactive');
            $table->string('coordination_mode', 32)->nullable();
            $table->string('coordination_reference', 255)->nullable();
            $table->timestamps();

            $table->unique([
                'environment', 'issuer_municipality', 'issuer_registration_type',
                'issuer_registration', 'series',
            ], 'nfse_dps_series_issuer_unique');
        });

        Schema::create('nfse_dps_reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('series_id');
            $table->unsignedBigInteger('company_id');
            // Akaunting documents.id uses unsigned INT, not BIGINT.
            $table->unsignedInteger('invoice_id');
            $table->char('operation_key', 64);
            $table->unsignedBigInteger('number');
            $table->string('origin', 32);
            $table->timestamps();

            $table->unique('operation_key', 'nfse_dps_reservation_operation_unique');
            $table->unique(['series_id', 'number'], 'nfse_dps_reservation_number_unique');
            $table->index(['company_id', 'invoice_id'], 'nfse_dps_reservation_company_invoice');
            $table->foreign('series_id')->references('id')->on('nfse_dps_series')->restrictOnDelete();
            $table->foreign('invoice_id')->references('id')->on('documents')->restrictOnDelete();
        });

        Schema::table('nfse_emission_attempts', function (Blueprint $table): void {
            // Historical attempts remain valid with null linkage until
            // existing emitters are migrated through the new reservation API.
            $table->unsignedBigInteger('dps_reservation_id')->nullable();
            $table->foreign('dps_reservation_id')
                ->references('id')->on('nfse_dps_reservations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nfse_emission_attempts', function (Blueprint $table): void {
            $table->dropForeign(['dps_reservation_id']);
            $table->dropColumn('dps_reservation_id');
        });

        Schema::dropIfExists('nfse_dps_reservations');
        Schema::dropIfExists('nfse_dps_series');
    }
};

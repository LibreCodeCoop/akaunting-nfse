<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One Akaunting database holds both invoices and the immutable DPS authority.
 * The authority uses a second PDO connection to commit before local journal
 * changes, not a second schema, MySQL server or Akaunting installation.
 */
return new class () extends Migration {
    public function up(): void
    {
        // SQLite's disposable feature harness uses a synthetic coordinator;
        // real issuance is supported only on transactional MySQL.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (!Schema::hasTable('nfse_dps_coordination_series')) {
            Schema::create('nfse_dps_coordination_series', function (Blueprint $table): void {
                $table->id();
                $table->unsignedTinyInteger('environment');
                $table->char('issuer_municipality', 7);
                $table->unsignedTinyInteger('issuer_registration_type');
                $table->char('issuer_registration', 14);
                $table->char('series', 5);
                $table->char('coordination_reference', 64);
                $table->string('status', 16)->default('inactive');
                $table->unsignedBigInteger('attested_floor');
                $table->char('evidence_sha256', 64);
                $table->unsignedBigInteger('last_number');
                $table->unique([
                    'environment', 'issuer_municipality', 'issuer_registration_type',
                    'issuer_registration', 'series',
                ], 'nfse_dps_global_issuer');
            });
        }

        if (!Schema::hasTable('nfse_dps_coordination_members')) {
            Schema::create('nfse_dps_coordination_members', function (Blueprint $table): void {
                $table->unsignedBigInteger('series_id');
                $table->char('installation_id', 36);
                $table->unsignedBigInteger('generation');
                $table->string('status', 16)->default('inactive');
                $table->primary(['series_id', 'installation_id']);
                $table->foreign('series_id', 'nfse_dps_global_members_series_fk')
                    ->references('id')->on('nfse_dps_coordination_series')->restrictOnDelete();
            });
        }

        if (!Schema::hasTable('nfse_dps_coordination_allocations')) {
            Schema::create('nfse_dps_coordination_allocations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('series_id');
                $table->char('installation_id', 36);
                $table->char('operation_key', 64);
                $table->unsignedBigInteger('number');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('first_post_claimed_at', 6)->nullable();
                $table->unsignedBigInteger('claimed_generation')->nullable();
                $table->char('first_post_fingerprint', 64)->nullable();
                $table->unique(['series_id', 'number'], 'nfse_dps_global_fiscal_number');
                $table->unique(['installation_id', 'operation_key'], 'nfse_dps_global_operation');
                $table->foreign(['series_id', 'installation_id'], 'nfse_dps_global_allocation_member_fk')
                    ->references(['series_id', 'installation_id'])
                    ->on('nfse_dps_coordination_members')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('nfse_dps_coordination_series')) {
            return;
        }

        // Even an inactive series is historical fiscal evidence; never erase
        // coordination state through an automatic migration rollback.
        foreach ([
            'nfse_dps_coordination_allocations',
            'nfse_dps_coordination_members',
            'nfse_dps_coordination_series',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new \LogicException('Cannot roll back DPS authority with preserved fiscal history.');
            }
        }

        Schema::dropIfExists('nfse_dps_coordination_allocations');
        Schema::dropIfExists('nfse_dps_coordination_members');
        Schema::dropIfExists('nfse_dps_coordination_series');
    }
};

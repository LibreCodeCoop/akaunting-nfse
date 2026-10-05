<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('nfse_bulk_emission_runs')) {
            Schema::create('nfse_bulk_emission_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('requested_by')->nullable()->index();
                $table->string('status', 24)->default('queued')->index();
                $table->string('selection_hash', 64)->index();
                $table->timestamps();
            });
        }

        $mysql = DB::connection()->getDriverName() === 'mysql';

        if (!Schema::hasTable('nfse_bulk_emission_units')) {
            Schema::create('nfse_bulk_emission_units', function (Blueprint $table) use ($mysql): void {
                $table->id();
                $table->unsignedBigInteger('run_id')->index();
                // Akaunting documents.id is an unsigned INT, not a BIGINT.
                $table->unsignedInteger('invoice_id')->index();
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

                // SQLite requires foreign keys in the CREATE TABLE statement.
                if (!$mysql) {
                    $this->addForeignKeys($table);
                }
            });
        }

        if (!$mysql) {
            return;
        }

        // Recover a previous MySQL attempt that created the tables but failed
        // while adding the invoice foreign key. Keep any existing rows.
        $foreignKeys = array_column(Schema::getForeignKeys('nfse_bulk_emission_units'), 'name');
        $invoiceForeign = 'nfse_bulk_emission_units_invoice_id_foreign';

        if (!in_array($invoiceForeign, $foreignKeys, true)) {
            if (DB::table('nfse_bulk_emission_units')->where('invoice_id', '>', 4294967295)->exists()) {
                throw new RuntimeException('NFS-e bulk invoice IDs exceed the Akaunting unsigned INT range.');
            }

            Schema::table('nfse_bulk_emission_units', function (Blueprint $table): void {
                $table->unsignedInteger('invoice_id')->change();
            });
        }

        foreach (['run_id', 'invoice_id', 'status', 'receipt_id'] as $column) {
            if (!Schema::hasIndex('nfse_bulk_emission_units', 'nfse_bulk_emission_units_' . $column . '_index')) {
                Schema::table('nfse_bulk_emission_units', function (Blueprint $table) use ($column): void {
                    $table->index($column);
                });
            }
        }

        Schema::table('nfse_bulk_emission_units', function (Blueprint $table) use ($foreignKeys): void {
            $this->addForeignKeys($table, $foreignKeys);
        });
    }

    /** @param list<string> $existing */
    private function addForeignKeys(Blueprint $table, array $existing = []): void
    {
        if (!in_array('nfse_bulk_emission_units_run_id_foreign', $existing, true)) {
            $table->foreign('run_id')->references('id')->on('nfse_bulk_emission_runs')->onDelete('cascade');
        }

        if (!in_array('nfse_bulk_emission_units_invoice_id_foreign', $existing, true)) {
            $table->foreign('invoice_id')->references('id')->on('documents')->onDelete('cascade');
        }

        if (!in_array('nfse_bulk_emission_units_receipt_id_foreign', $existing, true)) {
            $table->foreign('receipt_id')->references('id')->on('nfse_receipts')->nullOnDelete();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_bulk_emission_units');
        Schema::dropIfExists('nfse_bulk_emission_runs');
    }
};

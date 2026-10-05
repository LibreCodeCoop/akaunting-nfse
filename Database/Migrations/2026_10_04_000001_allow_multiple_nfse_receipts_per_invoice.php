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
        // MySQL may use the unique index to support the invoice foreign key.
        // Create its replacement in a separate ALTER before dropping it.
        if (!Schema::hasIndex('nfse_receipts', 'nfse_receipts_invoice_id_index')) {
            Schema::table('nfse_receipts', function (Blueprint $table): void {
                $table->index('invoice_id');
            });
        }

        // DDL is not transactional on MySQL: a failed attempt can leave the
        // columns behind without their indexes or a migration history entry.
        if (!Schema::hasColumn('nfse_receipts', 'emission_group_key')) {
            Schema::table('nfse_receipts', function (Blueprint $table): void {
                $table->string('emission_group_key', 100)->nullable();
            });
        }

        if (!Schema::hasColumn('nfse_receipts', 'replaces_receipt_id')) {
            Schema::table('nfse_receipts', function (Blueprint $table): void {
                $table->unsignedBigInteger('replaces_receipt_id')->nullable();
            });
        }

        foreach (['emission_group_key', 'replaces_receipt_id'] as $column) {
            if (!Schema::hasIndex('nfse_receipts', 'nfse_receipts_' . $column . '_index')) {
                Schema::table('nfse_receipts', function (Blueprint $table) use ($column): void {
                    $table->index($column);
                });
            }
        }

        if (Schema::hasIndex('nfse_receipts', 'nfse_receipts_invoice_id_unique')) {
            Schema::table('nfse_receipts', function (Blueprint $table): void {
                $table->dropUnique(['invoice_id']);
            });
        }
    }

    public function down(): void
    {
        // Validate uniqueness before removing columns: rollback must fail
        // without losing group metadata when an invoice has multiple receipts.
        Schema::table('nfse_receipts', function (Blueprint $table): void {
            $table->unique('invoice_id');
        });

        Schema::table('nfse_receipts', function (Blueprint $table): void {
            $table->dropIndex(['emission_group_key']);
            $table->dropIndex(['replaces_receipt_id']);
            $table->dropColumn(['emission_group_key', 'replaces_receipt_id']);
        });
    }
};

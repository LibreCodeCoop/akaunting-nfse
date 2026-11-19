<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nfse_receipts')) {
            return;
        }

        // A historical PDF can predate the adoption of Akaunting. Do not
        // manufacture an accounting invoice merely to catalogue the NFS-e.
        $foreignKeys = $this->invoiceForeignKeys();
        if ($foreignKeys !== []) {
            Schema::table('nfse_receipts', static function (Blueprint $table) use ($foreignKeys): void {
                foreach ($foreignKeys as $foreignKey) {
                    $table->dropForeign($foreignKey);
                }
            });
        }

        Schema::table('nfse_receipts', static function (Blueprint $table): void {
            $table->unsignedInteger('invoice_id')->nullable()->change();
        });

        foreach ([
            'company_id' => static fn (Blueprint $table) => $table->unsignedInteger('company_id')->nullable(),
            'archive_page_number' => static fn (Blueprint $table) => $table->unsignedSmallInteger('archive_page_number')->nullable(),
            'archive_pdf_sha256' => static fn (Blueprint $table) => $table->string('archive_pdf_sha256', 64)->nullable(),
            'archive_evidence' => static fn (Blueprint $table) => $table->json('archive_evidence')->nullable(),
        ] as $column => $add) {
            if (!Schema::hasColumn('nfse_receipts', $column)) {
                Schema::table('nfse_receipts', $add);
            }
        }

        if (!Schema::hasColumn('nfse_receipts', 'archive_identity')) {
            Schema::table('nfse_receipts', static fn (Blueprint $table) => $table->string('archive_identity', 64)->nullable());
        }
        if (!Schema::hasColumn('nfse_receipts', 'recipient_contact_id')) {
            Schema::table('nfse_receipts', static fn (Blueprint $table) => $table->unsignedInteger('recipient_contact_id')->nullable());
        }
        if (!Schema::hasIndex('nfse_receipts', 'nfse_receipts_archive_identity_unique')) {
            Schema::table('nfse_receipts', static fn (Blueprint $table) => $table->unique('archive_identity', 'nfse_receipts_archive_identity_unique'));
        }
        if (!Schema::hasIndex('nfse_receipts', 'nfse_receipts_recipient_contact_id_index')) {
            Schema::table('nfse_receipts', static fn (Blueprint $table) => $table->index('recipient_contact_id', 'nfse_receipts_recipient_contact_id_index'));
        }

        if (!Schema::hasIndex('nfse_receipts', 'nfse_receipts_company_id_index')) {
            Schema::table('nfse_receipts', static fn (Blueprint $table) => $table->index('company_id'));
        }

        // Preserve tenant identity for all existing fiscal receipts without
        // assuming a global company. Historical records set it explicitly.
        DB::table('nfse_receipts')
            ->whereNotNull('invoice_id')
            ->whereNull('company_id')
            ->orderBy('id')
            ->chunkById(200, static function ($receipts): void {
                $companies = DB::table('documents')
                    ->whereIn('id', $receipts->pluck('invoice_id')->all())
                    ->pluck('company_id', 'id');
                foreach ($receipts as $receipt) {
                    if (isset($companies[$receipt->invoice_id])) {
                        DB::table('nfse_receipts')->where('id', $receipt->id)
                            ->update(['company_id' => (int) $companies[$receipt->invoice_id]]);
                    }
                }
            });

        if ($this->invoiceForeignKeys() === []) {
            Schema::table('nfse_receipts', static function (Blueprint $table): void {
                $table->foreign('invoice_id')
                    ->references('id')
                    ->on('documents')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('nfse_receipts')) {
            return;
        }

        if (DB::table('nfse_receipts')->whereNull('invoice_id')->exists()) {
            throw new RuntimeException('Historical NFS-e records must be migrated before reverting this schema.');
        }

        $foreignKeys = $this->invoiceForeignKeys();
        if ($foreignKeys !== []) {
            Schema::table('nfse_receipts', static function (Blueprint $table) use ($foreignKeys): void {
                foreach ($foreignKeys as $foreignKey) {
                    $table->dropForeign($foreignKey);
                }
            });
        }

        Schema::table('nfse_receipts', static function (Blueprint $table): void {
            $table->unsignedInteger('invoice_id')->nullable(false)->change();
            $table->dropIndex('nfse_receipts_archive_identity_unique');
            $table->dropIndex('nfse_receipts_recipient_contact_id_index');
            $table->dropIndex('nfse_receipts_company_id_index');
            $table->dropColumn(['company_id', 'archive_page_number', 'archive_pdf_sha256', 'archive_evidence', 'archive_identity', 'recipient_contact_id']);
        });

        Schema::table('nfse_receipts', static function (Blueprint $table): void {
            $table->foreign('invoice_id')
                ->references('id')
                ->on('documents')
                ->onDelete('cascade');
        });
    }

    /**
     * @return list<string>
     */
    private function invoiceForeignKeys(): array
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return [];
        }

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'nfse_receipts')
            ->where('COLUMN_NAME', 'invoice_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->all();
    }
};

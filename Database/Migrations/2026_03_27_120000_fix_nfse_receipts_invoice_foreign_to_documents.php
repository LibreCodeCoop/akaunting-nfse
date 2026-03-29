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

        $constraints = $this->foreignConstraintsForInvoiceId();

        if (!empty($constraints)) {
            Schema::table('nfse_receipts', function (Blueprint $table) use ($constraints): void {
                foreach ($constraints as $constraint) {
                    $table->dropForeign($constraint['constraint_name']);
                }
            });
        }

        Schema::table('nfse_receipts', function (Blueprint $table): void {
            $table->foreign('invoice_id')
                ->references('id')
                ->on('documents')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('nfse_receipts')) {
            return;
        }

        $constraints = $this->foreignConstraintsForInvoiceId();

        if (!empty($constraints)) {
            Schema::table('nfse_receipts', function (Blueprint $table) use ($constraints): void {
                foreach ($constraints as $constraint) {
                    $table->dropForeign($constraint['constraint_name']);
                }
            });
        }

        Schema::table('nfse_receipts', function (Blueprint $table): void {
            $table->foreign('invoice_id')
                ->references('id')
                ->on('invoices')
                ->onDelete('cascade');
        });
    }

    /**
     * @return array<int, array{constraint_name: string, referenced_table_name: string}>
     */
    private function foreignConstraintsForInvoiceId(): array
    {
        $rows = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->select(['CONSTRAINT_NAME as constraint_name', 'REFERENCED_TABLE_NAME as referenced_table_name'])
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'nfse_receipts')
            ->where('COLUMN_NAME', 'invoice_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->get();

        $constraints = [];

        foreach ($rows as $row) {
            $name = (string) ($row->constraint_name ?? '');
            $table = (string) ($row->referenced_table_name ?? '');

            if ($name === '' || $table === '') {
                continue;
            }

            if (!in_array($table, ['documents', 'invoices'], true)) {
                continue;
            }

            $constraints[] = [
                'constraint_name' => $name,
                'referenced_table_name' => $table,
            ];
        }

        return $constraints;
    }
};

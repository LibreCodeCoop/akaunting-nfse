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
        if (Schema::hasColumn('nfse_item_fiscal_profiles', 'codigo_tributacao_municipal')) {
            return;
        }

        Schema::table('nfse_item_fiscal_profiles', function (Blueprint $table): void {
            $table->string('codigo_tributacao_municipal', 3)
                ->nullable()
                ->after('codigo_tributacao_nacional');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('nfse_item_fiscal_profiles', 'codigo_tributacao_municipal')) {
            return;
        }

        Schema::table('nfse_item_fiscal_profiles', function (Blueprint $table): void {
            $table->dropColumn('codigo_tributacao_municipal');
        });
    }
};

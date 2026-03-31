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
        if (!Schema::hasTable('nfse_company_services')) {
            return;
        }

        if (Schema::hasColumn('nfse_company_services', 'codigo_tributacao_nacional')) {
            return;
        }

        Schema::table('nfse_company_services', function (Blueprint $table): void {
            $table->string('codigo_tributacao_nacional', 6)
                ->nullable()
                ->after('item_lista_servico');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('nfse_company_services')) {
            return;
        }

        if (!Schema::hasColumn('nfse_company_services', 'codigo_tributacao_nacional')) {
            return;
        }

        Schema::table('nfse_company_services', function (Blueprint $table): void {
            $table->dropColumn('codigo_tributacao_nacional');
        });
    }
};

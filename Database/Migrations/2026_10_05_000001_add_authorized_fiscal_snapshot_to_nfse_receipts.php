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
        Schema::table('nfse_receipts', function (Blueprint $table): void {
            $table->date('competence_date')->nullable()->after('data_emissao');
            $table->text('authorized_fiscal_snapshot')->nullable()->after('competence_date');
        });
    }

    public function down(): void
    {
        Schema::table('nfse_receipts', function (Blueprint $table): void {
            $table->dropColumn(['competence_date', 'authorized_fiscal_snapshot']);
        });
    }
};

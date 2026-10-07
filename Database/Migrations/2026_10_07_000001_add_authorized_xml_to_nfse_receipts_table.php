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
        Schema::table('nfse_receipts', function (Blueprint $table): void {
            $table->longText('authorized_xml')->nullable()->after('codigo_verificacao');
        });
    }

    public function down(): void
    {
        Schema::table('nfse_receipts', function (Blueprint $table): void {
            $table->dropColumn('authorized_xml');
        });
    }
};

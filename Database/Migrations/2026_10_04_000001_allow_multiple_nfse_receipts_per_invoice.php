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
            $table->dropUnique(['invoice_id']);
            $table->string('emission_group_key', 100)->nullable()->index();
            $table->unsignedBigInteger('replaces_receipt_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('nfse_receipts', function (Blueprint $table): void {
            $table->dropIndex(['emission_group_key']);
            $table->dropIndex(['replaces_receipt_id']);
            $table->dropColumn(['emission_group_key', 'replaces_receipt_id']);
            $table->unique('invoice_id');
        });
    }
};

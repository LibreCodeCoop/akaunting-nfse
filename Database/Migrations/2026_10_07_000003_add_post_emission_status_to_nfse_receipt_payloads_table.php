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
        Schema::table('nfse_receipt_payloads', function (Blueprint $table): void {
            $table->string('artifacts_status', 20)->nullable()->after('authorized_xml');
            $table->string('email_status', 20)->nullable()->after('artifacts_status');
            $table->text('post_processing_error')->nullable()->after('email_status');
            $table->timestamp('artifacts_completed_at')->nullable()->after('post_processing_error');
        });
    }

    public function down(): void
    {
        Schema::table('nfse_receipt_payloads', function (Blueprint $table): void {
            $table->dropColumn([
                'artifacts_status',
                'email_status',
                'post_processing_error',
                'artifacts_completed_at',
            ]);
        });
    }
};

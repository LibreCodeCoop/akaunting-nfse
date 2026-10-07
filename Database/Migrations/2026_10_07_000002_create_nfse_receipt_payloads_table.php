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
        Schema::create('nfse_receipt_payloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->unique();
            $table->longText('authorized_xml')->nullable();
            $table->timestamp('post_emission_email_sent_at')->nullable();
            $table->timestamps();

            $table->foreign('receipt_id')
                ->references('id')
                ->on('nfse_receipts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_receipt_payloads');
    }
};

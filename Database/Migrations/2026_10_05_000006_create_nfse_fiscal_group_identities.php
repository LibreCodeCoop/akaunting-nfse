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
        Schema::create('nfse_fiscal_group_identities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('group_key', 100);
            $table->timestamps();

            $table->unique(
                ['invoice_id', 'group_key'],
                'nfse_fiscal_group_identity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_fiscal_group_identities');
    }
};

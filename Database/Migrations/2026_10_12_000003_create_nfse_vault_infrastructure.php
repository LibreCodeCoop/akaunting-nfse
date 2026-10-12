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
        Schema::create('nfse_vault_infrastructure', function (Blueprint $table): void {
            // One encrypted configuration shared by all Akaunting companies.
            $table->unsignedTinyInteger('id')->primary();
            $table->text('encrypted_payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_vault_infrastructure');
    }
};

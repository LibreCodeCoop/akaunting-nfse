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
        Schema::create('nfse_category_issuance_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('category_id');
            $table->string('mode', 32);
            $table->text('justification');
            $table->unsignedInteger('actor_id');
            $table->timestamp('recorded_at');
            $table->index(['company_id', 'category_id', 'id'], 'nfse_category_issuance_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_category_issuance_policies');
    }
};

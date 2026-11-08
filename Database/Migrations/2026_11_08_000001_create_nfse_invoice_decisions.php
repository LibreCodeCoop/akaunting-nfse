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
        Schema::create('nfse_invoice_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('invoice_id');
            $table->string('decision', 32);
            $table->string('reason', 64);
            $table->text('justification');
            $table->string('evidence_reference', 255)->nullable();
            $table->unsignedInteger('actor_id');
            $table->timestamp('recorded_at');
            $table->index(['company_id', 'invoice_id', 'id'], 'nfse_invoice_decisions_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_invoice_decisions');
    }
};

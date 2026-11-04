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
        Schema::create('nfse_adn_review_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('adn_document_id');
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('action', 20);
            $table->string('reason', 60)->nullable();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->timestamps();

            $table->index(
                ['company_id', 'adn_document_id', 'id'],
                'nfse_adn_review_events_by_company_document',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_adn_review_events');
    }
};

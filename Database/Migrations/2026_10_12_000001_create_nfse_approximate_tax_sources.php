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
        Schema::create('nfse_approximate_tax_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('national_service_code', 6);
            $table->unsignedTinyInteger('simples_option');
            $table->date('competence_from');
            $table->date('competence_until')->nullable();
            $table->string('source_kind', 32);
            $table->string('source_name', 160);
            $table->string('source_version', 80);
            $table->string('source_reference', 255);
            $table->decimal('federal_percent', 5, 2);
            $table->decimal('state_percent', 5, 2);
            $table->decimal('municipal_percent', 5, 2);
            $table->timestamps();

            $table->unique(
                ['company_id', 'national_service_code', 'simples_option', 'competence_from', 'source_version'],
                'nfse_approximate_tax_source_revision_unique',
            );
            $table->index(
                ['company_id', 'national_service_code', 'simples_option', 'competence_from'],
                'nfse_approximate_tax_source_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_approximate_tax_sources');
    }
};

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
        Schema::create('nfse_tax_fiscal_associations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('tax_id');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('fiscal_identity', 16);
            $table->string('fiscal_role', 32);
            // Classification provenance is not proof of legal incidence.
            $table->string('confirmation_kind', 32);
            $table->string('source_reference', 255);
            $table->unsignedInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'tax_id', 'effective_from'],
                'nfse_tax_fiscal_assoc_revision_unique',
            );
            $table->index(
                ['company_id', 'tax_id', 'effective_from', 'effective_until'],
                'nfse_tax_fiscal_assoc_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_tax_fiscal_associations');
    }
};

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
        Schema::create('nfse_fiscal_event_evidence', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('invoice_id');
            $table->string('fiscal_group_key', 191);
            $table->date('taxable_event_date');
            $table->string('evidence_kind', 32);
            $table->string('source_reference', 255);
            $table->unsignedInteger('attested_by');
            $table->timestamp('attested_at');

            // Append-only attestations preserve previous evidence. A later
            // correction never rewrites a previous fiscal attempt or receipt.
            $table->index(
                ['company_id', 'invoice_id', 'fiscal_group_key', 'id'],
                'nfse_fiscal_event_evidence_lookup',
            );
        });

        Schema::table('nfse_emission_attempts', function (Blueprint $table): void {
            $table->unsignedBigInteger('fiscal_event_evidence_id')->nullable();
            $table->json('fiscal_assessment_summary')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nfse_emission_attempts', function (Blueprint $table): void {
            $table->dropColumn(['fiscal_event_evidence_id', 'fiscal_assessment_summary']);
        });
        Schema::dropIfExists('nfse_fiscal_event_evidence');
    }
};

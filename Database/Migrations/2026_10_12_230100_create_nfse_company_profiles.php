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
        Schema::create('nfse_company_profiles', function (Blueprint $table): void {
            $table->id();
            // Company is owned by Akaunting; a DB constraint also rejects concurrent duplicates.
            $table->unsignedInteger('company_id')->unique();
            $table->string('status', 24)->default('provisioning');
            $table->char('certificate_ref', 32)->nullable();
            $table->char('staged_certificate_ref', 32)->nullable();
            $table->string('certificate_cnpj', 14)->nullable();
            $table->char('certificate_fingerprint_sha256', 64)->nullable();
            $table->unsignedBigInteger('certificate_valid_from')->nullable();
            $table->unsignedBigInteger('certificate_valid_to')->nullable();
            $table->json('fiscal_parameters')->nullable();
            $table->timestamps();
        });

        // Retired secrets remain traceable until physical Vault/filesystem cleanup succeeds.
        // No fiscal receipt or DPS identity has a cascade reference to these tables.
        Schema::create('nfse_company_certificate_retirements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->char('certificate_ref', 32);
            $table->timestamps();
            $table->unique(['company_id', 'certificate_ref'], 'nfse_company_certificate_retirement_unique');
        });
    }

    public function down(): void
    {
        // Rolling back profile schema while certificates are provisioned would
        // orphan tenant secrets and erase their cleanup references.
        if (Schema::hasTable('nfse_company_profiles')
            && \Illuminate\Support\Facades\DB::table('nfse_company_profiles')->exists()
        ) {
            throw new \LogicException('Cannot roll back provisioned NFS-e company profiles.');
        }

        if (Schema::hasTable('nfse_company_certificate_retirements')
            && \Illuminate\Support\Facades\DB::table('nfse_company_certificate_retirements')->exists()
        ) {
            throw new \LogicException('Cannot roll back pending certificate retirements.');
        }

        Schema::dropIfExists('nfse_company_certificate_retirements');
        Schema::dropIfExists('nfse_company_profiles');
    }
};

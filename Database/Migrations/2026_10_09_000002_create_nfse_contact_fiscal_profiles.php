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
        Schema::create('nfse_contact_fiscal_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('municipal_registration', 40)->nullable();
            $table->string('legal_name', 255)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'contact_id'], 'nfse_contact_fiscal_company_contact_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_contact_fiscal_profiles');
    }
};

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
        Schema::create('nfse_municipal_parameter_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('environment', 20);
            $table->string('municipio_ibge', 7);
            $table->string('service_code', 20);
            $table->date('competence_date');
            $table->json('payload');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(
                ['company_id', 'environment', 'municipio_ibge', 'service_code', 'competence_date'],
                'nfse_municipal_parameter_snapshot_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_municipal_parameter_snapshots');
    }
};

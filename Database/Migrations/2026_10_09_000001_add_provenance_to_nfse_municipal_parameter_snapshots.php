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
        if (Schema::hasColumn('nfse_municipal_parameter_snapshots', 'source_provenance')) {
            return;
        }

        Schema::table('nfse_municipal_parameter_snapshots', function (Blueprint $table): void {
            // Additive and nullable: legacy snapshots remain consultative and
            // must never acquire invented HTTP or API-version metadata.
            $table->json('source_provenance')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('nfse_municipal_parameter_snapshots', 'source_provenance')) {
            return;
        }

        Schema::table('nfse_municipal_parameter_snapshots', function (Blueprint $table): void {
            $table->dropColumn('source_provenance');
        });
    }
};

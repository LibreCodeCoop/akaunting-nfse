<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || Schema::hasTable('nfse_dps_number_adjustments')) {
            return;
        }

        Schema::create('nfse_dps_number_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('series_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('previous_last_number');
            $table->unsignedBigInteger('new_last_number');
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('series_id', 'nfse_dps_adjustments_series_fk')
                ->references('id')->on('nfse_dps_coordination_series')->restrictOnDelete();
            $table->index(['series_id', 'created_at'], 'nfse_dps_adjustments_series_history');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('nfse_dps_number_adjustments')
            && DB::table('nfse_dps_number_adjustments')->exists()) {
            throw new \LogicException('Cannot erase DPS numbering audit history.');
        }
        Schema::dropIfExists('nfse_dps_number_adjustments');
    }
};

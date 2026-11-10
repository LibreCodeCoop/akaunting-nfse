<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_LIMIT = 191;

    public function up(): void
    {
        // WebDAV paths are unindexed and can be longer than the legacy VARCHAR(191).
        // TEXT preserves every existing value and accommodates long Nextcloud paths.
        Schema::table('nfse_receipts', static function (Blueprint $table): void {
            $table->text('xml_webdav_path')->nullable()->change();
            $table->text('danfse_webdav_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        // MySQL may silently truncate values in permissive SQL modes. Never
        // narrow an existing column if any archived location would be lost.
        foreach (['xml_webdav_path', 'danfse_webdav_path'] as $column) {
            if (DB::table('nfse_receipts')
                ->whereRaw('CHAR_LENGTH(' . $column . ') > ?', [self::LEGACY_LIMIT])
                ->exists()) {
                throw new LogicException(
                    'Cannot narrow NFS-e WebDAV paths while values exceed 191 characters.',
                );
            }
        }

        Schema::table('nfse_receipts', static function (Blueprint $table): void {
            $table->string('xml_webdav_path', self::LEGACY_LIMIT)->nullable()->change();
            $table->string('danfse_webdav_path', self::LEGACY_LIMIT)->nullable()->change();
        });
    }
};

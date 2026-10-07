<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Database\Migrations;

use Modules\Nfse\Tests\TestCase;

final class PostEmissionStatusMigrationTest extends TestCase
{
    public function testMigrationAddsPostEmissionStatusColumns(): void
    {
        $content = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Database/Migrations/2026_10_07_000003_add_post_emission_status_to_nfse_receipt_payloads_table.php',
        );

        self::assertStringContainsString("\$table->string('artifacts_status', 20)->nullable()", $content);
        self::assertStringContainsString("\$table->string('email_status', 20)->nullable()", $content);
        self::assertStringContainsString("\$table->text('post_processing_error')->nullable()", $content);
        self::assertStringContainsString("\$table->timestamp('artifacts_completed_at')->nullable()", $content);
    }
}

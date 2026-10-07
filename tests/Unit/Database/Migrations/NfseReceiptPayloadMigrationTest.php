<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Database\Migrations;

use Modules\Nfse\Tests\TestCase;

final class NfseReceiptPayloadMigrationTest extends TestCase
{
    public function testMigrationKeepsLargeFiscalPayloadOutsideReceiptHotRow(): void
    {
        $content = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Database/Migrations/2026_10_07_000002_create_nfse_receipt_payloads_table.php',
        );

        self::assertStringContainsString("Schema::create('nfse_receipt_payloads'", $content);
        self::assertStringContainsString("\$table->foreignId('receipt_id')->unique()", $content);
        self::assertStringContainsString("\$table->longText('authorized_xml')->nullable()", $content);
        self::assertStringContainsString("\$table->timestamp('post_emission_email_sent_at')->nullable()", $content);
        self::assertStringContainsString('->cascadeOnDelete()', $content);
    }
}

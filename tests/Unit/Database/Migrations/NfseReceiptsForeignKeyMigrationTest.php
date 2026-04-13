<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Database\Migrations;

use PHPUnit\Framework\TestCase;

final class NfseReceiptsForeignKeyMigrationTest extends TestCase
{
    public function testCreateMigrationReferencesDocumentsTable(): void
    {
        $content = file_get_contents(__DIR__ . '/../../../../Database/Migrations/2026_01_01_000001_create_nfse_receipts_table.php');

        self::assertIsString($content);
        self::assertStringContainsString("->on('documents')", $content);
        self::assertStringNotContainsString("->on('invoices')", $content);
    }

    public function testFixMigrationTargetsDocumentsOnUpAndInvoicesOnDown(): void
    {
        $content = file_get_contents(__DIR__ . '/../../../../Database/Migrations/2026_03_27_120000_fix_nfse_receipts_invoice_foreign_to_documents.php');

        self::assertIsString($content);
        self::assertStringContainsString("->on('documents')", $content);
        self::assertStringContainsString("->on('invoices')", $content);
        self::assertStringContainsString('information_schema.KEY_COLUMN_USAGE', $content);
    }

    public function testArtifactPathsMigrationAddsXmlAndDanfseColumns(): void
    {
        $content = file_get_contents(__DIR__ . '/../../../../Database/Migrations/2026_04_13_000001_add_artifact_paths_to_nfse_receipts_table.php');

        self::assertIsString($content);
        self::assertStringContainsString("\$table->string('xml_webdav_path')->nullable();", $content);
        self::assertStringContainsString("\$table->string('danfse_webdav_path')->nullable();", $content);
        self::assertStringContainsString("\$table->dropColumn(['xml_webdav_path', 'danfse_webdav_path']);", $content);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Database\Migrations;

use Modules\Nfse\Tests\TestCase;

final class AuthorizedXmlMigrationTest extends TestCase
{
    public function testMigrationAddsDurableAuthorizedXmlColumn(): void
    {
        $content = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Database/Migrations/2026_10_07_000001_add_authorized_xml_to_nfse_receipts_table.php',
        );

        self::assertStringContainsString("\$table->longText('authorized_xml')->nullable()", $content);
        self::assertStringContainsString("\$table->dropColumn('authorized_xml')", $content);
    }
}

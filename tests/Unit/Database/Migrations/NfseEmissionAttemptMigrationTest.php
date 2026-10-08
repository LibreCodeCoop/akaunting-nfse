<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Database\Migrations;

use Modules\Nfse\Tests\TestCase;

final class NfseEmissionAttemptMigrationTest extends TestCase
{
    public function testMigrationDefinesIndependentAttemptsScopedByCompanyAndDps(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Database/Migrations/2026_10_08_000002_create_nfse_emission_attempts.php',
        );

        self::assertStringContainsString("Schema::create('nfse_emission_attempts'", $migration);
        self::assertStringContainsString("'dps_identifier'", $migration);
        self::assertStringContainsString("'attempt_number'", $migration);
        self::assertStringContainsString("'company_id', 'environment', 'dps_identifier', 'attempt_number'", $migration);
        self::assertStringContainsString("->foreign('invoice_id')", $migration);
        self::assertStringContainsString("->foreign('receipt_id')", $migration);
        self::assertStringNotContainsString("authorized_xml", $migration);
        self::assertStringNotContainsString("chave_acesso", $migration);
    }
}

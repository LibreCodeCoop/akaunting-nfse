<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

final class SettingsFrontendModuleTest extends TestCase
{
    public function testAdministrativeViewUsesExistingAkauntingShellAndSafeBootstrap(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 3) . '/Resources/views/settings/edit.blade.php');
        self::assertStringContainsString('<x-layouts.admin>', $blade);
        self::assertStringContainsString('id="nfse-company-admin-config"', $blade);
        self::assertStringContainsString('data-nfse-company-admin="true"', $blade);
        self::assertStringContainsString('id="profiles-body"', $blade);
        self::assertStringContainsString('id="vault-diagnostics"', $blade);
        self::assertStringContainsString('id="profile-pfx"', $blade);
        self::assertStringContainsString('id="profile-company"', $blade);
        self::assertStringContainsString("route('nfse.settings.company-profiles.index')", $blade);
        self::assertStringContainsString("route('nfse.settings.company-profiles.cleanup'", $blade);
        self::assertStringNotContainsString('id="tab-panel-certificate"', $blade);
        self::assertStringNotContainsString('id="nfse-readiness-summary"', $blade);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

final class SettingsFrontendModuleTest extends TestCase
{
    public function testSettingsBladeContainsOnlyConfigurationBootstrapForStatefulFrontendLogic(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Resources/views/settings/edit.blade.php'
        );

        self::assertStringContainsString('data-nfse-settings-module="true"', $blade);
        self::assertStringContainsString('id="nfse-settings-config"', $blade);
        self::assertStringNotContainsString("document.addEventListener('DOMContentLoaded'", $blade);
        self::assertStringNotContainsString('const federalSituacao = document.', $blade);
        self::assertStringNotContainsString('const municipalParametersButton = document.', $blade);
    }
}

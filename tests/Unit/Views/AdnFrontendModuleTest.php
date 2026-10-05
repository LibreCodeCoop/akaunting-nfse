<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

final class AdnFrontendModuleTest extends TestCase
{
    public function testAdnViewLoadsExternalDistributionModuleInsteadOfInlineStatefulScript(): void
    {
        $view = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Resources/views/adn/index.blade.php',
        );

        self::assertStringContainsString('adn-distribution-browser.js', $view);
        self::assertStringContainsString('data-querying-label=', $view);
        self::assertStringContainsString('data-error-label=', $view);
        self::assertStringNotContainsString('new URLSearchParams({', $view);
        self::assertStringNotContainsString("queryButton.addEventListener('click'", $view);
    }
}

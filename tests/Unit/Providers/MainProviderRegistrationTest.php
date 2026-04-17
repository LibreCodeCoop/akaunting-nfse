<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Providers;

use Modules\Nfse\Tests\TestCase;

final class MainProviderRegistrationTest extends TestCase
{
    public function testMainProviderRegistersProvisionCommandInConsole(): void
    {
        $path = dirname(__DIR__, 3) . '/Providers/Main.php';
        $content = file_get_contents($path);

        self::assertStringContainsString('ProvisionTestUser::class', $content);
        self::assertStringContainsString('runningInConsole()', $content);
        self::assertStringContainsString('commands([', $content);
        self::assertStringContainsString("->composer('sales.invoices.show'", $content);
        self::assertStringContainsString("->composer(['common.items.create', 'common.items.edit']", $content);
        self::assertStringContainsString('registerItemFiscalProfileHooks', $content);
        self::assertStringContainsString('ItemFiscalProfile::updateOrCreate', $content);
        self::assertStringContainsString('OverrideInvoiceEmailRoute::class', $content);
    }

    public function testEventProviderListensToRouteMatchedForInvoiceSendFlow(): void
    {
        $path = dirname(__DIR__, 3) . '/Providers/Event.php';
        $content = file_get_contents($path);

        self::assertStringContainsString("'Illuminate\\\\Routing\\\\Events\\\\RouteMatched'", $content);
        self::assertStringContainsString('OverrideInvoiceEmailRoute::class', $content);
    }
}

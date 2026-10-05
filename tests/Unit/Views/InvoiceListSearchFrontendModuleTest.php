<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

final class InvoiceListSearchFrontendModuleTest extends TestCase
{
    public function testLegacyInvoiceListDelegatesSearchCookieStateToFrontendModule(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Resources/views/invoices/index.blade.php'
        );

        self::assertStringContainsString('data-nfse-invoice-list-search-module="true"', $blade);
        self::assertStringContainsString('id="nfse-invoice-list-search-config"', $blade);
        self::assertStringNotContainsString('hydrateSearchStringCookie', $blade);
        self::assertStringNotContainsString("Cookies.remove('search-string')", $blade);
    }
}

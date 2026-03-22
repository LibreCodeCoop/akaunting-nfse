<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Routes;

use Modules\Nfse\Tests\TestCase;

class AdminRoutesDefinitionTest extends TestCase
{
    private string $routesContent;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__, 3) . '/Routes/admin.php';
        $this->routesContent = file_get_contents($path);
    }

    public function testUsesModuleRouteMacroWithCompanyContext(): void
    {
        self::assertStringContainsString("Route::module('nfse'", $this->routesContent);
        self::assertStringContainsString("'middleware' => ['web', 'auth', 'language', 'company.identify']", $this->routesContent);
    }

    public function testSettingsRouteUsesModuleSettingsGroup(): void
    {
        self::assertStringContainsString("'prefix' => 'settings'", $this->routesContent);
        self::assertStringContainsString("->name('edit')", $this->routesContent);
        self::assertStringContainsString("->name('readiness')", $this->routesContent);
        self::assertStringContainsString("->name('update')", $this->routesContent);
    }

    public function testCertificateAndInvoiceNamedRoutesExist(): void
    {
        self::assertStringContainsString("->name('dashboard.index')", $this->routesContent);
        self::assertStringContainsString("->name('certificate.upload')", $this->routesContent);
        self::assertStringContainsString("->name('certificate.destroy')", $this->routesContent);
        self::assertStringContainsString("->name('certificate.parse')", $this->routesContent);
        self::assertStringContainsString("->name('ibge.ufs')", $this->routesContent);
        self::assertStringContainsString("->name('ibge.municipalities')", $this->routesContent);
        self::assertStringContainsString("->name('lc116.services')", $this->routesContent);
        self::assertStringContainsString("->name('invoices.index')", $this->routesContent);
        self::assertStringContainsString("->name('invoices.pending')", $this->routesContent);
        self::assertStringContainsString("->name('invoices.emit')", $this->routesContent);
        self::assertStringContainsString("->name('invoices.refresh-all')", $this->routesContent);
        self::assertStringContainsString("->name('invoices.refresh')", $this->routesContent);
        self::assertStringContainsString("->name('invoices.show')", $this->routesContent);
        self::assertStringContainsString("->name('invoices.cancel')", $this->routesContent);
    }
}

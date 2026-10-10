<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Illuminate\Routing\Route;
use Tests\Feature\FeatureTestCase;

final class AdminRouteRegistrationTest extends FeatureTestCase
{
    /**
     * @return array<string,array{string,string}>
     */
    public static function registeredRoutes(): array
    {
        return [
            'dashboard' => ['nfse.dashboard.index', 'GET'],
            'settings edit' => ['nfse.settings.edit', 'GET'],
            'settings update' => ['nfse.settings.update', 'PATCH'],
            'certificate upload' => ['nfse.certificate.upload', 'POST'],
            'certificate destroy' => ['nfse.certificate.destroy', 'DELETE'],
            'certificate parse' => ['nfse.certificate.parse', 'POST'],
            'IBGE states' => ['nfse.ibge.ufs', 'GET'],
            'IBGE municipalities' => ['nfse.ibge.municipalities', 'GET'],
            'LC116 services' => ['nfse.lc116.services', 'GET'],
            'ADN index' => ['nfse.adn.index', 'GET'],
            'ADN distribution' => ['nfse.adn.distribution', 'GET'],
            'ADN invoice events' => ['nfse.invoices.adn-events', 'GET'],
            'invoice index' => ['nfse.invoices.index', 'GET'],
            'pending invoices' => ['nfse.invoices.pending', 'GET'],
            'invoice emit' => ['nfse.invoices.emit', 'POST'],
            'invoice refresh all' => ['nfse.invoices.refresh-all', 'POST'],
            'invoice refresh' => ['nfse.invoices.refresh', 'POST'],
            'invoice reemit' => ['nfse.invoices.reemit', 'POST'],
            'invoice substitute' => ['nfse.invoices.substitute', 'POST'],
            'invoice show' => ['nfse.invoices.show', 'GET'],
            'invoice cancel' => ['nfse.invoices.cancel', 'DELETE'],
            'closing index' => ['nfse.closing.index', 'GET'],
            'closing export' => ['nfse.closing.export', 'GET'],
        ];
    }

    public function testSensitiveFiscalRoutesEnforcePermissions(): void
    {
        $permissions = [
            'nfse.settings.edit' => 'permission:read-nfse-settings',
            'nfse.settings.update' => 'permission:update-nfse-settings',
            'nfse.settings.vault' => 'permission:update-nfse-settings',
            'nfse.settings.fiscal' => 'permission:update-nfse-settings',
            'nfse.settings.federal' => 'permission:update-nfse-settings',
            'nfse.settings.artifacts' => 'permission:update-nfse-settings',
            'nfse.certificate.upload' => 'permission:update-nfse-settings',
            'nfse.certificate.parse' => 'permission:update-nfse-settings',
            'nfse.certificate.destroy' => 'permission:delete-nfse-settings',
            'nfse.invoices.emit' => 'permission:update-sales-invoices',
            'nfse.invoices.refresh-all' => 'permission:update-sales-invoices',
            'nfse.invoices.refresh' => 'permission:update-sales-invoices',
            'nfse.invoices.reemit' => 'permission:update-sales-invoices',
            'nfse.invoices.substitute' => 'permission:update-sales-invoices',
            'nfse.invoices.cancel' => 'permission:update-sales-invoices',
        ];

        foreach ($permissions as $name => $permission) {
            $route = app('router')->getRoutes()->getByName($name);
            self::assertInstanceOf(Route::class, $route);
            self::assertContains($permission, $route->gatherMiddleware(), $name);
        }
    }

    /**
     * @dataProvider registeredRoutes
     */
    public function testModuleRouteIsRegisteredInCompanyAwareAdminGroup(string $name, string $method): void
    {
        $route = app('router')->getRoutes()->getByName($name);

        self::assertInstanceOf(Route::class, $route, 'Missing route: ' . $name);
        self::assertStringStartsWith('{company_id}/nfse', $route->uri());
        self::assertContains($method, $route->methods());
        self::assertContains('admin', $route->gatherMiddleware());
    }
}

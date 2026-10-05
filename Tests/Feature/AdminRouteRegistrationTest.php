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

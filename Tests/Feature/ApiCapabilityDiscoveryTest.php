<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Modules\Nfse\Application\ReadApiCapabilities;
use Modules\Nfse\Http\Controllers\ApiCapabilityController;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

final class ApiCapabilityDiscoveryTest extends FeatureTestCase
{
    public function testOnlyAnnotatedReadOnlyNativeRouteIsAdvertised(): void
    {
        $this->loginAs();
        $routes = (new ReadApiCapabilities())->list();
        $names = array_column($routes, 'name');

        self::assertContains('api.nfse.invoices.fiscal-decision.show', $names);
        self::assertContains('api.nfse.settings.category-issuance.index', $names);
        self::assertNotContains('api.nfse.invoices.fiscal-decision.store', $names);
        self::assertNotContains('api.nfse.invoices.emit', $names);
        self::assertNotContains('api.nfse.receipts.artifacts.download', $names);
        self::assertNotContains('api.nfse.adn.distribution', $names);
        self::assertNotContains('api.nfse.discovery.index', $names);

        $record = $routes[array_search('api.nfse.invoices.fiscal-decision.show', $names, true)];
        self::assertSame('GET', $record['method']);
        self::assertSame('invoice', $record['scope']);
        self::assertSame(['invoice'], $record['params']);
        self::assertSame(['/data/current', '/data/effective', '/data/history'], $record['response_paths']);
        self::assertSame(['read-sales-invoices'], $record['permissions']);
        self::assertContains('api', Route::getRoutes()->getByName('api.nfse.discovery.index')->gatherMiddleware());

        $payload = (new ApiCapabilityController())->index(new ReadApiCapabilities())->getData(true);
        self::assertSame((int) company_id(), $payload['company_id']);
        self::assertSame(1, $payload['version']);
        self::assertSame($routes, $payload['routes']);

        // The feature harness disables Laravel exception conversion by default.
        // An unknown invoice must become the native HTTP 404 response.
        $this->withExceptionHandling();
        $this->getJson(route('nfse.invoices.fiscal-decision.show', 999999))
            ->assertNotFound();
    }

    public function testNewExplicitlyReviewedRouteIsDiscoveredWithoutEditingAnMcpCatalog(): void
    {
        $collection = new RouteCollection();
        $new = new LaravelRoute(['GET', 'HEAD'], '{company_id}/api/example-records/{record}', static fn (): array => []);
        $new->name('api.example-records.show')->middleware(['api', 'permission:read-example-records'])
            ->defaults('mcp_read_contract', [
                'version' => 1, 'scope' => 'company',
                'response_paths' => ['/data'], 'query' => ['page' => 'positive_integer'],
            ]);
        $collection->add($new);
        self::assertSame([
            'name' => 'api.example-records.show',
            'uri' => '{company_id}/api/example-records/{record}',
            'method' => 'GET', 'scope' => 'company',
            'params' => ['record'], 'query' => ['page' => 'positive_integer'],
            'response_paths' => ['/data'],
            'permissions' => ['read-example-records'],
        ], (new ReadApiCapabilities())->list($collection)[0]);
    }

    public function testUnsafeOrMalformedAnnotationsFailClosed(): void
    {
        foreach ([
            [['POST'], '{company_id}/api/nfse/emit', ['api', 'permission:read-sales-invoices']],
            [['GET'], '{company_id}/api/nfse/unsafe', ['api']],
            [['GET'], 'https://unsafe.example.invalid/', ['api', 'permission:read-sales-invoices']],
            [['GET'], '{company_id}/api/nfse/unsafe', ['web', 'permission:read-sales-invoices']],
        ] as [$methods, $uri, $middleware]) {
            $collection = new RouteCollection();
            $route = new LaravelRoute($methods, $uri, static fn (): array => []);
            $route->name('api.nfse.unsafe.index')->middleware($middleware)
                ->defaults('mcp_read_contract', [
                    'version' => 1, 'scope' => 'company', 'response_paths' => ['/data'],
                ]);
            $collection->add($route);
            try {
                (new ReadApiCapabilities())->list($collection);
                self::fail('Unsafe route annotation was accepted.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }
}

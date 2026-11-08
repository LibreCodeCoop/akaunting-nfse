<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * Machine-readable API read contracts from Laravel's *actual* registration.
 *
 * Only a GET explicitly annotated by its owner is advertised. A GET verb
 * or a "show" action alone is not proof of a side-effect-free JSON contract.
 * This metadata is not an ACL grant; the original route middleware remains
 * authoritative for every subsequent API call.
 */
final class ReadApiCapabilities
{
    /** @return list<array<string,mixed>> */
    public function list(?RouteCollectionInterface $routes = null): array
    {
        $result = [];
        foreach (($routes ?? Route::getRoutes())->getRoutes() as $route) {
            if (!$route instanceof LaravelRoute || !array_key_exists('mcp_read_contract', $route->defaults)) {
                continue;
            }

            $contract = $route->defaults['mcp_read_contract'];
            $name = $route->getName();
            $uri = $route->uri();
            $methods = $route->methods();
            $middleware = $route->gatherMiddleware();
            if (!is_array($contract) || ($contract['version'] ?? null) !== 1
                || !is_string($name)
                || preg_match('/^api\.[a-z0-9.-]{4,110}$/D', $name) !== 1
                || !is_string($uri)
                || preg_match('~^(?:\{company_id\}/)?api/[a-z0-9-]+(?:/(?:[a-z0-9-]+|\{[a-z_]+\}))*$~D', $uri) !== 1
                || !in_array('GET', $methods, true)
                || array_diff($methods, ['GET', 'HEAD']) !== []
                || !in_array('api', $middleware, true)) {
                throw new RuntimeException('Invalid discoverable API route contract');
            }

            $permissions = [];
            foreach ($middleware as $entry) {
                if (is_string($entry) && preg_match('/^permission:(read-[a-z0-9-]+)$/D', $entry, $matches) === 1) {
                    $permissions[] = $matches[1];
                }
            }
            if ($permissions === []) {
                throw new RuntimeException('Discoverable API read requires native read permission');
            }

            $scope = $contract['scope'] ?? null;
            if (!in_array($scope, ['invoice', 'company'], true)) {
                throw new RuntimeException('Invalid discoverable API ownership scope');
            }
            preg_match_all('/\{([a-z_]+)\}/', $uri, $matches);
            $parameters = array_values(array_filter($matches[1], static fn (string $value): bool => $value !== 'company_id'));
            if ($scope === 'invoice' && !in_array('invoice', $parameters, true)) {
                throw new RuntimeException('Invoice-scoped read must require an invoice ID');
            }
            if ($scope === 'company' && in_array('invoice', $parameters, true)) {
                throw new RuntimeException('Company read cannot use invoice parameters');
            }

            $paths = $contract['response_paths'] ?? null;
            if (!is_array($paths) || $paths === [] || !array_is_list($paths) || count($paths) > 12) {
                throw new RuntimeException('Discoverable API requires explicit response projections');
            }
            foreach ($paths as $path) {
                if (!is_string($path) || preg_match('~^/data(?:/[a-z_][a-z0-9_]*){0,4}$~D', $path) !== 1) {
                    throw new RuntimeException('Invalid discoverable API projection path');
                }
            }

            // Query parameters are declared by the route owner, not inferred
            // from untrusted client input. Unspecified query fields are denied.
            $query = $contract['query'] ?? [];
            if (!is_array($query) || (array_is_list($query) && $query !== []) || count($query) > 20) {
                throw new RuntimeException('Invalid discoverable query schema');
            }
            foreach ($query as $key => $type) {
                if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) !== 1
                    || !in_array($type, ['positive_integer', 'short_text'], true)
                    || $key === 'company_id') {
                    throw new RuntimeException('Invalid discoverable query parameter');
                }
            }

            $result[] = [
                'name' => $name,
                'uri' => $uri,
                'method' => 'GET',
                'scope' => $scope,
                'params' => $parameters,
                'query' => $query,
                'response_paths' => $paths,
                'permissions' => array_values(array_unique($permissions)),
            ];
        }
        usort($result, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        if (count($result) > 200) {
            throw new RuntimeException('Too many discoverable API routes');
        }

        return $result;
    }
}

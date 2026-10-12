<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Support;

use Modules\Nfse\Support\VaultDiagnosticTransport;

/** Deterministic HTTP responses for the exact KV v2 capabilities path. */
final class CompanyProfileFakeVaultTransport implements VaultDiagnosticTransport
{
    public bool $offline = false;
    public bool $denyCompanyPaths = false;

    /** @var list<string> */
    public array $paths = [];

    public function request(string $method, string $url, ?string $token = null, array $body = []): array
    {
        if (str_ends_with($url, '/sys/health')) {
            return ['status' => $this->offline ? 503 : 200, 'body' => []];
        }

        if (str_ends_with($url, '/auth/token/lookup-self')) {
            return ['status' => 200, 'body' => []];
        }

        if (str_contains($url, '/sys/internal/ui/mounts/')) {
            return ['status' => 200, 'body' => ['data' => ['type' => 'kv', 'options' => ['version' => 2]]]];
        }

        if (str_ends_with($url, '/sys/capabilities-self')) {
            $path = (string) ($body['paths'][0] ?? '');
            $this->paths[] = $path;
            $rights = $this->denyCompanyPaths && str_contains($path, '/pfx/companies/')
                ? ['deny'] : ['read', 'create', 'update', 'delete'];

            return ['status' => 200, 'body' => [$path => $rights]];
        }

        return ['status' => 404, 'body' => []];
    }
}

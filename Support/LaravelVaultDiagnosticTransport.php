<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Support\Facades\Http;

/**
 * Only network-aware part of the infrastructure diagnosis. No KV secret reads
 * or writes: the coordinator POSTs only AppRole login and capability introspection.
 */
final class LaravelVaultDiagnosticTransport implements VaultDiagnosticTransport
{
    /**
     * @param array<string, mixed> $body
     * @return array{status:int,body:array<string,mixed>}
     */
    public function request(string $method, string $url, ?string $token = null, array $body = []): array
    {
        $client = Http::timeout(5)->withoutRedirecting();

        if ($token !== null) {
            $client = $client->withHeaders(['X-Vault-Token' => $token]);
        }

        $response = match ($method) {
            'GET' => $client->get($url),
            'POST' => $client->post($url, $body),
            default => throw new \InvalidArgumentException('Unsupported Vault diagnostic request.'),
        };
        $decoded = $response->json();

        return [
            'status' => $response->status(),
            'body' => is_array($decoded) ? $decoded : [],
        ];
    }
}

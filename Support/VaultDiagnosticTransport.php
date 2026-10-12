<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

/**
 * Narrow Vault HTTP boundary, replaceable by a deterministic fake in unit tests.
 * Never log tokens, request bodies or untrusted upstream responses.
 */
interface VaultDiagnosticTransport
{
    /**
     * @param array<string, mixed> $body
     * @return array{status:int,body:array<string,mixed>}
     */
    public function request(string $method, string $url, ?string $token = null, array $body = []): array;
}

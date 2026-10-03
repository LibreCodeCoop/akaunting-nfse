<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\SecretStoreException;

final class InMemorySecretStore implements SecretStoreInterface
{
    /** @param array<string, array<string, string>> $secrets */
    public function __construct(private array $secrets = [])
    {
    }

    public function get(string $path): array
    {
        if (!array_key_exists($path, $this->secrets)) {
            throw new SecretStoreException('Synthetic test secret not found: ' . $path);
        }

        return $this->secrets[$path];
    }

    public function put(string $path, array $data): void
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $normalized[(string) $key] = (string) $value;
            }
        }

        $this->secrets[$path] = $normalized;
    }

    public function delete(string $path): void
    {
        unset($this->secrets[$path]);
    }
}

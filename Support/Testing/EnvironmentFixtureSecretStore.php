<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support\Testing;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\SecretStoreException;

/**
 * Deterministic secret store used only by the guarded local/testing harness.
 */
final class EnvironmentFixtureSecretStore implements SecretStoreInterface
{
    public function __construct(
        private readonly ?string $cnpj = null,
        private readonly ?string $password = null,
        private readonly ?string $pfxPath = null,
    ) {
    }

    public function get(string $path): array
    {
        $cnpj = trim($this->cnpj ?? (string) (\getenv('NFSE_TEST_CNPJ') ?: ''));
        $password = $this->password ?? (string) (\getenv('NFSE_TEST_PFX_PASSWORD') ?: '');
        $pfxPath = trim($this->pfxPath ?? (string) (\getenv('NFSE_TEST_PFX_PATH') ?: ''));

        if ($cnpj === '' || $password === '' || $pfxPath === '') {
            throw new SecretStoreException(
                'Deterministic fiscal harness requires NFSE_TEST_CNPJ, NFSE_TEST_PFX_PASSWORD and NFSE_TEST_PFX_PATH.'
            );
        }

        if ($path !== 'pfx/' . $cnpj) {
            throw new SecretStoreException('Unknown deterministic fiscal secret path: ' . $path);
        }

        return [
            'password' => $password,
            'pfx_path' => $pfxPath,
        ];
    }

    public function put(string $path, array $data): void
    {
        throw new SecretStoreException('Deterministic fiscal harness secret store is read-only.');
    }

    public function delete(string $path): void
    {
        throw new SecretStoreException('Deterministic fiscal harness secret store is read-only.');
    }
}

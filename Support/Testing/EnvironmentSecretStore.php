<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support\Testing;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\SecretStoreException;

final class EnvironmentSecretStore implements SecretStoreInterface
{
    public function get(string $path): array
    {
        $cnpj = trim((string) env('NFSE_TEST_CNPJ', ''));
        $expectedPath = 'pfx/' . $cnpj;

        if ($cnpj === '' || $path !== $expectedPath) {
            throw new SecretStoreException('Deterministic fiscal secret is unavailable for path ' . $path);
        }

        $pfxPath = trim((string) env('NFSE_TEST_PFX_PATH', ''));
        $password = (string) env('NFSE_TEST_PFX_PASSWORD', '');

        if ($pfxPath === '' || $password === '') {
            throw new SecretStoreException('Deterministic fiscal certificate environment is incomplete.');
        }

        return [
            'pfx_path' => $pfxPath,
            'password' => $password,
        ];
    }

    public function put(string $path, array $data): void
    {
        throw new \LogicException('Deterministic fiscal secret store is read-only.');
    }

    public function delete(string $path): void
    {
        throw new \LogicException('Deterministic fiscal secret store is read-only.');
    }
}

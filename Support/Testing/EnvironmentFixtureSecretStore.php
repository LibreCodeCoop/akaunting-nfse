<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support\Testing;

use Modules\Nfse\Support\NfseCompanyCertificatePath;
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

        if ($cnpj === '' || $password === '') {
            throw new SecretStoreException(
                'Deterministic fiscal harness requires NFSE_TEST_CNPJ and NFSE_TEST_PFX_PASSWORD.'
            );
        }

        // Resolve only the requested immutable, company-owned certificate
        // revision. Production continues to use Vault, never this fixture.
        if (preg_match('#^pfx/companies/([1-9][0-9]*)/([a-f0-9]{32})$#D', $path, $matches) !== 1) {
            throw new SecretStoreException('Unknown deterministic fiscal secret path.');
        }

        $pfxPath = $this->pfxPath ?? NfseCompanyCertificatePath::filePath((int) $matches[1], $matches[2]);
        $expectedSuffix = '/nfse/pfx/companies/' . $matches[1] . '/' . $matches[2] . '.pfx';

        if (!str_ends_with(str_replace('\\', '/', $pfxPath), $expectedSuffix)) {
            throw new SecretStoreException('Synthetic certificate path belongs to another company or revision.');
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

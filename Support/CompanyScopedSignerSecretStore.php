<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\SecretStoreException;

/**
 * Read-only bridge for the pinned nfse-php XML signer.
 *
 * DpsSigner requests pfx/{cnpj}, which is not a tenant-safe secret key.
 * It may read only the immutable Vault revision already selected by the
 * authenticated operation's native Akaunting company profile. Neither the
 * input CNPJ nor an HTTP session may select another certificate.
 */
final readonly class CompanyScopedSignerSecretStore implements SecretStoreInterface
{
    public function __construct(
        private SecretStoreInterface $secrets,
        private string $cnpj,
        private string $vaultPath,
    ) {
        if (preg_match('/^[A-Z0-9]{14}$/D', $this->cnpj) !== 1
            || preg_match('#^pfx/companies/[1-9][0-9]*/[a-f0-9]{32}$#D', $this->vaultPath) !== 1) {
            throw new \InvalidArgumentException('Fiscal signing requires an identified company certificate revision.');
        }
    }

    /** @return array<string,mixed> */
    public function get(string $path): array
    {
        if ($path !== 'pfx/' . $this->cnpj) {
            throw new SecretStoreException('XML signing certificate identity does not match the owning company.');
        }

        return $this->secrets->get($this->vaultPath);
    }

    /** @param array<string,mixed> $data */
    public function put(string $path, array $data): void
    {
        throw new SecretStoreException('Fiscal signer cannot update certificate secrets.');
    }

    public function delete(string $path): void
    {
        throw new SecretStoreException('Fiscal signer cannot remove certificate secrets.');
    }
}

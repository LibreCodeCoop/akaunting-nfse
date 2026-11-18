<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\CredentialVault\Contracts\SecretStore as SharedSecretStore;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;

/**
 * Unmodified fiscal secret path and payload. Only the underlying generic
 * OpenBao transport changes when the shared vault has been explicitly enabled.
 */
final class SharedVaultSecretStore implements SecretStoreInterface
{
    public function __construct(private readonly SharedSecretStore $vault) {}

    /** @return array<string,string> */
    public function get(string $path): array
    {
        return $this->vault->get($path);
    }

    /** @param array<string,string> $data */
    public function put(string $path, array $data): void
    {
        $this->vault->put($path, $data);
    }

    public function delete(string $path): void
    {
        $this->vault->delete($path);
    }
}

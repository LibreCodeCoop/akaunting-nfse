<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts;

/** Standalone test boundary; production uses the pinned scoped nfse-php. */
interface SecretStoreInterface
{
    /** @return array<string,string> */
    public function get(string $path): array;
    /** @param array<string,string> $data */
    public function put(string $path, array $data): void;
    public function delete(string $path): void;
}

namespace Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\SecretStore;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;

final class OpenBaoSecretStore implements SecretStoreInterface
{
    public function __construct(
        public readonly string $addr,
        public readonly string $mount,
        public readonly ?string $token = null,
        public readonly ?string $roleId = null,
        public readonly ?string $secretId = null,
    ) {
    }

    public function get(string $path): array
    {
        return [];
    }
    public function put(string $path, array $data): void
    {
    }
    public function delete(string $path): void
    {
    }
}

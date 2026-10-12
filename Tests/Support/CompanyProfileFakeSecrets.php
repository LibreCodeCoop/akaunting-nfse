<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;

/** Test-only Vault KV; failures may occur after a write has taken effect. */
final class CompanyProfileFakeSecrets implements SecretStoreInterface
{
    /** @var array<string,array<string,string>> */
    public array $data = [];

    /** @var list<string> */
    public array $filePaths = [];

    public bool $failPutAfterWrite = false;
    public bool $failDelete = false;

    public function get(string $path): array
    {
        if (!isset($this->data[$path])) {
            throw new \RuntimeException('Synthetic secret does not exist.');
        }

        return $this->data[$path];
    }

    public function put(string $path, array $data): void
    {
        $this->data[$path] = $data;
        if (isset($data['pfx_path']) && is_string($data['pfx_path'])) {
            $this->filePaths[] = $data['pfx_path'];
        }

        if ($this->failPutAfterWrite) {
            $this->failPutAfterWrite = false;
            throw new \RuntimeException('Synthetic uncertain Vault write.');
        }
    }

    public function delete(string $path): void
    {
        if ($this->failDelete) {
            throw new \RuntimeException('Synthetic Vault deletion failure.');
        }

        unset($this->data[$path]);
    }
}

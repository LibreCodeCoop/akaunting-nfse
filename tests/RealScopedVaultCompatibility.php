<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Verify the adapter against REAL pinned nfse-php scoped runtime, rather
 * than a synthetic interface stub. No OpenBao request or certificate data.
 */
$shared = getenv('CREDENTIAL_VAULT_TEST_ROOT');
if (!is_string($shared) || !is_file($shared . '/Contracts/SecretStore.php')) {
    throw new RuntimeException('Shared module source required');
}
$nfseRoot = dirname(__DIR__);
$autoload = $nfseRoot . '/3rdparty/scoped/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('NFS-e production scoped dependencies not built');
}
require_once $autoload;
require_once $shared . '/Contracts/SecretStore.php';
require_once $nfseRoot . '/Support/SharedVaultSecretStore.php';

use Modules\CredentialVault\Contracts\SecretStore;
use Modules\Nfse\Support\SharedVaultSecretStore;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;

$fake = new class implements SecretStore {
    /** @var array<string,array<string,string>> */
    private array $values = [];
    public function get(string $path): array { return $this->values[$path] ?? []; }
    public function put(string $path, array $values): void { $this->values[$path] = $values; }
    public function delete(string $path): void { unset($this->values[$path]); }
};
$bridge = new SharedVaultSecretStore($fake);
if (!$bridge instanceof SecretStoreInterface) {
    throw new RuntimeException('Fiscal adapter does not implement the real scoped nfse-php interface');
}
$path = 'pfx/companies/2/' . str_repeat('a', 32);
$value = ['pfx_path' => '/synthetic/nonexistent.pfx', 'password' => 'synthetic'];
$bridge->put($path, $value);
if ($bridge->get($path) !== $value) {
    throw new RuntimeException('Real scoped adapter changed fiscal secret payload');
}
$bridge->delete($path);
if ($bridge->get($path) !== []) {
    throw new RuntimeException('Real scoped adapter failed to preserve delete contract');
}
echo 'PASS real pinned scoped nfse-php interface and original fiscal KV payload contract' . PHP_EOL;

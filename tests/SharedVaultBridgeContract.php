<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require __DIR__ . '/Support/SyntheticFiscalStore.php';

$sharedRoot = getenv('CREDENTIAL_VAULT_TEST_ROOT');
if (!is_string($sharedRoot) || !is_file($sharedRoot . '/Contracts/SecretStore.php')) {
    throw new RuntimeException('Shared vault module path required for tests');
}
require_once $sharedRoot . '/Contracts/SecretStore.php';
require_once $sharedRoot . '/Domain/ScopedSecretPath.php';
require_once $sharedRoot . '/Infrastructure/OpenBaoKv2.php';
require_once $sharedRoot . '/Support/VaultProfileStore.php';
require_once $sharedRoot . '/Support/VaultClientFactory.php';
require_once dirname(__DIR__) . '/Support/NfseCompanyCertificatePath.php';
require_once dirname(__DIR__) . '/Support/SharedVaultSecretStore.php';
require_once dirname(__DIR__) . '/Support/FiscalSecretStoreFactory.php';

use Modules\CredentialVault\Contracts\SecretStore;
use Modules\CredentialVault\Infrastructure\OpenBaoKv2;
use Modules\Nfse\Support\SharedVaultSecretStore;
use Modules\Nfse\Support\FiscalSecretStoreFactory;
use Modules\Nfse\Support\NfseCompanyCertificatePath;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\SecretStore\OpenBaoSecretStore;

function checkNfse(bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
}
$path = NfseCompanyCertificatePath::vaultPath(2, str_repeat('a', 32));
checkNfse($path === 'pfx/companies/2/' . str_repeat('a', 32),
    'NFS-e company-specific PFX identity remains unchanged');
$rows = [];
$fake = new class($rows) implements SecretStore {
    public array $data = [];
    public array $calls = [];
    public function __construct(array $rows) { $this->data = $rows; }
    public function get(string $path): array {
        $this->calls[] = 'get:' . $path;
        return $this->data[$path] ?? [];
    }
    public function put(string $path, array $data): void {
        $this->calls[] = 'put:' . $path;
        $this->data[$path] = $data;
    }
    public function delete(string $path): void {
        $this->calls[] = 'delete:' . $path;
        unset($this->data[$path]);
    }
};
$adapter = new SharedVaultSecretStore($fake);
checkNfse($adapter instanceof SecretStoreInterface,
    'generic Vault implements existing fiscal nfse-php contract');
$values = ['password' => 'only-a-synthetic-password', 'pfx_path' => '/synthetic/certificate.pfx'];
$adapter->put($path, $values);
checkNfse($adapter->get($path) === $values,
    'PFX password payload remains byte-for-byte compatible');
$adapter->delete($path);
checkNfse($adapter->get($path) === [] &&
    $fake->calls === ['put:' . $path, 'get:' . $path,
        'delete:' . $path, 'get:' . $path],
    'get/put/delete forwarded without changing legacy paths');

$config = [
    'addr' => 'https://vault.example',
    'mount' => '/nfse',
    'token' => 'synthetic-test-token', 'roleId' => null, 'secretId' => null,
];
$GLOBALS['__test_vault_profiles'] = new \Modules\CredentialVault\Support\VaultProfileStore(
    new PDO('sqlite::memory:'),
    static fn (string $value): string => $value,
    static fn (string $value): string => $value,
);
$previous = getenv('NFSE_USE_SHARED_CREDENTIAL_VAULT');
try {
    putenv('NFSE_USE_SHARED_CREDENTIAL_VAULT=yes');
    $shared = FiscalSecretStoreFactory::make($config);
    checkNfse($shared instanceof SharedVaultSecretStore,
        'explicit opt-in chooses shared Vault with existing encrypted fiscal config');
    putenv('NFSE_USE_SHARED_CREDENTIAL_VAULT=no');
    $legacy = FiscalSecretStoreFactory::make($config);
    checkNfse($legacy instanceof OpenBaoSecretStore &&
        $legacy->mount === '/nfse' &&
        $legacy->token === 'synthetic-test-token',
        'without opt-in existing fiscal backend and mount remain unchanged');
} finally {
    if ($previous === false) {
        putenv('NFSE_USE_SHARED_CREDENTIAL_VAULT');
    } else {
        putenv('NFSE_USE_SHARED_CREDENTIAL_VAULT=' . $previous);
    }
}
// Exercise the *second* migration switch independently: the Vault transport
// may use a shared encrypted profile only after an explicit opt-in.
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('CREATE TABLE lcc_credential_vault_profiles
    (purpose VARCHAR(80) PRIMARY KEY,encrypted_payload TEXT,
     created_at DATETIME,updated_at DATETIME)');
$encrypt = static fn (string $json): string => base64_encode($json);
$decrypt = static fn (string $data): string => (string) base64_decode($data, true);
$GLOBALS['__test_vault_profiles'] = new \Modules\CredentialVault\Support\VaultProfileStore(
    $pdo, $encrypt, $decrypt
);
$GLOBALS['__test_vault_profiles']->create('nfse', [
    ...$config, 'auth_mode' => 'token',
]);
function app(string $abstract): object {
    if ($abstract === \Modules\CredentialVault\Support\VaultClientFactory::class) {
        return new \Modules\CredentialVault\Support\VaultClientFactory(
            $GLOBALS['__test_vault_profiles']);
    }
    throw new RuntimeException('Unexpected shared profile service');
}
$oldShared = getenv('NFSE_USE_SHARED_CREDENTIAL_VAULT');
$oldSource = getenv('NFSE_SHARED_VAULT_PROFILE');
try {
    putenv('NFSE_USE_SHARED_CREDENTIAL_VAULT=yes');
    putenv('NFSE_SHARED_VAULT_PROFILE=yes');
    $migrated = FiscalSecretStoreFactory::make();
    checkNfse($migrated instanceof SharedVaultSecretStore,
        'NFS-e uses the verified shared encrypted profile when explicitly enabled');
    $pdo->exec("DELETE FROM lcc_credential_vault_profiles WHERE purpose = 'nfse'");
    try {
        FiscalSecretStoreFactory::make();
        throw new RuntimeException('FAIL empty shared config fell back to fiscal credentials');
    } catch (RuntimeException $error) {
        checkNfse($error->getMessage() === 'shared_vault_profile_missing',
            'missing shared profile fails closed instead of silently using legacy');
    }
} finally {
    foreach ([
        ['NFSE_USE_SHARED_CREDENTIAL_VAULT', $oldShared],
        ['NFSE_SHARED_VAULT_PROFILE', $oldSource],
    ] as [$name, $value]) {
        if ($value === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }
    unset($GLOBALS['__test_vault_profiles']);
}
echo "NFS-e backward-compatible shared Vault adapter tests passed.\n";

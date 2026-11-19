<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\SecretStore\OpenBaoSecretStore;

/**
 * Backward-compatible transition to the generic CredentialVault module.
 *
 * Both backends read the SAME configured mount and unchanged pfx/companies/*
 * revision paths. No secret is copied, rewritten or migrated at runtime.
 * An explicit opt-in selects the shared implementation. If it is unavailable,
 * fail closed rather than silently switch back with surprising privileges.
 */
final class FiscalSecretStoreFactory
{
    /**
     * @param ?array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string} $config
     */
    public static function make(?array $config = null): SecretStoreInterface
    {
        if (getenv('NFSE_USE_SHARED_CREDENTIAL_VAULT') === 'yes') {
            if (!class_exists(\Modules\CredentialVault\Support\VaultClientFactory::class)) {
                throw new \RuntimeException('shared_vault_module_required');
            }
            // Laravel injects the same shared factory used by future modules,
            // not a second fiscal-specific implementation of the OpenBao API.
            $factory = app(\Modules\CredentialVault\Support\VaultClientFactory::class);
            if ($config === null && getenv('NFSE_SHARED_VAULT_PROFILE') === 'yes') {
                try {
                    return new SharedVaultSecretStore($factory->forPurpose('nfse'));
                } catch (\RuntimeException $error) {
                    if ($error->getMessage() === 'vault_profile_missing') {
                        throw new \RuntimeException('shared_vault_profile_missing');
                    }
                    throw $error;
                }
            }
            $config ??= VaultConfig::secretStoreConfig();
            return new SharedVaultSecretStore($factory->fromConfig($config));
        }

        $config ??= VaultConfig::secretStoreConfig();
        return new OpenBaoSecretStore(
            addr: $config['addr'],
            mount: $config['mount'],
            token: $config['token'],
            roleId: $config['roleId'],
            secretId: $config['secretId'],
        );
    }
}

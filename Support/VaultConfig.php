<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

final class VaultConfig
{
    /**
     * @return array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string}
     */
    public static function secretStoreConfig(): array
    {
        return (new GlobalVaultSettings())->effective();
    }

    /**
     * No global DB record: resolve environment aliases in declared order.
     * Token authentication wins if both environment auth modes are present.
     *
     * @return array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string}
     */
    public static function environmentConfig(): array
    {
        $token = self::resolve(null, ['VAULT_TOKEN', 'OPENBAO_TOKEN']);
        $roleId = self::resolve(null, ['VAULT_ROLE_ID', 'OPENBAO_ROLE_ID']);
        $secretId = self::resolve(null, ['VAULT_SECRET_ID', 'OPENBAO_SECRET_ID']);

        if ($token !== null) {
            $roleId = null;
            $secretId = null;
        }

        return [
            'addr' => self::resolve(null, ['VAULT_ADDR', 'OPENBAO_ADDR']) ?? '',
            'mount' => self::normalizeMount(
                self::resolve(null, ['VAULT_MOUNT', 'OPENBAO_MOUNT'], '/nfse') ?? '/nfse',
            ),
            'token' => $token,
            'roleId' => $roleId,
            'secretId' => $secretId,
        ];
    }

    /**
     * A configured credential is not a successful connectivity test.
     *
     * @param array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string} $config
     */
    public static function configured(array $config): bool
    {
        return $config['addr'] !== ''
            && $config['mount'] !== ''
            && ($config['token'] !== null || ($config['roleId'] !== null && $config['secretId'] !== null));
    }

    public static function normalizeMount(string $mount): string
    {
        $normalized = trim($mount);

        if ($normalized === '') {
            return '/nfse';
        }

        $normalized = '/' . ltrim($normalized, '/');

        return rtrim($normalized, '/') ?: '/nfse';
    }

    /**
     * Resolve configuration with strict precedence: setting > env > default.
     *
     * @param array<int, string> $envNames
     * @param null|callable(string): mixed $envResolver
     */
    public static function resolve(mixed $settingValue, array $envNames, ?string $default = null, ?callable $envResolver = null): ?string
    {
        $normalizedSetting = self::normalize($settingValue);
        if ($normalizedSetting !== null) {
            return $normalizedSetting;
        }

        $resolveEnv = $envResolver ?? static fn (string $name): mixed => env($name);

        foreach ($envNames as $envName) {
            $normalizedEnv = self::normalize($resolveEnv($envName));
            if ($normalizedEnv !== null) {
                return $normalizedEnv;
            }
        }

        return $default;
    }

    private static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

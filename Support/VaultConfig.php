<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

final class VaultConfig
{
    /**
     * @return array{addr: string, mount: string, token: ?string, roleId: ?string, secretId: ?string}
     */
    public static function secretStoreConfig(): array
    {
        $mount = self::normalizeMount(
            self::resolve(
                setting('nfse.bao_mount', '/nfse'),
                ['VAULT_MOUNT', 'OPENBAO_MOUNT'],
                '/nfse',
            ) ?? '/nfse',
        );

        return [
            'addr' => self::resolve(
                setting('nfse.bao_addr'),
                ['VAULT_ADDR', 'OPENBAO_ADDR'],
                'http://openbao:8200',
            ) ?? 'http://openbao:8200',
            'mount' => $mount,
            'token' => self::resolve(
                setting('nfse.bao_token'),
                ['VAULT_TOKEN', 'OPENBAO_TOKEN'],
                null,
            ),
            'roleId' => self::resolve(
                setting('nfse.bao_role_id'),
                ['VAULT_ROLE_ID', 'OPENBAO_ROLE_ID'],
                null,
            ),
            'secretId' => self::resolve(
                setting('nfse.bao_secret_id'),
                ['VAULT_SECRET_ID', 'OPENBAO_SECRET_ID'],
                null,
            ),
        ];
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

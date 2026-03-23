<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\VaultConfig;
use Modules\Nfse\Tests\TestCase;

class VaultConfigTest extends TestCase
{
    public function testResolvePrefersSettingOverEnvAndDefault(): void
    {
        $value = VaultConfig::resolve(
            'https://settings.example',
            ['VAULT_ADDR'],
            'http://default.example',
            static fn (string $key): ?string => match ($key) {
                'VAULT_ADDR' => 'https://env-vault.example',
                default => null,
            },
        );

        self::assertSame('https://settings.example', $value);
    }

    public function testResolveUsesFirstAvailableEnvInDeclaredOrder(): void
    {
        $value = VaultConfig::resolve(
            null,
            ['VAULT_TOKEN', 'OPENBAO_TOKEN'],
            null,
            static fn (string $key): ?string => match ($key) {
                'VAULT_TOKEN' => 'vault-token',
                'OPENBAO_TOKEN' => 'openbao-token',
                default => null,
            },
        );

        self::assertSame('vault-token', $value);
    }

    public function testResolveFallsBackToOpenbaoEnvAliasWhenVaultEnvIsMissing(): void
    {
        $value = VaultConfig::resolve(
            null,
            ['VAULT_TOKEN', 'OPENBAO_TOKEN'],
            null,
            static fn (string $key): ?string => match ($key) {
                'OPENBAO_TOKEN' => 'openbao-token',
                default => null,
            },
        );

        self::assertSame('openbao-token', $value);
    }

    public function testResolveReturnsDefaultWhenSettingAndEnvAreMissing(): void
    {
        $value = VaultConfig::resolve(
            '',
            ['VAULT_MOUNT'],
            '/nfse',
            static fn (string $_key): ?string => null,
        );

        self::assertSame('/nfse', $value);
    }

    public function testNormalizeMountAddsLeadingSlashWhenMissing(): void
    {
        self::assertSame('/nfse', VaultConfig::normalizeMount('nfse'));
    }

    public function testNormalizeMountKeepsSingleLeadingSlash(): void
    {
        self::assertSame('/nfse', VaultConfig::normalizeMount('/nfse'));
    }

    public function testNormalizeMountFallsBackForEmptyInput(): void
    {
        self::assertSame('/nfse', VaultConfig::normalizeMount('   '));
    }
}

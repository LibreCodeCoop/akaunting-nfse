<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\VaultDiagnosticPolicy;
use Modules\Nfse\Tests\TestCase;

final class VaultDiagnosticPolicyTest extends TestCase
{
    public function testConfigurationRejectsUntrustedEndpointsAndMountTraversal(): void
    {
        $valid = [
            'addr' => 'https://vault.example.test',
            'mount' => '/nfse',
            'token' => 'synthetic-token',
            'roleId' => null,
            'secretId' => null,
        ];

        self::assertNull(VaultDiagnosticPolicy::configurationError($valid));

        foreach ([
            [['addr' => ''], 'missing_configuration'],
            [['token' => null], 'missing_configuration'],
            [['addr' => 'http://vault.example.test'], 'invalid_endpoint'],
            [['addr' => 'https://user:pass@vault.example.test'], 'invalid_endpoint'],
            [['addr' => 'https://vault.example.test/?token=secret'], 'invalid_endpoint'],
            [['mount' => '/nfse/../sys'], 'invalid_mount'],
            [['mount' => '/nfse//pfx'], 'invalid_mount'],
            [['mount' => '/nfse?query=1'], 'invalid_mount'],
        ] as [$overrides, $reason]) {
            self::assertSame($reason, VaultDiagnosticPolicy::configurationError(array_replace($valid, $overrides)));
        }
    }

    public function testKvVersionMustBeExactlyTwo(): void
    {
        self::assertTrue(VaultDiagnosticPolicy::isKvV2(['data' => ['type' => 'kv', 'options' => ['version' => '2']]]));
        self::assertTrue(VaultDiagnosticPolicy::isKvV2(['data' => ['type' => 'kv', 'options' => ['version' => 2]]]));
        self::assertFalse(VaultDiagnosticPolicy::isKvV2(['data' => ['type' => 'kv', 'options' => ['version' => '1']]]));
        self::assertFalse(VaultDiagnosticPolicy::isKvV2(['data' => ['type' => 'transit', 'options' => ['version' => '2']]]));
        self::assertFalse(VaultDiagnosticPolicy::isKvV2([]));
    }

    public function testPermissionsFollowExactReadCreateUpdateAndDenySemantics(): void
    {
        $path = 'nfse/data/pfx/__nfse_capability_probe__';
        foreach ([
            [['capabilities' => ['read', 'create', 'update']], ['read' => true, 'write' => true]],
            [[$path => ['root']], ['read' => true, 'write' => true]],
            [['capabilities' => ['read', 'update']], ['read' => true, 'write' => false]],
            [['capabilities' => ['create', 'update']], ['read' => false, 'write' => true]],
            [['capabilities' => ['read']], ['read' => true, 'write' => false]],
            [['capabilities' => ['root', 'deny']], ['read' => false, 'write' => false]],
            [['capabilities' => ['deny']], ['read' => false, 'write' => false]],
            [[$path => ['read'], 'capabilities' => ['root']], ['read' => true, 'write' => false]],
            [['data' => [$path => ['read', 'create', 'update']]], ['read' => true, 'write' => true]],
        ] as [$response, $expected]) {
            self::assertSame($expected, VaultDiagnosticPolicy::assessCapabilities($response, $path));
        }

        self::assertNull(VaultDiagnosticPolicy::assessCapabilities([], $path));
        self::assertNull(VaultDiagnosticPolicy::assessCapabilities(['capabilities' => 'read'], $path));
        self::assertNull(VaultDiagnosticPolicy::assessCapabilities(['capabilities' => [false]], $path));
    }

    public function testUnverifiedAndUnconfiguredStatesKeepIdenticalFalseCapabilityKeys(): void
    {
        $caps = VaultDiagnosticPolicy::emptyCapabilities();

        self::assertSame([
            'network' => false,
            'authentication' => false,
            'kv_v2' => false,
            'certificate_read' => false,
            'certificate_write' => false,
        ], $caps);

        self::assertSame(
            ['state' => 'unverified', 'reason' => 'not_checked', 'capabilities' => $caps],
            VaultDiagnosticPolicy::result('unverified', 'not_checked', $caps),
        );
    }
}

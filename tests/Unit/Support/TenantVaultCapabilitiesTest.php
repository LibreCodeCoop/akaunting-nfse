<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\VaultDiagnostics;
use Modules\Nfse\Support\VaultDiagnosticTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TenantVaultTransport implements VaultDiagnosticTransport
{
    /** @var list<string> */
    public array $checkedPaths = [];

    /** @param list<string> $rights */
    public function __construct(private readonly array $rights)
    {
    }

    public function request(string $method, string $url, ?string $token = null, array $body = []): array
    {
        if (str_ends_with($url, '/v1/sys/health')) {
            return ['status' => 200, 'body' => []];
        }
        if (str_ends_with($url, '/v1/auth/token/lookup-self')) {
            return ['status' => 200, 'body' => []];
        }
        if (str_contains($url, '/v1/sys/internal/ui/mounts/')) {
            return ['status' => 200, 'body' => ['data' => ['type' => 'kv', 'options' => ['version' => 2]]]];
        }
        if (str_ends_with($url, '/v1/sys/capabilities-self')) {
            $path = $body['paths'][0] ?? '';
            $this->checkedPaths[] = $path;

            return ['status' => 200, 'body' => [$path => $this->rights]];
        }

        return ['status' => 404, 'body' => []];
    }
}

final class TenantVaultCapabilitiesTest extends TestCase
{
    #[DataProvider('rights')]
    public function testActualCompanyCertificateKeyRequiresLifecyclePermissions(array $rights, bool $expected): void
    {
        $transport = new TenantVaultTransport($rights);
        $relativePath = 'pfx/companies/21/' . str_repeat('a', 32);
        $result = (new VaultDiagnostics($transport))->checkCertificatePath(self::configuration(), $relativePath);

        self::assertSame($expected ? 'operational' : 'failed', $result['state']);
        self::assertSame(['secret/data/' . $relativePath], $transport->checkedPaths);
        self::assertStringNotContainsString('synthetic-vault-token', json_encode($result));
    }

    /** @return array<string,array{list<string>,bool}> */
    public static function rights(): array
    {
        return [
            'full ACL' => [['read', 'create', 'update', 'delete'], true],
            'admin root' => [['root'], true],
            'write but no delete' => [['read', 'create', 'update'], false],
            'write but no read' => [['create', 'update', 'delete'], false],
            'read and create without update' => [['read', 'create', 'delete'], false],
            'explicit deny overrides root' => [['root', 'deny'], false],
        ];
    }

    public function testGenericProbeDoesNotSubstituteForTenantPath(): void
    {
        $transport = new TenantVaultTransport(['read', 'create', 'update']);
        $result = (new VaultDiagnostics($transport))->check(self::configuration());
        self::assertSame('operational', $result['state']);
        self::assertSame(['secret/data/pfx/__nfse_capability_probe__'], $transport->checkedPaths);

        $specific = (new VaultDiagnostics($transport))->checkCertificatePath(
            self::configuration(),
            'pfx/companies/21/' . str_repeat('a', 32),
        );
        self::assertSame('failed', $specific['state']);
        self::assertSame('insufficient_capabilities', $specific['reason']);
    }

    public function testInvalidPathMakesNoNetworkRequest(): void
    {
        $transport = new TenantVaultTransport(['root']);
        $result = (new VaultDiagnostics($transport))->checkCertificatePath(
            self::configuration(),
            'pfx/../other-company',
        );
        self::assertSame('invalid_certificate_path', $result['reason']);
        self::assertSame([], $transport->checkedPaths);
    }

    /** @return array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string} */
    private static function configuration(): array
    {
        return [
            'addr' => 'https://vault.example.test',
            'mount' => '/secret',
            'token' => 'synthetic-vault-token',
            'roleId' => null,
            'secretId' => null,
        ];
    }
}

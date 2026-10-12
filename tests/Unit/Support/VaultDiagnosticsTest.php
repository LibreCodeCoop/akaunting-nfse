<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\VaultDiagnosticTransport;
use Modules\Nfse\Support\VaultDiagnostics;
use Modules\Nfse\Tests\TestCase;

/** Synthetic fake. No Laravel container and no real Vault involved. */
final class FakeVaultDiagnosticTransport implements VaultDiagnosticTransport
{
    /** @var list<array{method:string,url:string,token:?string,body:array<string,mixed>}> */
    public array $requests = [];

    /** @param list<mixed> $responses */
    public function __construct(private array $responses)
    {
    }

    /** @return array{status:int,body:array<string,mixed>} */
    public function request(string $method, string $url, ?string $token = null, array $body = []): array
    {
        $this->requests[] = compact('method', 'url', 'token', 'body');
        if ($this->responses === []) {
            throw new \RuntimeException('Unexpected fake request.');
        }

        $result = array_shift($this->responses);
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }
}

final class VaultDiagnosticsTest extends TestCase
{
    /** @return array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string} */
    private function config(array $overrides = []): array
    {
        return array_replace([
            'addr' => 'https://vault.example.test',
            'mount' => '/nfse',
            'token' => 'fixture-token',
            'roleId' => null,
            'secretId' => null,
        ], $overrides);
    }

    /** @return list<array{status:int,body:array<string,mixed>}> */
    private function successResponses(array $grants = ['read', 'create', 'update'], int $health = 200): array
    {
        return [
            ['status' => $health, 'body' => []],
            ['status' => 200, 'body' => ['data' => ['id' => 'synthetic']]],
            ['status' => 200, 'body' => ['data' => ['type' => 'kv', 'options' => ['version' => '2']]]],
            ['status' => 200, 'body' => ['capabilities' => $grants]],
        ];
    }

    public function testUnverifiedAndInvalidConfigurationsNeverCallNetwork(): void
    {
        $transport = new FakeVaultDiagnosticTransport([]);
        $diagnostics = new VaultDiagnostics($transport);

        self::assertSame('unverified', $diagnostics->notVerified()['state']);
        self::assertSame('unconfigured', $diagnostics->check($this->config(['token' => null]))['state']);
        self::assertSame('invalid_endpoint', $diagnostics->check($this->config(['addr' => 'http://vault.example.test']))['reason']);
        self::assertSame('invalid_mount', $diagnostics->check($this->config(['mount' => '/nfse/../sys']))['reason']);
        self::assertSame([], $transport->requests);
    }

    public function testTokenAuthenticationAndKvPermissionsProduceOperationalState(): void
    {
        $transport = new FakeVaultDiagnosticTransport($this->successResponses());
        $result = (new VaultDiagnostics($transport))->check($this->config());

        self::assertSame('operational', $result['state']);
        self::assertSame('verified', $result['reason']);
        self::assertSame([
            'network' => true, 'authentication' => true, 'kv_v2' => true,
            'certificate_read' => true, 'certificate_write' => true,
        ], $result['capabilities']);
        self::assertCount(4, $transport->requests);
        self::assertSame('GET', $transport->requests[0]['method']);
        self::assertNull($transport->requests[0]['token']);
        self::assertSame('fixture-token', $transport->requests[1]['token']);
        self::assertSame('POST', $transport->requests[3]['method']);
        self::assertSame(
            ['paths' => ['nfse/data/pfx/__nfse_capability_probe__']],
            $transport->requests[3]['body'],
        );
        self::assertStringNotContainsString('fixture-token', json_encode($result));
    }

    public function testAppRoleLoginUsesOnlyIssuedTokenForSubsequentRequests(): void
    {
        $steps = $this->successResponses();
        array_splice($steps, 1, 0, [[
            'status' => 200,
            'body' => ['auth' => ['client_token' => 'issued-fixture-token']],
        ]]);
        $transport = new FakeVaultDiagnosticTransport($steps);
        $result = (new VaultDiagnostics($transport))->check($this->config([
            'token' => null, 'roleId' => 'fixture-role', 'secretId' => 'fixture-secret',
        ]));

        self::assertSame('operational', $result['state']);
        self::assertCount(5, $transport->requests);
        self::assertSame('POST', $transport->requests[1]['method']);
        self::assertSame(['role_id' => 'fixture-role', 'secret_id' => 'fixture-secret'], $transport->requests[1]['body']);
        self::assertNull($transport->requests[1]['token']);
        self::assertSame('issued-fixture-token', $transport->requests[2]['token']);
        self::assertStringNotContainsString('issued-fixture-token', json_encode($result));
        self::assertStringNotContainsString('fixture-secret', json_encode($result));
    }

    public function testMissingOrRejectedAppRoleTokenFailsClosed(): void
    {
        foreach ([
            ['status' => 200, 'body' => ['auth' => []]],
            ['status' => 403, 'body' => ['errors' => ['synthetic-sensitive-upstream']]],
        ] as $login) {
            $transport = new FakeVaultDiagnosticTransport([
                ['status' => 200, 'body' => []],
                $login,
            ]);
            $result = (new VaultDiagnostics($transport))->check($this->config([
                'token' => null, 'roleId' => 'role', 'secretId' => 'secret',
            ]));

            self::assertSame('failed', $result['state']);
            self::assertSame('authentication_failed', $result['reason']);
            self::assertCount(2, $transport->requests);
            self::assertStringNotContainsString('synthetic-sensitive-upstream', json_encode($result));
        }
    }

    public function testSealedAndDrSecondaryServersDoNotPassHealthCheck(): void
    {
        foreach ([472, 501, 503] as $status) {
            $transport = new FakeVaultDiagnosticTransport([['status' => $status, 'body' => ['errors' => ['sensitive']]]]);
            $result = (new VaultDiagnostics($transport))->check($this->config());

            self::assertSame('server_unavailable', $result['reason']);
            self::assertTrue($result['capabilities']['network']);
            self::assertFalse($result['capabilities']['authentication']);
            self::assertCount(1, $transport->requests);
        }
    }

    public function testHealthyStandbyMayProceedToAuthAndCapabilityChecks(): void
    {
        foreach ([429, 473] as $status) {
            $transport = new FakeVaultDiagnosticTransport($this->successResponses(health: $status));
            $result = (new VaultDiagnostics($transport))->check($this->config());

            self::assertSame('operational', $result['state']);
            self::assertCount(4, $transport->requests);
        }
    }

    public function testInvalidTokenNeverQueriesMountOrPolicies(): void
    {
        $transport = new FakeVaultDiagnosticTransport([
            ['status' => 200, 'body' => []],
            ['status' => 403, 'body' => ['errors' => ['sensitive-upstream-body']]],
        ]);
        $result = (new VaultDiagnostics($transport))->check($this->config());

        self::assertSame('authentication_failed', $result['reason']);
        self::assertTrue($result['capabilities']['network']);
        self::assertFalse($result['capabilities']['authentication']);
        self::assertCount(2, $transport->requests);
        self::assertStringNotContainsString('sensitive-upstream-body', json_encode($result));
    }

    public function testNonKv2MountFailsBeforePermissionQuery(): void
    {
        $steps = $this->successResponses();
        $steps[2] = ['status' => 200, 'body' => ['data' => ['type' => 'kv', 'options' => ['version' => '1']]]];
        $transport = new FakeVaultDiagnosticTransport($steps);
        $result = (new VaultDiagnostics($transport))->check($this->config());

        self::assertSame('kv_v2_unavailable', $result['reason']);
        self::assertTrue($result['capabilities']['authentication']);
        self::assertFalse($result['capabilities']['kv_v2']);
        self::assertCount(3, $transport->requests);
    }

    public function testDeniedOrMalformedCapabilityResponseIsNeverOperational(): void
    {
        foreach ([
            [['status' => 403, 'body' => []], 'permission_check_failed'],
            [['status' => 200, 'body' => ['capabilities' => ['read', 'update']]], 'insufficient_capabilities'],
            [['status' => 200, 'body' => ['capabilities' => ['root', 'deny']]], 'insufficient_capabilities'],
            [['status' => 200, 'body' => ['capabilities' => 'read']], 'permission_check_failed'],
        ] as [$response, $expected]) {
            $steps = $this->successResponses();
            $steps[3] = $response;
            $transport = new FakeVaultDiagnosticTransport($steps);
            $result = (new VaultDiagnostics($transport))->check($this->config());

            self::assertSame('failed', $result['state']);
            self::assertSame($expected, $result['reason']);
            self::assertTrue($result['capabilities']['kv_v2']);
            self::assertFalse($result['capabilities']['certificate_write']);
            self::assertCount(4, $transport->requests);
        }
    }

    public function testTransportExceptionsReturnOnlyCanonicalReason(): void
    {
        $transport = new FakeVaultDiagnosticTransport([
            ['status' => 200, 'body' => []],
            new \RuntimeException('fixture secret should never be returned'),
        ]);
        $result = (new VaultDiagnostics($transport))->check($this->config());

        self::assertSame('failed', $result['state']);
        self::assertSame('network_failure', $result['reason']);
        self::assertTrue($result['capabilities']['network']);
        self::assertFalse($result['capabilities']['authentication']);
        self::assertStringNotContainsString('fixture secret', json_encode($result));
    }
}

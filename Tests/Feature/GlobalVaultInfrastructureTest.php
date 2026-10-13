<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Jobs\Auth\CreateUser;
use App\Models\Common\Company;
use App\Traits\Permissions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Nfse\Support\GlobalVaultSettings;
use Modules\Nfse\Support\VaultConfig;
use Modules\Nfse\Support\VaultDiagnostics;
use Tests\Feature\FeatureTestCase;

/** Test-only queued consumer: the handler reads the shared configuration. */
final class GlobalVaultResolutionJob implements ShouldQueue
{
    use Queueable;

    /** @var null|array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string} */
    public static ?array $resolved = null;

    public function handle(): void
    {
        self::$resolved = VaultConfig::secretStoreConfig();
    }
}

final class GlobalVaultInfrastructureTest extends FeatureTestCase
{
    use Permissions;

    public function testDifferentActiveCompaniesAndQueuedConsumersUseTheSameGlobalInfrastructure(): void
    {
        $this->loginAs();
        (new GlobalVaultSettings())->save([
            'addr' => 'https://vault.example.test',
            'mount' => '/nfse',
            'auth_mode' => 'token',
            'token' => 'synthetic-shared-token',
        ]);
        $originalCompany = $this->company;
        // Akaunting stores the company name in settings, not in the companies
        // table. Create the second tenant using its actual database columns.
        $otherCompanyId = DB::table('companies')->insertGetId([
            'domain' => 'vault-secondary.example.test',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCompany = Company::query()->findOrFail($otherCompanyId);
        self::assertNotSame((int) $originalCompany->id, (int) $otherCompany->id);

        try {
            $otherCompany->makeCurrent();
            setting()->set([
                'nfse.bao_addr' => 'https://wrong-company.example.test',
                'nfse.bao_token' => 'synthetic-company-only-token',
            ]);
            setting()->save();

            self::assertSame('synthetic-shared-token', VaultConfig::secretStoreConfig()['token']);

            GlobalVaultResolutionJob::$resolved = null;
            Bus::dispatchSync(new GlobalVaultResolutionJob());
            self::assertSame('synthetic-shared-token', GlobalVaultResolutionJob::$resolved['token'] ?? null);
            self::assertSame('https://vault.example.test', GlobalVaultResolutionJob::$resolved['addr'] ?? null);
        } finally {
            $originalCompany->makeCurrent();
        }

        self::assertSame('synthetic-shared-token', VaultConfig::secretStoreConfig()['token']);
    }

    public function testSettingsViewerCanDiagnoseButCannotMutateGlobalInfrastructure(): void
    {
        $this->loginAs();
        $store = new GlobalVaultSettings();
        $store->save([
            'addr' => 'https://vault.example.test',
            'mount' => '/nfse',
            'auth_mode' => 'token',
            'token' => 'synthetic-protected-token',
        ]);

        $role = $this->createRole('nfse-vault-readonly');
        $this->attachPermission($role, 'read-admin-panel');
        $this->attachPermission($role, 'read-nfse-settings');

        $user = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$this->company->id],
            'roles' => [$role->id],
        ])));

        $this->withExceptionHandling()->loginAs($user);

        $this->get(route('nfse.settings.edit'))
            ->assertOk()
            ->assertDontSee('synthetic-protected-token');

        Http::fake([
            'https://vault.example.test/v1/sys/health' => Http::response([], 503),
        ]);
        $this->post(route('nfse.settings.vault.diagnostics'))
            ->assertOk()
            ->assertJsonPath('state', 'failed')
            ->assertDontSee('synthetic-protected-token');

        $this->patch(route('nfse.settings.vault'), [
            'auth_mode_ui' => 'token',
            'nfse' => [
                'bao_addr' => 'https://attacker.example.test',
                'bao_mount' => '/nfse',
                'bao_token' => 'synthetic-overwrite-attempt',
            ],
        ])->assertForbidden();

        self::assertSame('synthetic-protected-token', $store->effective()['token']);
        self::assertSame('https://vault.example.test', $store->effective()['addr']);
    }

    public function testUserWithoutSettingsPermissionCannotQueryAdministrativeVaultData(): void
    {
        $this->loginAs();
        $role = $this->createRole('nfse-vault-no-access');
        $this->attachPermission($role, 'read-admin-panel');

        $user = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$this->company->id],
            'roles' => [$role->id],
        ])));

        $this->withExceptionHandling()->loginAs($user);
        $this->get(route('nfse.settings.edit'))->assertForbidden();
        $this->post(route('nfse.settings.vault.diagnostics'))->assertForbidden();
        $this->patch(route('nfse.settings.vault'))->assertForbidden();
    }

    public function testCorruptedGlobalRecordDoesNotHideFiscalSettingsAndCanBeReplaced(): void
    {
        $this->loginAs();
        setting()->set(['nfse.cnpj_prestador' => '11222333000181']);
        setting()->save();

        DB::table('nfse_vault_infrastructure')->updateOrInsert(['id' => 1], [
            'encrypted_payload' => 'synthetic-invalid-ciphertext',
            'updated_at' => now(),
        ]);

        $this->get(route('nfse.settings.edit'))
            ->assertOk()
            ->assertSee('vault-configuration-unreadable', false)
            ->assertViewHas('settings', static function ($settings): bool {
                return !array_key_exists('bao_token', $settings)
                    && !array_key_exists('bao_role_id', $settings)
                    && !array_key_exists('bao_secret_id', $settings);
            });

        $this->post(route('nfse.settings.vault.diagnostics'))
            ->assertOk()
            ->assertJsonPath('state', 'failed')
            ->assertJsonPath('reason', 'configuration_unreadable')
            ->assertDontSee('synthetic-invalid-ciphertext');

        $this->patch(route('nfse.settings.vault'), [
            'auth_mode_ui' => 'token',
            'nfse' => [
                'bao_addr' => 'https://vault.example.test',
                'bao_mount' => '/nfse',
                'bao_token' => 'synthetic-replacement-token',
            ],
        ])->assertRedirect(route('nfse.settings.edit', ['tab' => 'vault']))
            ->assertSessionHas('success');

        self::assertSame('synthetic-replacement-token', VaultConfig::secretStoreConfig()['token']);
        self::assertSame('11222333000181', (string) setting('nfse.cnpj_prestador'));
    }

    public function testSettingsViewNeverReceivesPlaintextGlobalCredentials(): void
    {
        $this->loginAs();
        (new GlobalVaultSettings())->save([
            'addr' => 'https://vault.example.test',
            'mount' => '/nfse',
            'auth_mode' => 'approle',
            'roleId' => 'synthetic-private-role',
            'secretId' => 'synthetic-private-secret',
        ]);

        $this->get(route('nfse.settings.edit'))
            ->assertOk()
            ->assertDontSee('synthetic-private-role')
            ->assertDontSee('synthetic-private-secret')
            ->assertViewHas('settings', static function ($settings): bool {
                return !array_key_exists('bao_token', $settings)
                    && !array_key_exists('bao_role_id', $settings)
                    && !array_key_exists('bao_secret_id', $settings);
            });
    }

    public function testGlobalConfigurationIsIndependentOfActiveCompanySettings(): void
    {
        $this->loginAs();
        (new GlobalVaultSettings())->save([
            'addr' => 'https://vault.example.test',
            'mount' => '/nfse',
            'auth_mode' => 'token',
            'token' => 'fixture-global-token',
        ]);

        setting()->set(['nfse.bao_addr' => 'https://wrong-company.example.test', 'nfse.bao_token' => 'company-token']);
        setting()->save();

        $expected = VaultConfig::secretStoreConfig();
        self::assertSame('https://vault.example.test', $expected['addr']);
        self::assertSame('fixture-global-token', $expected['token']);
        self::assertNotSame('fixture-global-token', (string) DB::table('nfse_vault_infrastructure')->value('encrypted_payload'));

        setting()->set(['nfse.bao_addr' => 'https://second-company.example.test', 'nfse.bao_token' => 'second-token']);
        setting()->save();

        self::assertSame($expected, VaultConfig::secretStoreConfig());
    }

    public function testDiagnosticReadIsSideEffectFreeAndDoesNotDiscloseToken(): void
    {
        $this->loginAs();
        (new GlobalVaultSettings())->save([
            'addr' => 'https://vault.example.test',
            'mount' => '/nfse',
            'auth_mode' => 'token',
            'token' => 'fixture-global-token',
        ]);

        Http::fake([
            'https://vault.example.test/v1/sys/health' => Http::response(['initialized' => true, 'sealed' => false], 200),
            'https://vault.example.test/v1/auth/token/lookup-self' => Http::response(['data' => ['id' => 'redacted']], 200),
            'https://vault.example.test/v1/sys/internal/ui/mounts/nfse' => Http::response(['data' => ['type' => 'kv', 'options' => ['version' => '2']]], 200),
            'https://vault.example.test/v1/sys/capabilities-self' => Http::response(['capabilities' => ['read', 'create', 'update']], 200),
        ]);
        $before = DB::table('nfse_vault_infrastructure')->value('encrypted_payload');
        $response = $this->post(route('nfse.settings.vault.diagnostics'))
            ->assertOk()
            ->assertJsonPath('state', 'operational')
            ->assertJsonPath('capabilities.certificate_write', true);
        $response->assertDontSee('fixture-global-token');
        self::assertSame($before, DB::table('nfse_vault_infrastructure')->value('encrypted_payload'));
        Http::assertSentCount(4);
    }

    public function testFailedAuthenticationNeverDisclosesUpstreamBody(): void
    {
        Http::fake([
            'https://vault.example.test/v1/sys/health' => Http::response([], 200),
            'https://vault.example.test/v1/auth/token/lookup-self' => Http::response(['errors' => ['synthetic sensitive failure']], 403),
        ]);

        $status = (new VaultDiagnostics())->check([
            'addr' => 'https://vault.example.test', 'mount' => '/nfse',
            'token' => 'synthetic-secret', 'roleId' => null, 'secretId' => null,
        ]);

        self::assertSame('failed', $status['state']);
        self::assertSame('authentication_failed', $status['reason']);
        self::assertStringNotContainsString('synthetic sensitive failure', json_encode($status));
        self::assertStringNotContainsString('synthetic-secret', json_encode($status));
    }

    public function testUnconfiguredDiagnosticMakesNoNetworkCalls(): void
    {
        Http::fake();
        $status = (new VaultDiagnostics())->check([
            'addr' => '', 'mount' => '/nfse', 'token' => null,
            'roleId' => null, 'secretId' => null,
        ]);
        self::assertSame('unconfigured', $status['state']);
        Http::assertNothingSent();
    }

    public function testExplicitCredentialClearMakesVaultUnconfiguredAndAllowsRestore(): void
    {
        $this->loginAs();
        $store = new GlobalVaultSettings();
        $store->save([
            'addr' => 'https://vault.example.test',
            'mount' => '/nfse',
            'auth_mode' => 'token',
            'token' => 'synthetic-initial-token',
        ]);

        $this->patch(route('nfse.settings.vault'), [
            'auth_mode_ui' => 'token',
            'nfse' => [
                'bao_addr' => 'https://vault.example.test',
                'bao_mount' => '/nfse',
                'clear_bao_token' => '1',
            ],
        ])->assertRedirect(route('nfse.settings.edit', ['tab' => 'vault']))
            ->assertSessionHas('success');

        self::assertNull($store->effective()['token']);
        self::assertFalse(VaultConfig::configured($store->effective()));

        $this->patch(route('nfse.settings.vault'), [
            'auth_mode_ui' => 'token',
            'nfse' => [
                'bao_addr' => 'https://vault.example.test',
                'bao_mount' => '/nfse',
                'bao_token' => 'synthetic-restored-token',
            ],
        ])->assertRedirect(route('nfse.settings.edit', ['tab' => 'vault']))
            ->assertSessionHas('success');

        self::assertSame('synthetic-restored-token', $store->effective()['token']);
        self::assertTrue(VaultConfig::configured($store->effective()));
    }

    public function testNewVaultCannotBeSavedWithoutAnyAuthentication(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new GlobalVaultSettings())->save([
            'addr' => 'https://vault.example.test',
            'mount' => '/nfse',
            'auth_mode' => 'token',
            'token' => null,
        ]);
    }

    public function testSavingTokenModeRemovesAppRoleFieldsAndRetainsBlankToken(): void
    {
        $store = new GlobalVaultSettings();
        $store->save([
            'addr' => 'https://vault.example.test', 'mount' => '/nfse',
            'auth_mode' => 'token', 'token' => 'first-token',
        ]);
        $store->save([
            'addr' => 'https://vault.example.test', 'mount' => '/nfse',
            'auth_mode' => 'token',
        ]);
        self::assertSame('first-token', $store->effective()['token']);

        $store->save([
            'addr' => 'https://vault.example.test', 'mount' => '/nfse',
            'auth_mode' => 'approle', 'roleId' => 'role', 'secretId' => 'secret',
        ]);
        self::assertNull($store->effective()['token']);
        self::assertSame('role', $store->effective()['roleId']);
        self::assertSame('secret', $store->effective()['secretId']);
    }

    public function testChangingVaultEndpointRejectsOmittedTokenWithoutOverwritingExistingSettings(): void
    {
        $this->loginAs();
        $store = new GlobalVaultSettings();
        $store->save([
            'addr' => 'https://vault-a.example.test',
            'mount' => '/nfse',
            'auth_mode' => 'token',
            'token' => 'synthetic-token-a',
        ]);
        $before = $store->effective();

        $this->patch(route('nfse.settings.vault'), [
            'auth_mode_ui' => 'token',
            'nfse' => [
                'bao_addr' => 'https://vault-b.example.test',
                'bao_mount' => '/nfse',
            ],
        ])->assertRedirect(route('nfse.settings.edit', ['tab' => 'vault']))
            ->assertSessionHas('error');
        self::assertSame($before, $store->effective());

        $this->patch(route('nfse.settings.vault'), [
            'auth_mode_ui' => 'token',
            'nfse' => [
                'bao_addr' => 'https://vault-b.example.test',
                'bao_mount' => '/nfse',
                'bao_token' => 'synthetic-token-b',
            ],
        ])->assertRedirect(route('nfse.settings.edit', ['tab' => 'vault']))
            ->assertSessionHas('success');
        self::assertSame('https://vault-b.example.test', $store->effective()['addr']);
        self::assertSame('synthetic-token-b', $store->effective()['token']);
    }

    public function testChangingVaultEndpointRejectsPartialAppRoleWithoutOverwritingExistingSettings(): void
    {
        $this->loginAs();
        $store = new GlobalVaultSettings();
        $store->save([
            'addr' => 'https://vault-a.example.test',
            'mount' => '/nfse',
            'auth_mode' => 'approle',
            'roleId' => 'synthetic-role-a',
            'secretId' => 'synthetic-secret-a',
        ]);
        $before = $store->effective();

        // Unchanged endpoint may retain existing AppRole credentials.
        $store->save([
            'addr' => 'https://vault-a.example.test',
            'mount' => '/nfse',
            'auth_mode' => 'approle',
        ]);
        self::assertSame($before, $store->effective());

        $this->patch(route('nfse.settings.vault'), [
            'auth_mode_ui' => 'approle',
            'nfse' => [
                'bao_addr' => 'https://vault-b.example.test',
                'bao_mount' => '/nfse',
                'bao_role_id' => 'synthetic-role-b',
            ],
        ])->assertRedirect(route('nfse.settings.edit', ['tab' => 'vault']))
            ->assertSessionHas('error');
        self::assertSame($before, $store->effective());

        $this->patch(route('nfse.settings.vault'), [
            'auth_mode_ui' => 'approle',
            'nfse' => [
                'bao_addr' => 'https://vault-b.example.test',
                'bao_mount' => '/nfse',
                'bao_role_id' => 'synthetic-role-b',
                'bao_secret_id' => 'synthetic-secret-b',
            ],
        ])->assertRedirect(route('nfse.settings.edit', ['tab' => 'vault']))
            ->assertSessionHas('success');
        self::assertSame('https://vault-b.example.test', $store->effective()['addr']);
        self::assertSame('synthetic-role-b', $store->effective()['roleId']);
        self::assertSame('synthetic-secret-b', $store->effective()['secretId']);
    }
}

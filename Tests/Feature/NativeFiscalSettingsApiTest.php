<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Modules\Nfse\Support\GlobalVaultSettings;
use Modules\Nfse\Support\VaultConfig;
use Tests\Feature\FeatureTestCase;

final class NativeFiscalSettingsApiTest extends FeatureTestCase
{
    public function testBrowserVaultJsonNeverDisclosesSecretsAndPreservesOmittedCredential(): void
    {
        $this->loginAs();
        (new GlobalVaultSettings())->save([
            'addr' => 'https://vault.example.test',
            'mount' => '/nfse',
            'auth_mode' => 'token',
            'token' => 'synthetic-credential-never-expose',
        ]);

        $this->get(route('nfse.settings.vault.status'))
            ->assertOk()
            ->assertJsonPath('data.addr', 'https://vault.example.test')
            ->assertJsonPath('data.mount', '/nfse')
            ->assertJsonPath('data.auth_mode', 'token')
            ->assertJsonPath('data.ready', true)
            ->assertDontSee('synthetic-credential-never-expose');

        $this->get(route('nfse.settings.edit'))
            ->assertOk()
            ->assertDontSee('synthetic-credential-never-expose')
            ->assertDontSee('https://vault.example.test')
            ->assertSee('nfse-company-admin-config');

        $this->patchJson(route('nfse.settings.vault'), [
            'auth_mode_ui' => 'token',
            'nfse' => [
                'bao_addr' => 'https://vault.example.test',
                'bao_mount' => '/other',
                'bao_token' => '',
            ],
        ])->assertOk()
            ->assertJsonPath('data.mount', '/other')
            ->assertDontSee('synthetic-credential-never-expose');

        self::assertSame(
            'synthetic-credential-never-expose',
            VaultConfig::secretStoreConfig()['token'],
        );

        $this->withExceptionHandling()->patchJson(route('nfse.settings.vault'), [
            'auth_mode_ui' => 'token',
            'nfse' => [
                'bao_addr' => 'not a url',
                'bao_mount' => '/other',
            ],
        ])->assertUnprocessable();
        self::assertSame('https://vault.example.test', VaultConfig::secretStoreConfig()['addr']);
    }

    public function testSettingsNativeRoutesReuseExistingControllersAndPermissions(): void
    {
        foreach ([
            'api.nfse.settings.company-profiles.index' => ['GET', 'read-nfse-settings'],
            'api.nfse.settings.company-profiles.store' => ['POST', 'update-nfse-settings'],
            'api.nfse.settings.company-profiles.update' => ['PATCH', 'update-nfse-settings'],
            'api.nfse.settings.company-profiles.destroy' => ['DELETE', 'delete-nfse-settings'],
            'api.nfse.settings.company-profiles.cleanup' => ['POST', 'delete-nfse-settings'],
            'api.nfse.settings.vault.show' => ['GET', 'read-nfse-settings'],
            'api.nfse.settings.vault.update' => ['PATCH', 'update-nfse-settings'],
            'api.nfse.settings.vault.diagnostics' => ['POST', 'read-nfse-settings'],
            'api.nfse.certificates.inspect' => ['POST', 'update-nfse-settings'],
        ] as $name => [$method, $permission]) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, 'Missing route ' . $name);
            self::assertContains($method, $route->methods());
            self::assertContains('permission:' . $permission, $route->gatherMiddleware());
        }
    }
}

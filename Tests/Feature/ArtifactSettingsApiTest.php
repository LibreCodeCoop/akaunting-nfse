<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Modules\Nfse\Http\Controllers\SettingsController;
use Tests\Feature\FeatureTestCase;

final class ArtifactSettingsApiTest extends FeatureTestCase
{
    public function testBrowserAndNativeApiShareReadAndWritePermissions(): void
    {
        foreach ([
            'nfse.settings.artifacts.show' => ['GET', 'read-nfse-settings'],
            'nfse.settings.artifacts' => ['PATCH', 'update-nfse-settings'],
            'api.nfse.settings.artifacts.show' => ['GET', 'read-nfse-settings'],
            'api.nfse.settings.artifacts.update' => ['PATCH', 'update-nfse-settings'],
        ] as $name => [$method, $permission]) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertContains($method, $route->methods());
            self::assertContains('permission:' . $permission, $route->gatherMiddleware());
        }
    }

    public function testReadNeverReturnsUrlCredentialsEvenWhenLegacySettingsContainThem(): void
    {
        $this->loginAs();
        setting([
            'nfse.webdav_url' => 'https://embedded-user:embedded-password@dav.example.test/private?token=hidden',
            'nfse.webdav_username' => 'synthetic-service-user',
            'nfse.webdav_password' => 'synthetic-secret-do-not-disclose',
            'nfse.webdav_path_template' => 'nfse/{cnpj}/2026',
            'nfse.webdav_filename_template' => '{chave_acesso}',
            'nfse.webdav_store_xml' => true,
            'nfse.webdav_store_pdf' => false,
        ]);
        setting()->save();

        $this->getJson(route('nfse.settings.artifacts.show'))
            ->assertOk()
            ->assertJsonPath('data.host', 'dav.example.test')
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.username_configured', true)
            ->assertJsonPath('data.password_configured', true)
            ->assertJsonPath('data.store_pdf', false)
            ->assertDontSee('synthetic-secret-do-not-disclose')
            ->assertDontSee('embedded-password')
            ->assertDontSee('private?token=hidden')
            ->assertDontSee('synthetic-service-user');
        $this->get(route('nfse.settings.edit'))
            ->assertOk()
            ->assertDontSee('synthetic-secret-do-not-disclose')
            ->assertSee('nfse-artifact-settings');
    }

    public function testPartialJsonUpdatePreservesStoredCredentialsOnSameEndpoint(): void
    {
        $this->loginAs();
        setting([
            'nfse.webdav_url' => 'https://dav.example.test/storage',
            'nfse.webdav_username' => 'synthetic-old-user',
            'nfse.webdav_password' => 'synthetic-old-secret',
            'nfse.webdav_store_xml' => true,
            'nfse.webdav_store_pdf' => true,
        ]);
        setting()->save();

        app()->instance(SettingsController::class, new class extends SettingsController {
            protected function assertWebDavConnection(string $url, string $username, string $password): void
            {
                \PHPUnit\Framework\Assert::assertSame('https://dav.example.test/storage', $url);
                \PHPUnit\Framework\Assert::assertSame('synthetic-old-user', $username);
                \PHPUnit\Framework\Assert::assertSame('synthetic-old-secret', $password);
            }
        });

        $this->patchJson(route('nfse.settings.artifacts'), [
            'nfse' => [
                'webdav_password' => '',
                'webdav_username' => '',
                'webdav_store_xml' => false,
            ],
        ])->assertOk()
            ->assertJsonPath('data.store_xml', false)
            ->assertJsonPath('data.password_configured', true)
            ->assertDontSee('synthetic-old-secret');
        self::assertSame('synthetic-old-secret', setting('nfse.webdav_password'));
        self::assertSame('synthetic-old-user', setting('nfse.webdav_username'));
    }

    public function testEmbeddedCredentialsAreRejectedBeforeContactOrMutation(): void
    {
        $this->withExceptionHandling()->loginAs();
        $this->patchJson(route('nfse.settings.artifacts'), [
            'nfse' => ['webdav_url' => 'https://password:token@dav.example.test/dav'],
        ])->assertUnprocessable()
            ->assertJsonPath('reason', 'invalid_webdav_url')
            ->assertDontSee('password:token');
        self::assertSame('', setting('nfse.webdav_url', ''));
    }

    public function testWebDavConnectionErrorNeverLeaksUpstreamExceptionOrInput(): void
    {
        $this->withExceptionHandling()->loginAs();
        app()->instance(SettingsController::class, new class extends SettingsController {
            protected function assertWebDavConnection(string $url, string $username, string $password): void
            {
                throw new \RuntimeException('Authorization: Basic VERY-SENSITIVE-SYNTHETIC-HEADER');
            }
        });
        $this->patchJson(route('nfse.settings.artifacts'), [
            'nfse' => [
                'webdav_url' => 'https://dav.example.test/storage',
                'webdav_username' => 'testuser',
                'webdav_password' => 'synthetic-private-credential',
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('reason', 'webdav_connection_failed')
            ->assertDontSee('VERY-SENSITIVE-SYNTHETIC-HEADER')
            ->assertDontSee('synthetic-private-credential');
        self::assertSame('', setting('nfse.webdav_url', ''));
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Modules\Nfse\Support\GlobalVaultSettings;
use Tests\Feature\FeatureTestCase;

final class SettingsReadinessNavigationTest extends FeatureTestCase
{
    public function testAdministrativeScreenSeparatesCompanyFiscalStateFromVault(): void
    {
        $this->loginAs()->get(route('nfse.settings.edit'))
            ->assertOk()
            ->assertSee('nfse-companies-title', false)
            ->assertSee('nfse-vault-title', false)
            ->assertSee('vault-diagnostics', false)
            ->assertDontSee('nfse-readiness-summary', false);
    }

    public function testAdminScreenUsesGlobalVaultWithoutReadingLegacyCertificateSecrets(): void
    {
        $this->loginAs();
        (new GlobalVaultSettings())->save([
            'addr' => 'https://global-vault.example.test',
            'mount' => '/nfse',
            'auth_mode' => 'token',
            'token' => 'synthetic-global-vault-token',
        ]);
        setting()->set([
            'nfse.cnpj_prestador' => '12345678000190',
            'nfse.certificate_valid_to' => 1798761600,
            'nfse.bao_addr' => 'https://legacy-company.example.test',
            'nfse.bao_token' => 'synthetic-legacy-company-token',
        ]);
        setting()->save();

        Http::fake();

        $this->get(route('nfse.settings.edit'))
            ->assertOk()
            ->assertViewHas('settings', static fn (array $settings): bool => $settings === [
                'bao_addr' => 'https://global-vault.example.test',
                'bao_mount' => '/nfse',
            ])
            ->assertViewHas('vaultUiState', static fn (array $state): bool => ($state['auth_mode'] ?? null) === 'token'
                && ($state['ready'] ?? false) === true
                && !array_key_exists('certificate_secret_available', $state))
            ->assertDontSee('synthetic-legacy-company-token')
            ->assertDontSee('https://legacy-company.example.test');

        Http::assertNothingSent();
    }
}

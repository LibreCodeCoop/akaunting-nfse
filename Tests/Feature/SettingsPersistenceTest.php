<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Tests\Feature\FeatureTestCase;

final class SettingsPersistenceTest extends FeatureTestCase
{
    public function testGlobalVaultPersistsButDeprecatedFiscalWriterIsDisabled(): void
    {
        $this->loginAs();
        $this->patch(route('nfse.settings.vault'), [
            'auth_mode_ui' => 'token',
            'nfse' => [
                'bao_addr' => 'https://vault.example.test',
                'bao_mount' => '/secret/',
                'bao_token' => 'test-token',
            ],
        ])->assertRedirect(route('nfse.settings.edit', ['tab' => 'vault']))->assertSessionHas('success');

        $this->patch(route('nfse.settings.update'), [
            'nfse' => [
                'cnpj_prestador' => '11222333000181',
                'municipio_ibge' => '3304557',
                'item_lista_servico' => '0107',
                'codigo_tributacao_nacional' => '010701',
            ],
        ])->assertStatus(410);

        self::assertNull(setting('nfse.cnpj_prestador'));
        self::assertNull(setting('nfse.item_lista_servico'));
        self::assertSame('https://vault.example.test', \Modules\Nfse\Support\VaultConfig::secretStoreConfig()['addr']);
        self::assertSame('test-token', \Modules\Nfse\Support\VaultConfig::secretStoreConfig()['token']);
    }

}

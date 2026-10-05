<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Tests\Feature\FeatureTestCase;

final class SettingsPersistenceTest extends FeatureTestCase
{
    public function testAuthenticatedSettingsUpdatePersistsFiscalAndVaultConfiguration(): void
    {
        $this->loginAs()
            ->patch(route('nfse.settings.update'), [
                'nfse' => [
                    'cnpj_prestador' => '11222333000181',
                    'uf' => 'rj',
                    'municipio_nome' => 'Rio de Janeiro',
                    'municipio_ibge' => '3304557',
                    'sandbox_mode' => '1',
                    'emission_policy' => 'emit_on_send',
                    'bao_addr' => 'https://vault.example.test',
                    'bao_mount' => '/secret/',
                    'bao_token' => 'test-token',
                    'item_lista_servico' => '1.07',
                    'codigo_tributacao_nacional' => '010701',
                ],
            ])
            ->assertRedirect(route('nfse.settings.edit'))
            ->assertSessionHas('success');

        self::assertSame('11222333000181', (string) setting('nfse.cnpj_prestador'));
        self::assertSame('RJ', (string) setting('nfse.uf'));
        self::assertSame('Rio de Janeiro', (string) setting('nfse.municipio_nome'));
        self::assertSame('3304557', (string) setting('nfse.municipio_ibge'));
        self::assertSame('1', (string) setting('nfse.sandbox_mode'));
        self::assertSame('emit_on_send', (string) setting('nfse.emission_policy'));
        self::assertSame('https://vault.example.test', (string) setting('nfse.bao_addr'));
        self::assertSame('secret', (string) setting('nfse.bao_mount'));
        self::assertSame('test-token', (string) setting('nfse.bao_token'));
        self::assertSame('0107', (string) setting('nfse.item_lista_servico'));
        self::assertSame('010701', (string) setting('nfse.codigo_tributacao_nacional'));
    }
}

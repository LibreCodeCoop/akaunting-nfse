<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Tests\Feature\FeatureTestCase;

final class IssuerFiscalContactTest extends FeatureTestCase
{
    public function testFiscalIssuerFieldsPersistWithinActiveCompanyAndReloadInForm(): void
    {
        $this->loginAs();
        $companyId = (int) company_id();

        setting([
            'nfse.bao_addr' => 'https://vault.example.invalid',
            'nfse.bao_mount' => '/secret',
            'nfse.bao_token' => 'fixture-token',
        ]);
        setting()->save();

        DB::table('settings')->insert([
            'company_id' => $companyId + 50000,
            'key' => 'nfse.prestador_email',
            'value' => 'other-company@example.invalid',
        ]);

        $this->patch(route('nfse.settings.fiscal'), [
            'nfse' => [
                'cnpj_prestador' => '11222333000181',
                'uf' => 'RJ',
                'municipio_nome' => 'Curitiba',
                'municipio_ibge' => '4106902',
                'tributacao_issqn' => '1',
                'tipo_retencao_iss' => '1',
                'prestador_telefone' => '(00) 90000-0000',
                'prestador_email' => 'fiscal@example.invalid',
                'prestador_inscricao_municipal' => '123456',
            ],
        ])->assertRedirect(route('nfse.settings.edit', ['tab' => 'fiscal']))
            ->assertSessionHas('success');

        self::assertSame('(00) 90000-0000', (string) setting('nfse.prestador_telefone'));
        self::assertSame('fiscal@example.invalid', (string) setting('nfse.prestador_email'));
        self::assertSame('123456', (string) setting('nfse.prestador_inscricao_municipal'));
        self::assertSame('other-company@example.invalid', (string) DB::table('settings')
            ->where('company_id', $companyId + 50000)
            ->where('key', 'nfse.prestador_email')
            ->value('value'));

        $this->get(route('nfse.settings.edit', ['tab' => 'fiscal']))
            ->assertOk()
            ->assertSee('data-nfse-active-company="true"', false)
            ->assertSee('value="fiscal@example.invalid"', false)
            ->assertSee('value="123456"', false);
    }

    public function testConfiguredProviderPhoneAndEmailOverrideAkauntingCompanyOnlyInFiscalPayload(): void
    {
        $this->loginAs();
        setting([
            'nfse.prestador_telefone' => '(00) 90000-0000',
            'nfse.prestador_email' => 'issuer@example.invalid',
        ]);
        setting()->save();

        $controller = new class () extends InvoiceController {
            public function inspectIssuerContact(): array
            {
                return $this->providerContact();
            }
        };

        self::assertSame([
            'telefone' => '00900000000',
            'email' => 'issuer@example.invalid',
        ], $controller->inspectIssuerContact());
    }
}

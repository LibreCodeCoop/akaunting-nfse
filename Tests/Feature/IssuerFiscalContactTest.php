<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Modules\Nfse\Models\NfseCompanyProfile;
use Modules\Nfse\Support\NfseCompanyCertificatePath;
use Modules\Nfse\Support\NfseCompanyFiscalSettings;
use Tests\Feature\FeatureTestCase;

final class IssuerFiscalContactTest extends FeatureTestCase
{
    private const CNPJ = '11222333000181';

    public function testFiscalIssuerFieldsPersistWithinOwningCompanyAndReloadFromProfileApi(): void
    {
        $this->loginAs();
        $owner = (int) company_id();
        $other = $this->secondCompany();
        $this->profile($owner, ['prestador_email' => 'before@example.test']);
        $this->profile($other, [
            'prestador_email' => 'other-company@example.test',
            'prestador_inscricao_municipal' => '654321',
        ]);

        $this->patchJson(route('nfse.settings.company-profiles.update', $owner), [
            'fiscal_parameters' => [
                'uf' => 'RJ',
                'municipio_ibge' => '3303302',
                'prestador_telefone' => '(00) 90000-0000',
                'prestador_email' => 'fiscal@example.test',
                'prestador_inscricao_municipal' => '123456',
            ],
        ])->assertOk()
            ->assertJsonPath('data.company_id', $owner)
            ->assertJsonPath('data.fiscal_parameters.prestador_email', 'fiscal@example.test')
            ->assertJsonPath('data.fiscal_parameters.prestador_inscricao_municipal', '123456');

        $profiles = $this->getJson(route('nfse.settings.company-profiles.index'))
            ->assertOk()->json('profiles');
        $byCompany = array_column($profiles, null, 'company_id');

        self::assertSame('(00) 90000-0000', $byCompany[$owner]['fiscal_parameters']['prestador_telefone']);
        self::assertSame('fiscal@example.test', $byCompany[$owner]['fiscal_parameters']['prestador_email']);
        self::assertSame('123456', $byCompany[$owner]['fiscal_parameters']['prestador_inscricao_municipal']);
        self::assertSame('other-company@example.test', $byCompany[$other]['fiscal_parameters']['prestador_email']);
        self::assertSame('654321', $byCompany[$other]['fiscal_parameters']['prestador_inscricao_municipal']);

        $settings = new NfseCompanyFiscalSettings();
        self::assertSame('fiscal@example.test', $settings->value($owner, 'nfse.prestador_email'));
        self::assertSame('other-company@example.test', $settings->value($other, 'nfse.prestador_email'));
    }

    public function testSimplesApurationPersistsOnlyForOwnerWithoutGuessingSelection(): void
    {
        $this->loginAs();
        $owner = (int) company_id();
        $other = $this->secondCompany();
        $this->profile($owner, [
            'opcao_simples_nacional' => '3',
            'regime_apuracao_simples_nacional' => '1',
        ]);
        $this->profile($other, [
            'opcao_simples_nacional' => '3',
            'regime_apuracao_simples_nacional' => '3',
        ]);

        $this->patchJson(route('nfse.settings.company-profiles.update', $owner), [
            'fiscal_parameters' => ['regime_apuracao_simples_nacional' => '2'],
        ])->assertOk()
            ->assertJsonPath('data.fiscal_parameters.opcao_simples_nacional', '3')
            ->assertJsonPath('data.fiscal_parameters.regime_apuracao_simples_nacional', '2');

        $settings = new NfseCompanyFiscalSettings();
        self::assertSame('3', $settings->value($owner, 'nfse.opcao_simples_nacional'));
        self::assertSame('2', $settings->value($owner, 'nfse.regime_apuracao_simples_nacional'));
        self::assertSame('3', $settings->value($other, 'nfse.regime_apuracao_simples_nacional'));

        $profiles = $this->getJson(route('nfse.settings.company-profiles.index'))
            ->assertOk()->json('profiles');
        $byCompany = array_column($profiles, null, 'company_id');
        self::assertSame('2', $byCompany[$owner]['fiscal_parameters']['regime_apuracao_simples_nacional']);
        self::assertSame('3', $byCompany[$other]['fiscal_parameters']['regime_apuracao_simples_nacional']);
    }

    public function testSpecialRegimeRoundTripDoesNotModifyAnotherCompany(): void
    {
        $this->loginAs();
        $owner = (int) company_id();
        $other = $this->secondCompany();
        $this->profile($owner, ['regime_especial_tributacao' => '0']);
        $this->profile($other, ['regime_especial_tributacao' => '5']);

        $this->patchJson(route('nfse.settings.company-profiles.update', $owner), [
            'fiscal_parameters' => ['regime_especial_tributacao' => '9'],
        ])->assertOk()
            ->assertJsonPath('data.fiscal_parameters.regime_especial_tributacao', '9');

        $settings = new NfseCompanyFiscalSettings();
        self::assertSame('9', $settings->value($owner, 'nfse.regime_especial_tributacao'));
        self::assertSame('5', $settings->value($other, 'nfse.regime_especial_tributacao'));

        $profiles = $this->getJson(route('nfse.settings.company-profiles.index'))
            ->assertOk()->json('profiles');
        $byCompany = array_column($profiles, null, 'company_id');
        self::assertSame('9', $byCompany[$owner]['fiscal_parameters']['regime_especial_tributacao']);
        self::assertSame('5', $byCompany[$other]['fiscal_parameters']['regime_especial_tributacao']);
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

    /**
     * A metadata-only profile update does not require Vault access or an
     * actual certificate. The profile still has a distinct, valid reference.
     *
     * @param array<string,mixed> $parameters
     */
    private function profile(int $companyId, array $parameters): void
    {
        NfseCompanyProfile::query()->create([
            'company_id' => $companyId,
            'status' => 'active',
            'certificate_ref' => NfseCompanyCertificatePath::newReference(),
            'certificate_cnpj' => self::CNPJ,
            'certificate_valid_from' => time() - 3600,
            'certificate_valid_to' => time() + 3600,
            'fiscal_parameters' => $parameters,
        ]);
    }

    private function secondCompany(): int
    {
        $companyId = (int) DB::table('companies')->insertGetId([
            'domain' => 'synthetic-second-fiscal-company.example.test',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_companies')->insert([
            'user_id' => (int) auth()->id(),
            'company_id' => $companyId,
        ]);

        return $companyId;
    }
}

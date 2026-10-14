<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Application\MunicipalEmissionPreflight;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseCompanyProfile;
use Modules\Nfse\Support\NfseCompanyCertificatePath;
use Modules\Nfse\Models\MunicipalParameterSnapshot;
use Modules\Nfse\Support\InvoiceFiscalContextResolver;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Tests\Feature\FeatureTestCase;

final class MunicipalEstablishmentAttestationTest extends FeatureTestCase
{
    public function testAuthenticatedFiscalSettingsSaveAndInvalidateExplicitAttestation(): void
    {
        $this->loginAs();

        setting()->set([
            'nfse.bao_addr' => 'https://vault.example.test',
            'nfse.bao_mount' => '/secret',
            'nfse.bao_token' => 'synthetic-secret',
            'nfse.cnpj_prestador' => '11222333000181',
            'nfse.municipio_ibge' => '3303302',
        ]);
        setting()->save();

        (new \Modules\Nfse\Support\GlobalVaultSettings())->save([
            'addr' => 'https://vault.example.test',
            'mount' => '/secret',
            'auth_mode' => 'token',
            'token' => 'synthetic-global-fixture-token',
        ]);

        $fiscal = [
            'cnpj_prestador' => '11222333000181',
            'uf' => 'RJ',
            'municipio_nome' => 'Niterói',
            'municipio_ibge' => '3303302',
            'tributacao_issqn' => '1',
            'tipo_retencao_iss' => '1',
            'provider_establishment_confirmed' => '1',
        ];

        $this->patch(route('nfse.settings.fiscal'), ['nfse' => $fiscal])
            ->assertRedirect(route('nfse.settings.edit', ['tab' => 'fiscal']));
        self::assertSame('1', (string) setting('nfse.provider_establishment_confirmed'));
        self::assertSame('11222333000181', (string) setting('nfse.provider_establishment_confirmed_cnpj'));
        self::assertSame('3303302', (string) setting('nfse.provider_establishment_confirmed_municipality'));
        self::assertNotSame('', (string) setting('nfse.provider_establishment_confirmed_at'));
        self::assertSame((string) auth()->id(), (string) setting('nfse.provider_establishment_confirmed_by'));

        // A persisted confirmation cannot follow a changed IBGE municipality.
        $this->patch(route('nfse.settings.fiscal'), ['nfse' => array_merge($fiscal, [
            'municipio_nome' => 'Rio de Janeiro',
            'municipio_ibge' => '3304557',
        ])])->assertRedirect(route('nfse.settings.edit', ['tab' => 'fiscal']));

        self::assertSame('0', (string) setting('nfse.provider_establishment_confirmed'));
        self::assertSame('', (string) setting('nfse.provider_establishment_confirmed_municipality'));
        self::assertSame('', (string) setting('nfse.provider_establishment_confirmed_at'));
    }

    public function testLegacySettingsCannotForgeOrResurrectEstablishmentConfirmation(): void
    {
        $this->loginAs();
        setting()->set([
            'nfse.bao_addr' => 'https://vault.example.test',
            'nfse.bao_mount' => '/secret',
            'nfse.bao_token' => 'synthetic-secret',
            'nfse.cnpj_prestador' => '11222333000181',
            'nfse.municipio_ibge' => '3303302',
            'nfse.provider_establishment_confirmed' => '1',
            'nfse.provider_establishment_confirmed_cnpj' => '11222333000181',
            'nfse.provider_establishment_confirmed_municipality' => '3303302',
            'nfse.provider_establishment_confirmed_at' => '2026-10-12T09:00:00-03:00',
            'nfse.provider_establishment_confirmed_by' => 'synthetic-operator',
        ]);
        setting()->save();

        (new \Modules\Nfse\Support\GlobalVaultSettings())->save([
            'addr' => 'https://vault.example.test',
            'mount' => '/secret',
            'auth_mode' => 'token',
            'token' => 'synthetic-global-fixture-token',
        ]);

        $legacy = [
            'cnpj_prestador' => '11222333000181',
            'uf' => 'RJ',
            'municipio_nome' => 'Rio de Janeiro',
            'municipio_ibge' => '3304557',
            'bao_addr' => 'https://vault.example.test',
            'bao_mount' => '/secret',
            'bao_token' => 'synthetic-secret',
            'provider_establishment_confirmed' => '1',
            'provider_establishment_confirmed_cnpj' => '11222333000181',
            'provider_establishment_confirmed_municipality' => '3304557',
            'provider_establishment_confirmed_at' => '2030-01-01T00:00:00Z',
            'provider_establishment_confirmed_by' => 'forged-operator',
        ];

        $this->patch(route('nfse.settings.update'), ['nfse' => $legacy])
            ->assertRedirect(route('nfse.settings.edit'));
        self::assertSame('3304557', (string) setting('nfse.municipio_ibge'));
        self::assertSame('0', (string) setting('nfse.provider_establishment_confirmed'));
        self::assertSame('', (string) setting('nfse.provider_establishment_confirmed_cnpj'));
        self::assertSame('', (string) setting('nfse.provider_establishment_confirmed_at'));
        self::assertSame('', (string) setting('nfse.provider_establishment_confirmed_by'));

        // A later return to the original city cannot resurrect the evidence.
        $legacy['municipio_ibge'] = '3303302';
        $this->patch(route('nfse.settings.update'), ['nfse' => $legacy])
            ->assertRedirect(route('nfse.settings.edit'));
        self::assertSame('0', (string) setting('nfse.provider_establishment_confirmed'));
        self::assertSame('', (string) setting('nfse.provider_establishment_confirmed_municipality'));
    }

    public function testStoredIssuerAttestationSelectsOnlyExactDpsMunicipalSnapshot(): void
    {
        $this->loginAs();
        $invoice = $this->invoiceWithProfile('0107');
        $group = (new InvoiceFiscalContextResolver())->groups($invoice)[0];
        $this->confirmEstablishment();

        MunicipalParameterSnapshot::query()->create([
            'company_id' => (int) $invoice->company_id,
            'environment' => 'sandbox',
            'municipio_ibge' => '3303302',
            'service_code' => '010701123',
            'competence_date' => '2026-09-01',
            'payload' => ['aliquota' => ['aliquotas' => []]],
            'source_provenance' => [
                'contract_version' => 'synthetic-municipal-contract-v1',
                'endpoints' => ['aliquota' => [
                    'source_url' => 'https://adn.nfse.gov.br/parametrizacao/3303302/01.07.01.123/2026-09-01/aliquota',
                    'http_status' => 404,
                    'outcome' => 'not_found',
                ]],
            ],
            'fetched_at' => now(),
        ]);

        $preflight = new MunicipalEmissionPreflight();
        $matched = $preflight->evaluate($invoice, $this->dps($invoice), (string) $group['key']);

        self::assertTrue($matched['can_attempt']);
        self::assertSame('lc116_art3_default', $matched['context']['incidence_source']);
        self::assertSame('3303302', $matched['context']['incidence_municipality']);
        self::assertSame('2026-09-01', $matched['context']['competence']);
        self::assertSame('municipal_404_inconclusive', $matched['reason']);
        self::assertSame('unverifiable', $matched['municipal']['decision']);
        self::assertSame('010701123', $matched['parameters']['source']['service_code']);
        self::assertSame('synthetic-municipal-contract-v1', $matched['municipal']['source']['contract_version']);

        foreach ([
            $this->dps($invoice, '3304557'),
            $this->dps($invoice, '3303302', '2026-10-01'),
        ] as $differentDps) {
            $other = $preflight->evaluate($invoice, $differentDps, (string) $group['key']);
            self::assertTrue($other['can_attempt']);
            self::assertNull($other['parameters']);
            self::assertSame('unverifiable', $other['municipal']['decision']);
        }

        $profile = NfseCompanyProfile::query()->where('company_id', $invoice->company_id)->firstOrFail();
        $parameters = $profile->fiscal_parameters;
        $parameters['provider_establishment_confirmed_cnpj'] = '00111222000133';
        $profile->update(['fiscal_parameters' => $parameters]);
        $notConfirmed = $preflight->evaluate($invoice, $this->dps($invoice), (string) $group['key']);
        self::assertSame('municipal_context_incomplete', $notConfirmed['reason']);
        self::assertSame('', $notConfirmed['context']['incidence_municipality']);
        self::assertNull($notConfirmed['parameters']);
    }

    public function testUnlistedLc116CatalogItemDoesNotBlockActualDpsPreflight(): void
    {
        $this->loginAs();
        $invoice = $this->invoiceWithProfile('0509');
        $assessment = (new MunicipalEmissionPreflight())->evaluate(
            $invoice,
            $this->dps($invoice, '3303302', '2026-09-01', '0509', '010701'),
        );

        self::assertTrue($assessment['can_attempt']);
        self::assertSame('warning', $assessment['national']['status']);
        self::assertContains('unverified_service_code', $assessment['national']['issues']);
        self::assertSame('unverifiable', $assessment['municipal']['decision']);
    }

    public function testLocationExceptionRemainsUnverifiableEvenWithIssuerAttestation(): void
    {
        $this->loginAs();
        $invoice = $this->invoiceWithProfile('0702');
        $this->confirmEstablishment();
        $assessment = (new MunicipalEmissionPreflight())->evaluate(
            $invoice,
            $this->dps($invoice, '3303302', '2026-09-01', '0702', '070201'),
        );

        self::assertTrue($assessment['can_attempt']);
        self::assertSame('lc116_location_exception_requires_review', $assessment['context']['incidence_reason']);
        self::assertSame('municipal_context_incomplete', $assessment['reason']);
        self::assertNull($assessment['parameters']);
    }

    private function confirmEstablishment(): void
    {
        NfseCompanyProfile::query()->create([
            'company_id' => (int) company_id(),
            'status' => 'active',
            'certificate_ref' => NfseCompanyCertificatePath::newReference(),
            'certificate_cnpj' => '11222333000181',
            'certificate_valid_from' => time() - 3600,
            'certificate_valid_to' => time() + 3600,
            'fiscal_parameters' => [
                'municipio_ibge' => '3303302',
                'sandbox_mode' => '1',
                'provider_establishment_confirmed' => '1',
                'provider_establishment_confirmed_cnpj' => '11222333000181',
                'provider_establishment_confirmed_municipality' => '3303302',
                'provider_establishment_confirmed_by' => (string) auth()->id(),
                'provider_establishment_confirmed_at' => now()->toAtomString(),
            ],
        ]);
    }

    private function invoiceWithProfile(string $service): Document
    {
        $invoice = Document::factory()->invoice()->create([
            'company_id' => (int) company_id(),
            'issued_at' => '2026-09-01 12:00:00',
        ]);
        $invoice->items()->delete();
        $invoice->unsetRelation('items');

        $item = Item::factory()->create(['company_id' => (int) $invoice->company_id]);
        $invoice->items()->create([
            'company_id' => (int) $invoice->company_id,
            'type' => 'item',
            'item_id' => $item->id,
            'name' => 'Serviço de teste',
            'quantity' => 1,
            'price' => '100.00',
            'total' => '100.00',
        ]);
        ItemFiscalProfile::query()->create([
            'company_id' => (int) $invoice->company_id,
            'item_id' => $item->id,
            'item_lista_servico' => $service,
            'codigo_tributacao_nacional' => $service === '0702' ? '070201' : '010701',
            'codigo_tributacao_municipal' => '123',
        ]);
        $invoice->load('items');

        return $invoice;
    }

    private function dps(
        Document $invoice,
        string $municipality = '3303302',
        string $competence = '2026-09-01',
        string $service = '0107',
        string $national = '010701',
    ): DpsData {
        return new DpsData(
            cnpjPrestador: '11222333000181',
            municipioIbge: $municipality,
            itemListaServico: $service,
            codigoTributacaoNacional: $national,
            codigoTributacaoMunicipal: '123',
            valorServico: '100.00',
            aliquota: '2.00',
            discriminacao: 'Synthetic fiscal event',
            tipoAmbiente: 2,
            serie: '00001',
            numeroDps: (string) $invoice->id,
            dataCompetencia: $competence,
        );
    }
}

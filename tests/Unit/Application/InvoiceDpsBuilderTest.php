<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\InvoiceDpsBuilder;
use Modules\Nfse\Application\ModuleVersion;
use PHPUnit\Framework\TestCase;

final class InvoiceDpsBuilderTest extends TestCase
{
    public function testMapsResolvedNationalTakerAndFiscalPayload(): void
    {
        $manifestPath = tempnam(sys_get_temp_dir(), 'nfse-module-');

        if ($manifestPath === false) {
            self::fail('Unable to create temporary manifest.');
        }

        file_put_contents(
            $manifestPath,
            json_encode(['version' => '2.4.1'], JSON_THROW_ON_ERROR),
        );

        try {
            $dps = (new InvoiceDpsBuilder(
                moduleVersion: new ModuleVersion($manifestPath),
            ))->build($this->baseContext());
        } finally {
            @unlink($manifestPath);
        }

        self::assertSame('11222333000181', $dps->cnpjPrestador);
        self::assertSame('3303302', $dps->municipioIbge);
        self::assertSame('LibreCode/2.4.1', $dps->versaoAplicativo);
        self::assertSame('00900000000', $dps->prestadorTelefone);
        self::assertSame('issuer@example.org', $dps->prestadorEmail);
        self::assertSame('0107', $dps->itemListaServico);
        self::assertSame('010701', $dps->codigoTributacaoNacional);
        self::assertSame('12345678901', $dps->documentoTomador);
        self::assertSame('Cliente Teste', $dps->nomeTomador);
        self::assertSame('3303302', $dps->tomadorCodigoMunicipio);
        self::assertSame('10.00', $dps->federalPiscofinsValorPis);
    }

    public function testDevelopmentVersionRespectsOfficialVerAplicMaximumLength(): void
    {
        $manifestPath = tempnam(sys_get_temp_dir(), 'nfse-module-');
        if ($manifestPath === false) {
            self::fail('Unable to create temporary manifest.');
        }

        file_put_contents($manifestPath, json_encode(['version' => 'dev-main'], JSON_THROW_ON_ERROR));
        try {
            $dps = (new InvoiceDpsBuilder(moduleVersion: new ModuleVersion($manifestPath)))
                ->build($this->baseContext());
        } finally {
            @unlink($manifestPath);
        }

        self::assertSame('LibreCode/dev-main', $dps->versaoAplicativo);
        self::assertLessThanOrEqual(20, strlen($dps->versaoAplicativo));
    }

    public function testMapsEnabledIbsCbsPayloadIntoDps(): void
    {
        $context = $this->baseContext();
        $context['ibsCbs'] = [
            'enabled' => true,
            'ibsCbsFinalidade' => 0,
            'ibsCbsIndFinal' => 0,
            'ibsCbsCodigoIndicadorOperacao' => '010101',
            'ibsCbsIndDest' => 0,
            'ibsCbsCst' => '000',
            'ibsCbsClassificacaoTributaria' => '000001',
        ];

        $dps = (new InvoiceDpsBuilder())->build($context);

        self::assertSame(0, $dps->ibsCbsFinalidade);
        self::assertSame(0, $dps->ibsCbsIndFinal);
        self::assertSame('010101', $dps->ibsCbsCodigoIndicadorOperacao);
        self::assertSame(0, $dps->ibsCbsIndDest);
        self::assertSame('000', $dps->ibsCbsCst);
        self::assertSame('000001', $dps->ibsCbsClassificacaoTributaria);
    }

    public function testForeignTakerClearsNationalIdentityAndUsesExteriorAddress(): void
    {
        $context = $this->baseContext();
        $context['foreignTomador'] = [
            'enabled' => true,
            'nif' => 'ES-X123',
            'codigo_nao_nif' => null,
            'pais_codigo' => 'ES',
            'codigo_postal' => '28001',
            'cidade' => 'Madrid',
            'estado' => 'Madrid',
            'logradouro' => 'Calle Mayor',
            'numero' => '10',
            'complemento' => '',
            'bairro' => 'Centro',
        ];

        $dps = (new InvoiceDpsBuilder())->build($context);

        self::assertSame('', $dps->documentoTomador);
        self::assertSame('', $dps->tomadorCodigoMunicipio);
        self::assertSame('ES-X123', $dps->tomadorNif);
        self::assertSame('ES', $dps->tomadorPaisCodigo);
        self::assertSame('Madrid', $dps->tomadorCidadeExterior);
        self::assertSame('Calle Mayor', $dps->tomadorLogradouro);
    }

    /**
     * @return array<string,mixed>
     */
    private function baseContext(): array
    {
        return [
            'cnpjPrestador' => '11222333000181',
            'municipioIbge' => '3303302',
            'prestadorTelefone' => '00900000000',
            'prestadorEmail' => 'issuer@example.org',
            'itemListaServico' => '0107',
            'codigoTributacaoNacional' => '010701',
            'codigoTributacaoMunicipal' => '',
            'valorServico' => '100.00',
            'aliquota' => '2.00',
            'discriminacao' => 'Consultoria',
            'documentoTomador' => '12345678901',
            'nomeTomador' => 'Cliente Teste',
            'tomador' => [
                'codigo_municipio' => '3303302',
                'cep' => '24000000',
                'logradouro' => 'Rua Teste',
                'numero' => '100',
                'complemento' => '',
                'bairro' => 'Centro',
                'inscricao_municipal' => '',
                'telefone' => '',
                'email' => 'cliente@example.com',
            ],
            'foreignTomador' => ['enabled' => false],
            'opcaoSimplesNacional' => 2,
            'issqn' => [
                'tributacaoIssqn' => 1,
                'issqnPaisResultado' => '',
                'issqnTipoImunidade' => null,
                'issqnTipoSuspensao' => null,
                'issqnNumeroProcessoSuspensao' => '',
                'tipoRetencaoIss' => 1,
                'requiresSpecialRuntime' => false,
            ],
            'tipoAmbiente' => 2,
            'serie' => '00001',
            'numeroDps' => '42',
            'dataCompetencia' => '2026-10-05',
            'federal' => [
                'indicadorTributacao' => 2,
                'totalTributosPercentualFederal' => '10.00',
                'totalTributosPercentualEstadual' => '0.00',
                'totalTributosPercentualMunicipal' => '2.00',
                'federalPiscofinsSituacaoTributaria' => '1',
                'federalPiscofinsTipoRetencao' => '0',
                'federalPiscofinsBaseCalculo' => '100.00',
                'federalPiscofinsAliquotaPis' => '10.00',
                'federalPiscofinsValorPis' => '10.00',
                'federalPiscofinsAliquotaCofins' => '0.00',
                'federalPiscofinsValorCofins' => '0.00',
                'federalValorIrrf' => '',
                'federalValorCsll' => '',
                'federalValorCp' => '',
            ],
            'ibsCbs' => [
                'ibsCbsFinalidade' => null,
                'ibsCbsIndFinal' => null,
                'ibsCbsCodigoIndicadorOperacao' => '',
                'ibsCbsIndDest' => null,
                'ibsCbsCst' => '',
                'ibsCbsClassificacaoTributaria' => '',
            ],
        ];
    }
}

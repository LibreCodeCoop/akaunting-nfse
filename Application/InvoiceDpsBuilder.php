<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;

/**
 * Maps an already-resolved invoice emission context into the nfse-php DPS DTO.
 *
 * Request/settings/model resolution stays outside this class. This class owns
 * only the stable field mapping and runtime-capability requirements.
 */
final class InvoiceDpsBuilder
{
    public function __construct(
        private readonly RuntimeDpsFactory $runtime = new RuntimeDpsFactory(),
        private readonly ModuleVersion $moduleVersion = new ModuleVersion(),
    ) {
    }

    /**
     * @param array<string,mixed> $context
     */
    public function build(array $context): DpsData
    {
        $tomador = is_array($context['tomador'] ?? null) ? $context['tomador'] : [];
        $foreign = is_array($context['foreignTomador'] ?? null) ? $context['foreignTomador'] : [];
        $issqn = is_array($context['issqn'] ?? null) ? $context['issqn'] : [];
        $federal = is_array($context['federal'] ?? null) ? $context['federal'] : [];
        $ibsCbs = is_array($context['ibsCbs'] ?? null) ? $context['ibsCbs'] : [];
        $foreignEnabled = ($foreign['enabled'] ?? false) === true;

        $required = ['codigoTributacaoMunicipal'];

        if ($foreignEnabled) {
            $required = array_merge($required, [
                'tomadorNif',
                'tomadorCodigoNaoNif',
                'tomadorPaisCodigo',
                'tomadorCodigoPostalExterior',
                'tomadorCidadeExterior',
                'tomadorEstadoExterior',
            ]);
        }

        if (($issqn['requiresSpecialRuntime'] ?? false) === true) {
            $required = array_merge($required, [
                'tributacaoIssqn',
                'issqnPaisResultado',
                'issqnTipoImunidade',
                'issqnTipoSuspensao',
                'issqnNumeroProcessoSuspensao',
                'tipoRetencaoIss',
            ]);
        }

        if (($ibsCbs['enabled'] ?? false) === true) {
            $required = array_merge($required, [
                'codigoNbs',
                'ibsCbsFinalidade',
                'ibsCbsIndFinal',
                'ibsCbsCodigoIndicadorOperacao',
                'ibsCbsIndDest',
                'ibsCbsCst',
                'ibsCbsClassificacaoTributaria',
            ]);
        }

        $required = array_values(array_unique($required));

        return $this->runtime->make([
            'cnpjPrestador' => $context['cnpjPrestador'] ?? '',
            'municipioIbge' => $context['municipioIbge'] ?? '',
            'itemListaServico' => $context['itemListaServico'] ?? '',
            'codigoTributacaoNacional' => $context['codigoTributacaoNacional'] ?? '',
            'codigoTributacaoMunicipal' => $context['codigoTributacaoMunicipal'] ?? '',
            'codigoNbs' => $context['codigoNbs'] ?? '',
            'valorServico' => $context['valorServico'] ?? '',
            'aliquota' => $context['aliquota'] ?? '',
            'discriminacao' => $context['discriminacao'] ?? '',
            'prestadorTelefone' => $context['prestadorTelefone'] ?? '',
            'prestadorEmail' => $context['prestadorEmail'] ?? '',
            'versaoAplicativo' => 'LibreCode NFSe ' . $this->moduleVersion->get(),
            'documentoTomador' => $foreignEnabled ? '' : ($context['documentoTomador'] ?? ''),
            'nomeTomador' => $context['nomeTomador'] ?? '',
            'tomadorCodigoMunicipio' => $foreignEnabled ? '' : ($tomador['codigo_municipio'] ?? ''),
            'tomadorCep' => $foreignEnabled ? '' : ($tomador['cep'] ?? ''),
            'tomadorLogradouro' => $foreignEnabled ? ($foreign['logradouro'] ?? '') : ($tomador['logradouro'] ?? ''),
            'tomadorNumero' => $foreignEnabled ? ($foreign['numero'] ?? '') : ($tomador['numero'] ?? ''),
            'tomadorComplemento' => $foreignEnabled ? ($foreign['complemento'] ?? '') : ($tomador['complemento'] ?? ''),
            'tomadorBairro' => $foreignEnabled ? ($foreign['bairro'] ?? '') : ($tomador['bairro'] ?? ''),
            'tomadorInscricaoMunicipal' => $foreignEnabled ? '' : ($tomador['inscricao_municipal'] ?? ''),
            'tomadorTelefone' => $tomador['telefone'] ?? '',
            'tomadorEmail' => $tomador['email'] ?? '',
            'tomadorNif' => $foreignEnabled ? ($foreign['nif'] ?? '') : '',
            'tomadorCodigoNaoNif' => $foreignEnabled ? ($foreign['codigo_nao_nif'] ?? null) : null,
            'tomadorPaisCodigo' => $foreignEnabled ? ($foreign['pais_codigo'] ?? '') : '',
            'tomadorCodigoPostalExterior' => $foreignEnabled ? ($foreign['codigo_postal'] ?? '') : '',
            'tomadorCidadeExterior' => $foreignEnabled ? ($foreign['cidade'] ?? '') : '',
            'tomadorEstadoExterior' => $foreignEnabled ? ($foreign['estado'] ?? '') : '',
            'opcaoSimplesNacional' => $context['opcaoSimplesNacional'] ?? 2,
            'tributacaoIssqn' => $issqn['tributacaoIssqn'] ?? 1,
            'issqnPaisResultado' => $issqn['issqnPaisResultado'] ?? '',
            'issqnTipoImunidade' => $issqn['issqnTipoImunidade'] ?? null,
            'issqnTipoSuspensao' => $issqn['issqnTipoSuspensao'] ?? null,
            'issqnNumeroProcessoSuspensao' => $issqn['issqnNumeroProcessoSuspensao'] ?? '',
            'tipoRetencaoIss' => $issqn['tipoRetencaoIss'] ?? 1,
            'tipoAmbiente' => $context['tipoAmbiente'] ?? 2,
            'serie' => $context['serie'] ?? '00001',
            'numeroDps' => $context['numeroDps'] ?? '1',
            'dataCompetencia' => $context['dataCompetencia'] ?? null,
            'indicadorTributacao' => $federal['indicadorTributacao'] ?? 0,
            'totalTributosPercentualFederal' => $federal['totalTributosPercentualFederal'] ?? '',
            'totalTributosPercentualEstadual' => $federal['totalTributosPercentualEstadual'] ?? '',
            'totalTributosPercentualMunicipal' => $federal['totalTributosPercentualMunicipal'] ?? '',
            'federalPiscofinsSituacaoTributaria' => $federal['federalPiscofinsSituacaoTributaria'] ?? '',
            'federalPiscofinsTipoRetencao' => $federal['federalPiscofinsTipoRetencao'] ?? '',
            'federalPiscofinsBaseCalculo' => $federal['federalPiscofinsBaseCalculo'] ?? '',
            'federalPiscofinsAliquotaPis' => $federal['federalPiscofinsAliquotaPis'] ?? '',
            'federalPiscofinsValorPis' => $federal['federalPiscofinsValorPis'] ?? '',
            'federalPiscofinsAliquotaCofins' => $federal['federalPiscofinsAliquotaCofins'] ?? '',
            'federalPiscofinsValorCofins' => $federal['federalPiscofinsValorCofins'] ?? '',
            'federalValorIrrf' => $federal['federalValorIrrf'] ?? '',
            'federalValorCsll' => $federal['federalValorCsll'] ?? '',
            'federalValorCp' => $federal['federalValorCp'] ?? '',
            'ibsCbsFinalidade' => $ibsCbs['ibsCbsFinalidade'] ?? null,
            'ibsCbsIndFinal' => $ibsCbs['ibsCbsIndFinal'] ?? null,
            'ibsCbsCodigoIndicadorOperacao' => $ibsCbs['ibsCbsCodigoIndicadorOperacao'] ?? '',
            'ibsCbsIndDest' => $ibsCbs['ibsCbsIndDest'] ?? null,
            'ibsCbsCst' => $ibsCbs['ibsCbsCst'] ?? '',
            'ibsCbsClassificacaoTributaria' => $ibsCbs['ibsCbsClassificacaoTributaria'] ?? '',
        ], $required);
    }
}

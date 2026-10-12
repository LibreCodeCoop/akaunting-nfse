<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document as Invoice;
use Modules\Nfse\Support\InvoiceFiscalContextResolver;
use Modules\Nfse\Support\InvoiceMunicipalEvidenceContextResolver;
use Modules\Nfse\Support\ItemMunicipalValidationResolver;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;

/**
 * Municipal-only assessment of the exact final DPS, before its first POST.
 *
 * The national code is a necessary input, not proof of municipal acceptance.
 * The official municipal parameter cache is advisory. A missing, stale or
 * unavailable parameter lookup does not manufacture an issuer rejection.
 */
final class MunicipalEmissionPreflight
{
    public function __construct(
        private readonly FiscalProfileEmissionReadiness $profile = new FiscalProfileEmissionReadiness(),
        private readonly MunicipalAdmissibilityEvaluator $municipal = new MunicipalAdmissibilityEvaluator(),
        private readonly InvoiceMunicipalEvidenceContextResolver $contexts = new InvoiceMunicipalEvidenceContextResolver(),
        private readonly InvoiceFiscalContextResolver $groups = new InvoiceFiscalContextResolver(),
        private readonly ItemMunicipalValidationResolver $parameters = new ItemMunicipalValidationResolver(),
        private readonly RecoverInvoiceEmission $recovery = new RecoverInvoiceEmission(),
    ) {
    }

    /**
     * $fiscalFacts must come from a trusted, documented fiscal context, never
     * directly from a request's municipality field. No such assertion is made
     * when the installed module lacks authoritative incidence evidence.
     *
     * @param array<string,mixed> $fiscalFacts
     * @param array<string,mixed>|OfficialMunicipalIssuanceEvidence|null $officialEvidence
     * @return array{can_attempt:bool,reason:string,context:array<string,mixed>,national:array<string,mixed>,municipal:array<string,mixed>,parameters:?array<string,mixed>}
     */
    public function evaluate(
        Invoice $invoice,
        DpsData $dps,
        ?string $groupKey = null,
        array $fiscalFacts = [],
        array|OfficialMunicipalIssuanceEvidence|null $officialEvidence = null,
    ): array {
        $profile = [
            'item_lista_servico' => (string) $dps->itemListaServico,
            'codigo_tributacao_nacional' => (string) $dps->codigoTributacaoNacional,
            'codigo_tributacao_municipal' => (string) $dps->codigoTributacaoMunicipal,
        ];
        $national = $this->profile->evaluate($profile);
        $context = $this->contexts->resolve($invoice, $profile, [
            'municipio_ibge' => (string) $dps->municipioIbge,
            'sandbox_mode' => (int) $dps->tipoAmbiente === 2,
            'tributacao_issqn' => $dps->tributacaoIssqn ?? 1,
        ], array_merge($fiscalFacts, [
            'dps_id' => $this->recovery->dpsIdentifier($dps),
            'dps_sha256' => hash('sha256', json_encode(get_object_vars($dps), JSON_THROW_ON_ERROR)),
        ]));

        // The actual transmitted DPS is authoritative, even when its
        // competence differs from the invoice's original issued_at value.
        $context['competence'] = trim((string) ($dps->dataCompetencia ?? ''));
        $parameterResult = $this->matchedParameters($invoice, $dps, $groupKey, $context);
        $municipal = $this->municipal->evaluate(
            $context,
            $officialEvidence ?? $this->advisoryEvidence($parameterResult),
        );

        $nationalReady = ($national['isReady'] ?? false) === true
            && !in_array('missing_service_code', $national['issues'] ?? [], true)
            && preg_match('/^\d{6}$/D', (string) $dps->codigoTributacaoNacional) === 1;
        $municipalComplement = (string) ($dps->codigoTributacaoMunicipal ?? '');
        $complementReady = $municipalComplement === ''
            || preg_match('/^\d{3}$/D', $municipalComplement) === 1;
        $canAttempt = $nationalReady && $complementReady && ($municipal['can_attempt'] ?? false) === true;

        return [
            'can_attempt' => $canAttempt,
            'reason' => !$nationalReady ? 'invalid_national_fiscal_profile'
                : (!$complementReady ? 'invalid_municipal_complement'
                    : ($canAttempt ? (string) $municipal['reason'] : 'official_municipal_rejection')),
            'context' => $context,
            'national' => $national,
            'municipal' => $municipal,
            'parameters' => $parameterResult,
        ];
    }

    /**
     * Resolve only already-stored evidence for the same company, environment,
     * municipality, service code and DPS competence; never invoke a fiscal POST.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function matchedParameters(Invoice $invoice, DpsData $dps, ?string $groupKey, array $context): ?array
    {
        $municipality = trim((string) ($context['incidence_municipality'] ?? ''));
        $serviceCode = trim((string) ($context['service_code'] ?? ''));
        $competence = trim((string) ($context['competence'] ?? ''));

        if (preg_match('/^\d{7}$/D', $municipality) !== 1
            || preg_match('/^\d{9}$/D', $serviceCode) !== 1
            || $competence === '') {
            return null;
        }

        $groups = $this->groups->groups($invoice);
        $selected = null;
        foreach ($groups as $group) {
            if ($groupKey !== null && $groupKey !== '' && hash_equals((string) ($group['key'] ?? ''), $groupKey)) {
                $selected = $group;
                break;
            }
        }
        if ($selected === null && ($groupKey === null || $groupKey === '') && count($groups) === 1) {
            $selected = $groups[0];
        }
        if ($selected === null) {
            return null;
        }

        $itemCodes = [];
        foreach ($selected['items'] ?? [] as $item) {
            $id = is_numeric($item['item_id'] ?? null) ? (int) $item['item_id'] : 0;
            if ($id > 0) {
                $itemCodes[$id] = (string) $dps->codigoTributacaoNacional;
            }
        }
        if ($itemCodes === []) {
            return null;
        }

        $results = $this->parameters->resolveMany(
            itemNationalCodes: $itemCodes,
            companyId: (int) ($invoice->company_id ?? 0),
            municipioIbge: $municipality,
            sandboxMode: (int) $dps->tipoAmbiente === 2,
            competence: $competence,
        );

        foreach ($results as $result) {
            $source = is_array($result['source'] ?? null) ? $result['source'] : [];
            if (($source['company_id'] ?? null) === (int) $invoice->company_id
                && ($source['environment'] ?? '') === ($context['environment'] ?? '')
                && ($source['queried_municipality'] ?? '') === $municipality
                && ($source['service_code'] ?? '') === $serviceCode
                && ($source['competence'] ?? '') === $competence) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $parameters
     * @return array<string,mixed>
     */
    private function advisoryEvidence(?array $parameters): array
    {
        if ($parameters === null) {
            return ['kind' => 'adn_municipal_parameters', 'fallback_reason' => 'municipal_parameters_unavailable'];
        }

        $source = is_array($parameters['source'] ?? null) ? $parameters['source'] : [];
        $endpoints = is_array($source['endpoint_responses'] ?? null) ? $source['endpoint_responses'] : [];
        $aliquota = is_array($endpoints['aliquota'] ?? null) ? $endpoints['aliquota'] : [];

        return [
            'kind' => 'adn_municipal_parameters',
            'source_url' => $aliquota['source_url'] ?? '',
            'http_status' => $aliquota['http_status'] ?? null,
            'consulted_at' => $source['fetched_at'] ?? '',
            'contract_version' => $source['contract_version'] ?? '',
            'stale' => $source['stale'] ?? false,
            'fallback_reason' => $source['fallback_reason'] ?? '',
        ];
    }
}

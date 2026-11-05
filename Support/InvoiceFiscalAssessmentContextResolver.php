<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use App\Models\Document\Document as Invoice;
use Modules\Nfse\Contracts\FiscalContext;
use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;

/**
 * Maps persisted, company-scoped invoice facts and the actual outgoing DPS
 * to one immutable fiscal group. It never treats issued_at as an event date.
 */
final class InvoiceFiscalAssessmentContextResolver
{
    /** @var \Closure(Invoice):array */
    private readonly \Closure $groups;

    /** @var \Closure(int,int,string,?array):array */
    private readonly \Closure $taxFacts;

    /** @var \Closure(int,int,string):?array */
    private readonly \Closure $eventEvidence;

    public function __construct(
        ?\Closure $groups = null,
        ?\Closure $taxFacts = null,
        ?\Closure $eventEvidence = null,
    ) {
        $this->groups = $groups
            ?? static fn (Invoice $invoice): array => (new InvoiceFiscalContextResolver())->groups($invoice);
        $this->taxFacts = $taxFacts
            ?? static fn (int $companyId, int $invoiceId, string $date, ?array $ids): array =>
                (new InvoiceTaxFactsResolver())->resolve($companyId, $invoiceId, $date, $ids);
        $this->eventEvidence = $eventEvidence
            ?? static fn (int $companyId, int $invoiceId, string $key): ?array =>
                (new InvoiceFiscalEventEvidence())->latest($companyId, $invoiceId, $key);
    }

    /**
     * @param array<string,mixed> $municipalContext
     */
    public function resolve(
        Invoice $invoice,
        DpsData $dps,
        ?string $groupKey = null,
        array $municipalContext = [],
    ): FiscalContext {
        $companyId = (int) ($invoice->company_id ?? 0);
        $invoiceId = (int) ($invoice->id ?? 0);
        $activeCompany = function_exists('company_id') ? (int) company_id() : 0;

        if ($companyId <= 0 || $invoiceId <= 0 || (string) ($invoice->type ?? '') !== 'invoice'
            || ($activeCompany > 0 && $activeCompany !== $companyId)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Fiscal assessment invoice is outside the active company.');
        }

        $groups = ($this->groups)($invoice);
        $selected = null;

        foreach ($groups as $group) {
            if ($groupKey !== null && $groupKey !== ''
                && hash_equals((string) ($group['key'] ?? ''), $groupKey)) {
                $selected = $group;
                break;
            }
        }
        if ($selected === null && ($groupKey === null || $groupKey === '') && count($groups) === 1) {
            $selected = $groups[0];
        }
        if (!is_array($selected) || trim((string) ($selected['key'] ?? '')) === '') {
            throw new \DomainException('NFSE-FISCAL-GROUP-UNVERIFIABLE: select a current persisted fiscal group.');
        }

        $this->assertDpsGroupMatch($selected, $dps);

        $competence = $this->parseDate($dps->dataCompetencia ?? null);
        if ($competence === null) {
            throw new \DomainException('NFSE-TAX-COMPETENCE-UNVERIFIABLE: valid DPS competence is required.');
        }

        $itemIds = array_values(array_unique(array_filter(array_map(
            static fn (array $item): int => (int) ($item['document_item_id'] ?? 0),
            is_array($selected['items'] ?? null) ? $selected['items'] : [],
        ), static fn (int $id): bool => $id > 0)));
        if ($itemIds === []) {
            throw new \DomainException('NFSE-FISCAL-ITEMS-UNVERIFIABLE: group has no persisted invoice items.');
        }

        $facts = ($this->taxFacts)($companyId, $invoiceId, $competence->format('Y-m-d'), $itemIds);
        foreach ($facts as $fact) {
            if (!$fact instanceof FiscalTaxFact) {
                throw new \DomainException('NFSE-TAX-FACTS-UNVERIFIABLE: persisted tax resolver returned invalid facts.');
            }
        }

        $key = (string) $selected['key'];
        $evidence = ($this->eventEvidence)($companyId, $invoiceId, $key);
        $eventDate = null;
        if (is_array($evidence)) {
            if (($evidence['kind'] ?? null) !== 'operator_attestation'
                || trim((string) ($evidence['reference'] ?? '')) === '') {
                throw new \DomainException('NFSE-EVENT-EVIDENCE-UNVERIFIABLE: invalid event provenance.');
            }
            $eventDate = $this->parseDate($evidence['date'] ?? null);
            if ($eventDate === null) {
                throw new \DomainException('NFSE-EVENT-EVIDENCE-UNVERIFIABLE: invalid attested date.');
            }
        }

        $issuedAt = $invoice->issued_at ?? null;
        $commercialDate = $issuedAt instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($issuedAt)
            : $this->parseDate(is_string($issuedAt) ? substr($issuedAt, 0, 10) : null);
        $incidence = trim((string) ($municipalContext['incidence_municipality'] ?? ''));

        return new FiscalContext(
            companyId: $companyId,
            fiscalGroupKey: $key,
            taxableEventDate: $eventDate,
            competenceDate: $competence,
            providerRegime: null,
            operationCategory: trim((string) ($selected['rtc_supply_category'] ?? '')) ?: null,
            lc116Subitem: trim((string) $dps->itemListaServico) ?: null,
            nationalTaxCode: trim((string) $dps->codigoTributacaoNacional) ?: null,
            nbsCode: trim((string) $dps->codigoNbs) ?: null,
            incidenceMunicipalityIbge: preg_match('/^\d{7}$/D', $incidence) === 1 ? $incidence : null,
            takerType: null,
            takerMunicipalityIbge: null,
            takerCountryIso2: null,
            takerIsSimples: null,
            grossServiceCents: ExactTaxDecimal::cents($dps->valorServico),
            taxFacts: $facts,
            opcaoSimplesNacional: $dps->opcaoSimplesNacional,
            regimeApuracaoTributariaSn: $dps->regimeApuracaoSimplesNacional,
            regimeEspecialTributacao: $dps->regimeEspecialTributacao,
            commercialDate: $commercialDate,
            commercialDateSource: $commercialDate === null ? null : 'akaunting.documents.issued_at',
            competenceSource: 'dps.dataCompetencia',
            taxableEventSource: $eventDate === null ? null : 'operator_attestation',
            taxableEventEvidenceId: isset($evidence['id']) ? (int) $evidence['id'] : null,
        );
    }

    /** @param array<string,mixed> $group */
    private function assertDpsGroupMatch(array $group, DpsData $dps): void
    {
        $fields = [
            'item_lista_servico' => $dps->itemListaServico,
            'codigo_tributacao_nacional' => $dps->codigoTributacaoNacional,
            'codigo_tributacao_municipal' => $dps->codigoTributacaoMunicipal,
            'codigo_nbs' => $dps->codigoNbs,
        ];

        foreach ($fields as $key => $actual) {
            $expected = trim((string) ($group[$key] ?? ''));
            if ($expected !== '' && $expected !== trim((string) $actual)) {
                throw new \DomainException('NFSE-FISCAL-GROUP-CHANGED: outgoing DPS differs from persisted ' . $key . '.');
            }
        }

        try {
            $expectedAmount = ExactTaxDecimal::cents($group['amount'] ?? null);
            $actualAmount = ExactTaxDecimal::cents($dps->valorServico);
        } catch (\DomainException|\OverflowException $error) {
            throw new \DomainException('NFSE-FISCAL-AMOUNT-UNVERIFIABLE: invalid fiscal group amount.', previous: $error);
        }
        if ($expectedAmount === null || $actualAmount === null || $expectedAmount !== $actualAmount) {
            throw new \DomainException('NFSE-FISCAL-GROUP-CHANGED: outgoing DPS amount differs from persisted group.');
        }
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date !== false && $date->format('Y-m-d') === trim($value) ? $date : null;
    }
}

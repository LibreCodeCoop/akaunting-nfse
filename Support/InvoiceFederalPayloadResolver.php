<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use App\Models\Document\Document as Invoice;
use Modules\Nfse\Application\FederalTaxPayloadFacts;
use Modules\Nfse\Application\InvoiceDpsIdentity;
use Modules\Nfse\Application\SimplesOptionCode;

/**
 * Adapts company-scoped Akaunting tax observations to the federal DPS fields.
 *
 * Fiscal classification comes exclusively from confirmed, dated tax mappings.
 * Neither invoice totals nor configured percentages establish a tax base,
 * fiscal amount or lawful rate. Approximate taxes have a separate source.
 */
final class InvoiceFederalPayloadResolver
{
    /** @var \Closure(string,mixed):mixed */
    private readonly \Closure $settingResolver;

    /** @var \Closure(int,int,string,?array):array */
    private readonly \Closure $taxFactsResolver;

    /**
     * @param (\Closure(string,mixed):mixed)|null $settingResolver
     * @param (\Closure(int,int,string,?array):array)|null $taxFactsResolver
     */
    public function __construct(
        ?\Closure $settingResolver = null,
        ?\Closure $taxFactsResolver = null,
        private readonly ApproximateTaxSourceResolver $approximateTaxSources = new ApproximateTaxSourceResolver(),
        private readonly FederalTaxPayloadFacts $federalFacts = new FederalTaxPayloadFacts(),
    ) {
        $this->settingResolver = $settingResolver
            ?? static fn (string $key, mixed $default): mixed => function_exists('setting')
                ? \setting($key, $default)
                : $default;
        $this->taxFactsResolver = $taxFactsResolver
            ?? static fn (int $companyId, int $invoiceId, string $date, ?array $itemIds): array =>
                (new InvoiceTaxFactsResolver())->resolve($companyId, $invoiceId, $date, $itemIds);
    }

    /**
     * Purely observed fields with no statutory interpretation.
     *
     * @param list<int>|null $documentItemIds
     * @return array<string,mixed>
     */
    public function confirmedSnapshot(
        Invoice $invoice,
        ?array $documentItemIds,
        string $competenceDate,
        string $retentionType,
    ): array {
        $companyId = (int) ($invoice->company_id ?? 0);
        $invoiceId = (int) ($invoice->id ?? 0);
        if ($companyId <= 0 || $invoiceId <= 0) {
            throw new \DomainException('NFSE-TAX-CONTEXT-UNVERIFIABLE: persisted company and invoice are required.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $competenceDate);
        if ($date === false || $date->format('Y-m-d') !== $competenceDate) {
            throw new \DomainException('NFSE-TAX-COMPETENCE-UNVERIFIABLE: valid DPS competence is required.');
        }

        return $this->federalFacts->summarize(
            ($this->taxFactsResolver)($companyId, $invoiceId, $competenceDate, $documentItemIds),
            $retentionType,
        );
    }

    /**
     * Compatibility for federal readiness consumers. Values are verified facts,
     * never interpreted from tax labels or percentages.
     *
     * @param list<int>|null $documentItemIds
     * @return array<string,mixed>
     */
    public function snapshot(
        Invoice $invoice,
        float $invoiceAmount,
        ?array $documentItemIds = null,
    ): array {
        $date = (new InvoiceDpsIdentity())->competenceDate($invoice) ?? '';
        $snapshot = $this->confirmedSnapshot(
            $invoice,
            $documentItemIds,
            $date,
            $this->select($this->setting('nfse.federal_piscofins_tipo_retencao', '')),
        );

        return $snapshot + [
            'csll_value' => '',
            'federal_percent' => '',
        ];
    }

    /**
     * @param list<int>|null $documentItemIds
     * @return array<string,mixed>
     */
    public function resolve(
        Invoice $invoice,
        ?array $documentItemIds = null,
        ?float $amountOverride = null,
        mixed $municipalPercentFallback = null, // Ignored: ISS is not an approximate source.
        string $nationalServiceCode = '',
        string $competenceDate = '',
    ): array {
        $competenceDate = $competenceDate !== ''
            ? $competenceDate
            : ((new InvoiceDpsIdentity())->competenceDate($invoice) ?? '');
        $situacao = $this->select($this->setting('nfse.federal_piscofins_situacao_tributaria', ''));
        $retentionType = $this->select($this->setting('nfse.federal_piscofins_tipo_retencao', ''));
        $observed = $this->confirmedSnapshot($invoice, $documentItemIds, $competenceDate, $retentionType);
        $hasAssessment = $situacao !== '' && $situacao !== '0';
        $this->assertTransmissibleFacts($observed, $hasAssessment, $retentionType);

        $approximate = $this->approximateTaxSources->resolve(
            (int) $invoice->company_id,
            $nationalServiceCode,
            $this->simplesNacional(),
            $competenceDate,
        );

        return [
            'federalPiscofinsSituacaoTributaria' => $hasAssessment ? $situacao : '',
            'federalPiscofinsTipoRetencao' => $retentionType === '0' || $retentionType === '2'
                ? '' : $retentionType,
            'federalPiscofinsBaseCalculo' => $hasAssessment ? $observed['base_value'] : '',
            'federalPiscofinsAliquotaPis' => $hasAssessment ? $observed['pis_rate'] : '',
            'federalPiscofinsValorPis' => $hasAssessment ? $observed['pis_value'] : '',
            'federalPiscofinsAliquotaCofins' => $hasAssessment ? $observed['cofins_rate'] : '',
            'federalPiscofinsValorCofins' => $hasAssessment ? $observed['cofins_value'] : '',
            'federalValorIrrf' => $observed['irrf_value'],
            // NT007: vRetCSLL is selected actual PIS+COFINS+CSLL withholding,
            // not an isolated CSLL amount and not derived from a configured rate.
            'federalValorCsll' => $observed['social_total'],
            'federalValorCp' => '',
            'indicadorTributacao' => $approximate['status'] === 'unknown' ? 0 : 2,
            'approximateTaxEvidence' => [
                'status' => $approximate['status'],
                'reason' => $approximate['reason'],
                'source' => $approximate['source'],
            ],
            'totalTributosPercentualFederal' => $approximate['federal'],
            'totalTributosPercentualEstadual' => $approximate['state'],
            'totalTributosPercentualMunicipal' => $approximate['municipal'],
            'federalTaxEvidence' => [
                'status' => $observed['row_count'] > 0 ? 'confirmed_observations' : 'no_observations',
                'base_proven' => $observed['base_value'] !== '',
                'row_count' => $observed['row_count'],
                'legal_incidence' => 'unverifiable',
            ],
        ];
    }

    /** @param array<string,mixed> $observed */
    private function assertTransmissibleFacts(array $observed, bool $hasAssessment, string $retentionType): void
    {
        $hasOwnTaxes = $observed['pis_value'] !== '' || $observed['cofins_value'] !== '';

        if ($hasOwnTaxes && !$hasAssessment) {
            throw new \DomainException('NFSE-TAX-STATUS-UNVERIFIABLE: observed own taxes require a fiscal situation.');
        }
        if ($hasOwnTaxes && $observed['base_value'] === '') {
            throw new \DomainException('NFSE-TAX-BASE-UNVERIFIABLE: accounting invoice total cannot replace a proved PIS/COFINS base.');
        }
        if (($observed['pis_value'] !== '' && $observed['pis_rate'] === '')
            || ($observed['cofins_value'] !== '' && $observed['cofins_rate'] === '')) {
            throw new \DomainException('NFSE-TAX-RATE-UNVERIFIABLE: observed rate is missing or ambiguous.');
        }
        if (!in_array($retentionType, ['', '0', '2'], true) && $observed['social_total'] === '') {
            throw new \DomainException('NFSE-TAX-WITHHOLDING-UNVERIFIABLE: selected retention requires actual confirmed amounts.');
        }
    }

    /**
     * Only computes commercial group gross, never a federal tax base.
     *
     * @param list<int>|null $documentItemIds
     */
    public function serviceAmount(
        Invoice $invoice,
        ?array $documentItemIds = null,
        ?float $amountOverride = null,
    ): float {
        if ($amountOverride !== null) {
            return max(0.0, $amountOverride);
        }

        $amount = 0.0;
        $hasItemTotal = false;
        foreach ($this->invoiceItems($invoice) as $item) {
            $itemId = is_numeric($item['id'] ?? null) ? (int) $item['id'] : 0;
            if ($documentItemIds !== null && !in_array($itemId, $documentItemIds, true)) {
                continue;
            }
            if (!is_numeric($item['total'] ?? null)) {
                continue;
            }
            $amount += (float) $item['total'];
            $hasItemTotal = true;
        }

        return $hasItemTotal && $amount > 0
            ? $amount
            : max(0.0, (float) ($invoice->amount ?? 0.0));
    }

    /** @return list<array<string,mixed>> */
    private function invoiceItems(Invoice $invoice): array
    {
        $items = $invoice->items;
        if (is_object($items) && method_exists($items, 'toArray')) {
            $items = $items->toArray();
        }

        return is_array($items) ? array_values($items) : [];
    }

    private function simplesNacional(): ?int
    {
        return SimplesOptionCode::observed($this->setting('nfse.opcao_simples_nacional', null));
    }

    private function select(mixed $value): string
    {
        $normalized = trim((string) $value);

        return preg_match('/^\d+$/D', $normalized) === 1 ? $normalized : '';
    }

    private function setting(string $key, mixed $default): mixed
    {
        return ($this->settingResolver)($key, $default);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Contracts\FiscalContext;
use Modules\Nfse\Contracts\FiscalDecision;
use Modules\Nfse\Support\ExactTaxDecimal;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;

/**
 * Last-boundary NT007 counterproof: the final DPS cannot silently disagree
 * with current confirmed, company-scoped tax rows.
 */
final class FinalDpsFederalEvidenceValidator
{
    public function __construct(
        private readonly FederalTaxPayloadFacts $facts = new FederalTaxPayloadFacts(),
    ) {
    }

    /** @return list<FiscalDecision> */
    public function inspect(FiscalContext $context, DpsData $dps): array
    {
        if ($context->taxFacts === null) {
            return [$this->blocked('NFSE-TAX-FACTS-NOT-LOADED', 'taxFacts')];
        }

        $retentionType = trim((string) $dps->federalPiscofinsTipoRetencao);
        if (!in_array($retentionType, ['', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], true)) {
            return [$this->blocked('NFSE-NT007-RETENTION-TYPE-UNSUPPORTED', 'federalPiscofinsTipoRetencao')];
        }

        try {
            $observed = $this->facts->summarize($context->taxFacts, $retentionType);
        } catch (\DomainException|\OverflowException $error) {
            return [$this->blocked('NFSE-TAX-FACTS-UNVERIFIABLE', 'taxFacts')];
        }

        $ownTaxes = $observed['pis_value'] !== '' || $observed['cofins_value'] !== '';
        if ($ownTaxes && ($observed['base_value'] === ''
            || ($observed['pis_value'] !== '' && $observed['pis_rate'] === '')
            || ($observed['cofins_value'] !== '' && $observed['cofins_rate'] === ''))) {
            return [$this->blocked('NFSE-TAX-BASE-OR-RATE-UNVERIFIABLE', 'federalPiscofinsBaseCalculo')];
        }

        $fields = [
            'federalPiscofinsValorPis' => $observed['pis_value'],
            'federalPiscofinsValorCofins' => $observed['cofins_value'],
            'federalValorIrrf' => $observed['irrf_value'],
            // NT007 vRetCSLL is the selected PIS+COFINS+CSLL withheld total,
            // not a guessed standalone CSLL charge.
            'federalValorCsll' => $observed['social_total'],
            'federalValorCp' => '',
        ];
        if ($ownTaxes) {
            $fields['federalPiscofinsBaseCalculo'] = $observed['base_value'];
            // Percentages are observed rates, not amounts. Compare them
            // separately so no float or rounded-money conversion is used.
            foreach (['federalPiscofinsAliquotaPis' => 'pis_rate', 'federalPiscofinsAliquotaCofins' => 'cofins_rate'] as $field => $key) {
                if (trim((string) $dps->$field) !== $observed[$key]) {
                    return [$this->blocked('NFSE-TAX-RATE-OBSERVATION-MISMATCH', $field)];
                }
            }
        }

        $decisions = [];
        foreach ($fields as $field => $expected) {
            $actual = trim((string) $dps->$field);
            if ($this->moneyMatches($expected, $actual)) {
                continue;
            }

            $decisions[] = $this->blocked(
                'NFSE-NT007-OBSERVATION-MISMATCH',
                $field,
                $this->centsOrNull($expected),
                $this->centsOrNull($actual),
            );
        }

        if ($retentionType !== '' && $retentionType !== '0' && $retentionType !== '2'
            && $observed['social_total'] === '') {
            $decisions[] = $this->blocked('NFSE-NT007-RETENTION-UNVERIFIABLE', 'federalPiscofinsTipoRetencao');
        }

        return $decisions;
    }

    private function moneyMatches(string $expected, string $actual): bool
    {
        if ($expected === '' || $actual === '') {
            return $expected === $actual;
        }

        try {
            return ExactTaxDecimal::cents($expected) === ExactTaxDecimal::cents($actual);
        } catch (\DomainException|\OverflowException) {
            return false;
        }
    }

    private function centsOrNull(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        try {
            return ExactTaxDecimal::cents($value);
        } catch (\DomainException|\OverflowException) {
            return null;
        }
    }

    private function blocked(
        string $rule,
        string $field,
        ?int $expected = null,
        ?int $observed = null,
    ): FiscalDecision {
        return new FiscalDecision(
            ruleId: $rule,
            category: 'document',
            status: 'unverifiable',
            severity: 'block',
            sourceUrl: null,
            sourceProvision: null,
            sourceVersion: null,
            sourcePublishedAt: null,
            effectiveFrom: null,
            effectiveUntil: null,
            fieldPath: $field,
            correctionCode: strtolower(str_replace('NFSE-', '', $rule)),
            expectedCents: $expected,
            observedCents: $observed,
        );
    }
}

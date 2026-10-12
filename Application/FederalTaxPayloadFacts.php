<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxRole;
use Modules\Nfse\Support\ExactTaxDecimal;

/**
 * Bridge from confirmed persisted Akaunting facts to NT007 observations.
 * It does not establish the legal assessment or reconstruct a taxable base.
 */
final class FederalTaxPayloadFacts
{
    public function __construct(
        private readonly FederalTaxFactAggregator $aggregator = new FederalTaxFactAggregator(),
        private readonly FederalSocialRetentionCalculator $calculator = new FederalSocialRetentionCalculator(),
    ) {
    }

    /**
     * @param list<FiscalTaxFact> $facts
     * @return array{
     *     pis_value:string,pis_rate:string,cofins_value:string,cofins_rate:string,
     *     irrf_value:string,csll_value:string,social_total:string,base_value:string,row_count:int
     * }
     */
    public function summarize(array $facts, string $retentionType): array
    {
        $totals = $this->aggregator->aggregate($facts);
        $social = $this->calculator->calculateConfirmed($retentionType, [
            'pis' => $totals['retained']['pis'],
            'cofins' => $totals['retained']['cofins'],
            'csll' => $totals['retained']['csll'],
        ]);
        $base = $this->commonObservedBase($facts);

        return [
            'pis_value' => $this->observedAmount($facts, 'pis', FiscalTaxRole::PROVIDER_DUE, $totals['due']['pis']),
            'pis_rate' => $base !== '' ? $totals['rates']['pis'] : '',
            'cofins_value' => $this->observedAmount($facts, 'cofins', FiscalTaxRole::PROVIDER_DUE, $totals['due']['cofins']),
            'cofins_rate' => $base !== '' ? $totals['rates']['cofins'] : '',
            'irrf_value' => $this->observedAmount($facts, 'irrf', FiscalTaxRole::WITHHELD_BY_PAYER, $totals['retained']['irrf']),
            'csll_value' => $social['csll'],
            'social_total' => $social['total'],
            'base_value' => $base,
            'row_count' => $totals['row_count'],
        ];
    }

    /** @param list<FiscalTaxFact> $facts */
    private function observedAmount(array $facts, string $identity, FiscalTaxRole $role, int $cents): string
    {
        foreach ($facts as $fact) {
            if ($fact->fiscalTaxCode === $identity && $fact->fiscalRole === $role) {
                return ExactTaxDecimal::formatCents($cents);
            }
        }

        // Unknown is empty. A confirmed observation of zero is "0.00".
        return '';
    }

    /** @param list<FiscalTaxFact> $facts */
    private function commonObservedBase(array $facts): string
    {
        $base = null;
        foreach ($facts as $fact) {
            if ($fact->fiscalRole !== FiscalTaxRole::PROVIDER_DUE
                || !in_array($fact->fiscalTaxCode, ['pis', 'cofins'], true)) {
                continue;
            }

            if ($fact->baseCents === null) {
                return '';
            }
            if ($base !== null && $base !== $fact->baseCents) {
                return '';
            }

            $base = $fact->baseCents;
        }

        return $base === null ? '' : ExactTaxDecimal::formatCents($base);
    }
}

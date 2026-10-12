<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxIdentity;
use Modules\Nfse\Contracts\FiscalTaxRole;

/**
 * Aggregates confirmed monetary observations, not legal incidence or bases.
 * Identical persisted row IDs are counted once; different rows remain distinct.
 */
final class FederalTaxFactAggregator
{
    private const FEDERAL_CODES = ['pis', 'cofins', 'csll', 'irrf', 'cp'];

    /**
     * @param list<FiscalTaxFact> $facts
     * @return array{
     *    due:array<string,int>,
     *    retained:array<string,int>,
     *    rates:array<string,string>,
     *    row_count:int
     * }
     */
    public function aggregate(array $facts): array
    {
        $due = array_fill_keys(self::FEDERAL_CODES, 0);
        $retained = $due;
        $ratesByTax = [];
        $seen = [];

        foreach ($facts as $fact) {
            if (!$fact instanceof FiscalTaxFact) {
                throw new \InvalidArgumentException('Expected fiscal tax facts.');
            }

            $identity = $this->verifiedIdentity($fact);
            $this->assertAccountingRole($fact);

            if (!$this->registerOccurrence($fact, $seen)) {
                continue;
            }

            $code = $identity->value;
            // IBS, CBS and ISSQN cannot be promoted to ordinary federal
            // invoice-line taxes: they require independent legal assessment.
            if (!array_key_exists($code, $due)) {
                continue;
            }

            $this->recordAmount($fact, $code, $due, $retained, $ratesByTax);
        }

        return [
            'due' => $due,
            'retained' => $retained,
            'rates' => $this->selectedRates($ratesByTax),
            'row_count' => count($seen),
        ];
    }

    private function verifiedIdentity(FiscalTaxFact $fact): FiscalTaxIdentity
    {
        if ($fact->taxId === null || $fact->documentItemId === null
            || $fact->documentItemTaxRowId === null || $fact->fiscalRole === null
            || $fact->amountCents === null) {
            throw new \DomainException('Unclassified or incomplete persisted fiscal tax row.');
        }

        $identity = FiscalTaxIdentity::tryFrom((string) $fact->fiscalTaxCode);
        if ($identity === null) {
            throw new \DomainException('Unclassified or incomplete persisted fiscal tax row.');
        }

        if ($fact->amountCents < 0) {
            throw new \DomainException('Negative fiscal tax rows require dedicated legal assessment.');
        }

        return $identity;
    }

    private function assertAccountingRole(FiscalTaxFact $fact): void
    {
        if ($fact->fiscalRole === FiscalTaxRole::ESTIMATED_DISCLOSURE) {
            throw new \DomainException('Estimated transparency is not a normal accounting tax.');
        }

        if (!in_array($fact->accountingType, ['normal', 'withholding'], true)
            || ($fact->accountingType === 'withholding')
                !== ($fact->fiscalRole === FiscalTaxRole::WITHHELD_BY_PAYER)) {
            throw new \DomainException('Akaunting accounting type and verified fiscal role are incompatible.');
        }
    }

    /**
     * @param array<int,list<mixed>> $seen
     */
    private function registerOccurrence(FiscalTaxFact $fact, array &$seen): bool
    {
        $rowId = $fact->documentItemTaxRowId;
        $signature = [
            $fact->documentItemId, $fact->taxId, $fact->amountCents,
            $fact->fiscalTaxCode, $fact->fiscalRole?->value,
            $fact->accountingType, $fact->ratePercent, $fact->baseCents,
        ];

        if (array_key_exists($rowId, $seen)) {
            if ($seen[$rowId] !== $signature) {
                throw new \DomainException('Conflicting duplicate tax persistence row.');
            }

            return false;
        }

        $seen[$rowId] = $signature;

        return true;
    }

    /**
     * @param array<string,int> $due
     * @param array<string,int> $retained
     * @param array<string,string> $ratesByTax
     */
    private function recordAmount(
        FiscalTaxFact $fact,
        string $code,
        array &$due,
        array &$retained,
        array &$ratesByTax,
    ): void {
        if ($fact->fiscalRole === FiscalTaxRole::WITHHELD_BY_PAYER) {
            $retained[$code] = $this->addCents($retained[$code], $fact->amountCents);

            return;
        }

        $due[$code] = $this->addCents($due[$code], $fact->amountCents);

        if ($fact->ratePercent !== null && in_array($code, ['pis', 'cofins'], true)) {
            $this->rememberRate($fact, $code, $ratesByTax);
        }
    }

    /**
     * @param array<string,string> $ratesByTax
     */
    private function rememberRate(FiscalTaxFact $fact, string $code, array &$ratesByTax): void
    {
        $key = $code . ':' . $fact->taxId;
        if (isset($ratesByTax[$key]) && $ratesByTax[$key] !== $fact->ratePercent) {
            throw new \DomainException('Inconsistent observed tax rates for the same tax ID.');
        }

        $ratesByTax[$key] = $fact->ratePercent;
    }

    /**
     * @param array<string,string> $ratesByTax
     * @return array{pis:string,cofins:string}
     */
    private function selectedRates(array $ratesByTax): array
    {
        $rates = ['pis' => '', 'cofins' => ''];
        foreach (array_keys($rates) as $code) {
            $matching = array_filter(
                $ratesByTax,
                static fn (string $key): bool => str_starts_with($key, $code . ':'),
                ARRAY_FILTER_USE_KEY,
            );
            // Even equal rates from two different tax IDs are ambiguous
            // without a separately proven legal base for the combined value.
            if (count($matching) === 1) {
                $rates[$code] = (string) reset($matching);
            }
        }

        return $rates;
    }

    private function addCents(int $current, int $amount): int
    {
        if ($amount > PHP_INT_MAX - $current) {
            throw new \OverflowException('Fiscal tax aggregation exceeds integer-cent capacity.');
        }

        return $current + $amount;
    }
}

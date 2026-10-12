<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxIdentity;
use Modules\Nfse\Contracts\FiscalTaxRole;

/**
 * Aggregates observed, explicitly classified tax rows without guessing
 * incidence, tax-specific bases, monetary rules, or federal transparency.
 *
 * A repeated persistence row is counted once; distinct rows on different
 * invoice items are separate observations even when amounts coincide.
 */
final class FederalTaxFactAggregator
{
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
        $buckets = ['pis' => 0, 'cofins' => 0, 'csll' => 0, 'irrf' => 0, 'cp' => 0];
        $due = $buckets;
        $retained = $buckets;
        $rateByBucketAndTax = [];
        $seen = [];

        foreach ($facts as $fact) {
            if (!$fact instanceof FiscalTaxFact) {
                throw new \InvalidArgumentException('Expected fiscal tax facts.');
            }

            $identity = FiscalTaxIdentity::tryFrom((string) $fact->fiscalTaxCode);
            if ($fact->taxId === null || $fact->documentItemId === null
                || $fact->documentItemTaxRowId === null
                || $identity === null || $fact->fiscalRole === null
                || $fact->amountCents === null) {
                throw new \DomainException('Unclassified or incomplete persisted fiscal tax row.');
            }

            if ($fact->amountCents < 0) {
                throw new \DomainException('Negative fiscal tax rows require dedicated legal assessment.');
            }

            if ($fact->fiscalRole === FiscalTaxRole::ESTIMATED_DISCLOSURE) {
                throw new \DomainException('Estimated transparency is not a normal accounting tax.');
            }

            if (!in_array($fact->accountingType, ['normal', 'withholding'], true)
                || ($fact->accountingType === 'withholding')
                    !== ($fact->fiscalRole === FiscalTaxRole::WITHHELD_BY_PAYER)) {
                throw new \DomainException('Akaunting accounting type and verified fiscal role are incompatible.');
            }

            $rowId = $fact->documentItemTaxRowId;
            $signature = [
                $fact->documentItemId, $fact->taxId, $fact->amountCents,
                $identity->value, $fact->fiscalRole->value, $fact->accountingType,
            ];
            if (isset($seen[$rowId])) {
                if ($seen[$rowId] !== $signature) {
                    throw new \DomainException('Conflicting duplicate tax persistence row.');
                }

                continue;
            }
            $seen[$rowId] = $signature;

            $code = $identity->value;
            // ISSQN and the RTC IBS/CBS have their own operation policies.
            // Never synthesize them as federal invoice-line taxes.
            if (!array_key_exists($code, $buckets)) {
                continue;
            }

            $target = $fact->fiscalRole === FiscalTaxRole::WITHHELD_BY_PAYER
                ? 'retained'
                : 'due';
            $current = $target === 'retained' ? $retained[$code] : $due[$code];
            if ($fact->amountCents > PHP_INT_MAX - $current) {
                throw new \OverflowException('Fiscal tax aggregation exceeds integer-cent capacity.');
            }
            if ($target === 'retained') {
                $retained[$code] += $fact->amountCents;
            } else {
                $due[$code] += $fact->amountCents;
            }

            if ($target === 'due' && in_array($code, ['pis', 'cofins'], true)
                && $fact->ratePercent !== null) {
                $key = $code . ':' . $fact->taxId;
                if (isset($rateByBucketAndTax[$key]) && $rateByBucketAndTax[$key] !== $fact->ratePercent) {
                    throw new \DomainException('Inconsistent observed tax rates for the same tax ID.');
                }
                $rateByBucketAndTax[$key] = $fact->ratePercent;
            }
        }

        $rates = ['pis' => '', 'cofins' => ''];
        foreach ($rates as $code => $_) {
            $matching = array_filter(
                $rateByBucketAndTax,
                static fn (string $key): bool => str_starts_with($key, $code . ':'),
                ARRAY_FILTER_USE_KEY,
            );
            // Two distinct taxes cannot produce one legally meaningful rate
            // without an independently proven taxable base and calculation.
            if (count($matching) === 1) {
                $rates[$code] = (string) reset($matching);
            }
        }

        return [
            'due' => $due,
            'retained' => $retained,
            'rates' => $rates,
            'row_count' => count($seen),
        ];
    }
}

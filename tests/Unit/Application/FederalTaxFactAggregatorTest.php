<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\FederalTaxFactAggregator;
use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxRole;
use PHPUnit\Framework\TestCase;

final class FederalTaxFactAggregatorTest extends TestCase
{
    public function testSumsTwoPersistedEqualAmountsWithoutDoubleCountingSameRow(): void
    {
        $first = new FiscalTaxFact(101, 7, 5001, 'normal', 500, 'pis',
            FiscalTaxRole::PROVIDER_DUE, 'synthetic', null, '0.65');
        $second = new FiscalTaxFact(102, 7, 5002, 'normal', 500, 'pis',
            FiscalTaxRole::PROVIDER_DUE, 'synthetic', null, '0.65');

        $aggregation = (new FederalTaxFactAggregator())->aggregate([$first, $first, $second]);

        self::assertSame(1000, $aggregation['due']['pis']);
        self::assertSame(2, $aggregation['row_count']);
        self::assertSame('0.65', $aggregation['rates']['pis']);
    }

    public function testEqualNamesAndPercentagesCannotTurnWithholdingIntoOwnTax(): void
    {
        $due = new FiscalTaxFact(101, 7, 5001, 'normal', 500, 'pis',
            FiscalTaxRole::PROVIDER_DUE, 'synthetic', null, '0.65');
        $retained = new FiscalTaxFact(101, 8, 5002, 'withholding', 500, 'pis',
            FiscalTaxRole::WITHHELD_BY_PAYER, 'synthetic', null, '0.65');

        $aggregation = (new FederalTaxFactAggregator())->aggregate([$due, $retained]);

        self::assertSame(500, $aggregation['due']['pis']);
        self::assertSame(500, $aggregation['retained']['pis']);
        self::assertSame(0, $aggregation['retained']['csll']);
    }

    public function testIbsAndCbsAreNeverPromotedToFederalInvoiceTaxValues(): void
    {
        $ibs = new FiscalTaxFact(101, 4, 5001, 'normal', 123, 'ibs', FiscalTaxRole::PROVIDER_DUE);
        $cbs = new FiscalTaxFact(101, 5, 5002, 'normal', 456, 'cbs', FiscalTaxRole::PROVIDER_DUE);
        $aggregation = (new FederalTaxFactAggregator())->aggregate([$ibs, $cbs]);

        self::assertSame(0, $aggregation['due']['pis']);
        self::assertSame(0, $aggregation['due']['cofins']);
        self::assertSame('', $aggregation['rates']['pis']);
    }

    public function testIdentityMustBeConfirmedEvenForNamedTaxes(): void
    {
        $fact = new FiscalTaxFact(101, 7, 5001, 'normal', 500, null, null);
        $this->expectException(\DomainException::class);
        (new FederalTaxFactAggregator())->aggregate([$fact]);
    }

    public function testConflictingDuplicateRowIsNotSilentlyDeduplicated(): void
    {
        $first = new FiscalTaxFact(101, 7, 5001, 'normal', 500, 'pis', FiscalTaxRole::PROVIDER_DUE);
        $conflicting = new FiscalTaxFact(102, 7, 5001, 'normal', 500, 'pis', FiscalTaxRole::PROVIDER_DUE);
        $this->expectException(\DomainException::class);
        (new FederalTaxFactAggregator())->aggregate([$first, $conflicting]);
    }

    public function testInclusiveRequiresIndependentBasePolicy(): void
    {
        $fact = new FiscalTaxFact(101, 7, 5001, 'inclusive', 500, 'pis', FiscalTaxRole::PROVIDER_DUE);
        $this->expectException(\DomainException::class);
        (new FederalTaxFactAggregator())->aggregate([$fact]);
    }

    public function testMultipleDistinctTaxesCannotMasqueradeAsSingleRate(): void
    {
        $first = new FiscalTaxFact(101, 7, 5001, 'normal', 500, 'pis',
            FiscalTaxRole::PROVIDER_DUE, 'synthetic', null, '0.65');
        $second = new FiscalTaxFact(101, 8, 5002, 'normal', 500, 'pis',
            FiscalTaxRole::PROVIDER_DUE, 'synthetic', null, '0.65');
        $result = (new FederalTaxFactAggregator())->aggregate([$first, $second]);

        self::assertSame(1000, $result['due']['pis']);
        self::assertSame('', $result['rates']['pis']);
    }
}

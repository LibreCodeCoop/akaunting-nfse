<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Nfse\Contracts\CooperativeIbsCbsFacts;
use Modules\Nfse\Contracts\CooperativeSupplyRelation;
use Modules\Nfse\Contracts\FiscalContext;
use PHPUnit\Framework\TestCase;

final class CooperativeIbsCbsFactsTest extends TestCase
{
    public function testCooperativeLegalFormDoesNotProveSpecificTaxRegime(): void
    {
        $context = $this->context();

        self::assertTrue($context->providerIsCooperative);
        self::assertNull($context->cooperativeIbsCbsFacts);
    }

    public function testFilingAnElectionFormDoesNotEstablishAnEffectiveOption(): void
    {
        $facts = new CooperativeIbsCbsFacts(
            optionFormFiled: true,
            associatesListFiled: false,
            supplyRelation: CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE,
            associationVerifiedAtEvent: true,
            associateIsRegularIbsCbsTaxpayer: true,
        );

        self::assertNull($facts->optionConfirmed);
        self::assertFalse($facts->associatesListFiled);
        self::assertSame(CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE, $facts->supplyRelation);
    }

    public function testConfirmedOptionHasItsOwnEffectDateAndDoesNotBackdate(): void
    {
        $facts = new CooperativeIbsCbsFacts(
            optionConfirmed: true,
            optionEffectiveFrom: new DateTimeImmutable('2027-01-01'),
            optionFormFiled: true,
            associatesListFiled: true,
            supplyRelation: CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE,
            associationVerifiedAtEvent: true,
            associateIsRegularIbsCbsTaxpayer: true,
        );

        $context = $this->context($facts);
        self::assertSame('2026-12-31', $context->taxableEventDate?->format('Y-m-d'));
        self::assertTrue($facts->optionConfirmed);
        self::assertGreaterThan($context->taxableEventDate, $facts->optionEffectiveFrom);
    }

    public function testAssociateRegularTaxRegimeIsAnIndependentFact(): void
    {
        $unknown = new CooperativeIbsCbsFacts(
            supplyRelation: CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE,
            associationVerifiedAtEvent: true,
        );
        $confirmedNotRegular = new CooperativeIbsCbsFacts(
            supplyRelation: CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE,
            associationVerifiedAtEvent: true,
            associateIsRegularIbsCbsTaxpayer: false,
        );

        self::assertNull($unknown->associateIsRegularIbsCbsTaxpayer);
        self::assertFalse($confirmedNotRegular->associateIsRegularIbsCbsTaxpayer);
    }

    public function testCooperativePurposeAndPersonalUseExclusionsAreDistinctFacts(): void
    {
        $unknown = new CooperativeIbsCbsFacts(
            supplyRelation: CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE,
            associationVerifiedAtEvent: true,
            associateIsRegularIbsCbsTaxpayer: true,
        );
        $excluded = new CooperativeIbsCbsFacts(
            supplyRelation: CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE,
            associationVerifiedAtEvent: true,
            associateIsRegularIbsCbsTaxpayer: true,
            withinCooperativePurpose: false,
            forPersonalUseOrConsumption: true,
        );
        $permittedFacts = new CooperativeIbsCbsFacts(
            supplyRelation: CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE,
            associationVerifiedAtEvent: true,
            associateIsRegularIbsCbsTaxpayer: true,
            withinCooperativePurpose: true,
            forPersonalUseOrConsumption: false,
        );

        self::assertNull($unknown->withinCooperativePurpose);
        self::assertNull($unknown->forPersonalUseOrConsumption);
        self::assertFalse($excluded->withinCooperativePurpose);
        self::assertTrue($excluded->forPersonalUseOrConsumption);
        self::assertTrue($permittedFacts->withinCooperativePurpose);
        self::assertFalse($permittedFacts->forPersonalUseOrConsumption);
    }

    public function testCooperativeRecipientDoesNotRequireCooperativeSupplier(): void
    {
        $facts = new CooperativeIbsCbsFacts(
            supplyRelation: CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE,
            associationVerifiedAtEvent: true,
        );

        self::assertSame(CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE, $facts->supplyRelation);
    }

    public function testUnrelatedSupplyIsDifferentFromUnknownRelationship(): void
    {
        self::assertNull((new CooperativeIbsCbsFacts())->supplyRelation);
        self::assertSame(
            CooperativeSupplyRelation::UNRELATED,
            (new CooperativeIbsCbsFacts(supplyRelation: CooperativeSupplyRelation::UNRELATED))->supplyRelation,
        );
    }

    public function testContradictoryConfirmedOptionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CooperativeIbsCbsFacts(
            optionConfirmed: true,
            optionEffectiveFrom: new DateTimeImmutable('2027-01-01'),
            associatesListFiled: false,
        );
    }

    public function testConfirmationWithoutEffectiveDateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CooperativeIbsCbsFacts(optionConfirmed: true);
    }

    private function context(?CooperativeIbsCbsFacts $facts = null): FiscalContext
    {
        return new FiscalContext(
            companyId: 31,
            fiscalGroupKey: 'synthetic-cooperative-2027',
            taxableEventDate: new DateTimeImmutable('2026-12-31'),
            competenceDate: new DateTimeImmutable('2026-12-31'),
            providerRegime: 'lucro_real',
            operationCategory: 'ordinary_lc116',
            lc116Subitem: '01.01',
            nationalTaxCode: '010101',
            nbsCode: null,
            incidenceMunicipalityIbge: '3304557',
            takerType: 'legal_entity',
            takerMunicipalityIbge: '3304557',
            takerCountryIso2: 'BR',
            takerIsSimples: false,
            grossServiceCents: 1000000,
            providerIsCooperative: true,
            cooperativeIbsCbsFacts: $facts,
        );
    }
}

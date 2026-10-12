<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use DateTimeImmutable;
use Modules\Nfse\Application\CooperativeIbsCbsEligibilityAssessor;
use Modules\Nfse\Contracts\CooperativeIbsCbsFacts;
use Modules\Nfse\Contracts\CooperativeSupplyRelation;
use Modules\Nfse\Contracts\FiscalContext;
use PHPUnit\Framework\TestCase;

final class CooperativeIbsCbsEligibilityAssessorTest extends TestCase
{
    public function testConfirmedCooperativeToRegularAssociateServiceHasSourceBackedEligibilityOnly(): void
    {
        $decision = (new CooperativeIbsCbsEligibilityAssessor())->assess($this->context($this->facts()))->decisions[0];
        self::assertSame('pass', $decision->status);
        self::assertSame('COOP-ZERO-COOP-TO-REGULAR-ASSOCIATE', $decision->ruleId);
        self::assertNull($decision->expectedCents);
        self::assertNull($decision->observedCents);
    }

    public function testRegularAssociateToCooperativeIsIndependentlyRepresented(): void
    {
        $decision = (new CooperativeIbsCbsEligibilityAssessor())->assess($this->context(
            $this->facts(CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE),
            providerIsCooperative: false,
        ))->decisions[0];
        self::assertSame('pass', $decision->status);
        self::assertSame('COOP-ZERO-REGULAR-ASSOCIATE-TO-COOP', $decision->ruleId);
    }

    public function testNonregularAssociateToCooperativeExposesBothConflictingPrimaryNorms(): void
    {
        $decisions = (new CooperativeIbsCbsEligibilityAssessor())->assess($this->context(
            $this->facts(CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE, false),
            providerIsCooperative: false,
        ))->decisions;
        self::assertCount(2, $decisions);
        self::assertSame(['unverifiable', 'unverifiable'], array_map(
            static fn ($decision): string => $decision->status,
            $decisions,
        ));
        self::assertSame('COOP-ZERO-CONFLICT-LC271-I', $decisions[0]->ruleId);
        self::assertSame('COOP-ZERO-CONFLICT-DEC392-II', $decisions[1]->ruleId);
        self::assertSame('resolve_statute_regulation_conflict', $decisions[0]->correctionCode);
    }

    public function testNonregularAssociateReceivingServiceCannotClaimGoodsException(): void
    {
        $decision = (new CooperativeIbsCbsEligibilityAssessor())->assess($this->context(
            $this->facts(CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE, false),
        ))->decisions[0];
        self::assertSame('not_applicable', $decision->status);
    }

    public function testAbsentElectionIsNotAZeroRate(): void
    {
        $decision = (new CooperativeIbsCbsEligibilityAssessor())->assess($this->context(null))->decisions[0];
        self::assertSame('unverifiable', $decision->status);
        self::assertSame('verify_official_cooperative_election', $decision->correctionCode);
    }

    public function testConfirmedElectionCannotBeAppliedBeforeEffectiveness(): void
    {
        $assessor = new CooperativeIbsCbsEligibilityAssessor();
        self::assertSame('not_applicable', $assessor->assess($this->context(
            $this->facts(effectiveFrom: '2027-07-01'), date: '2027-06-30',
        ))->decisions[0]->status);
        self::assertSame('pass', $assessor->assess($this->context(
            $this->facts(effectiveFrom: '2027-07-01'), date: '2027-07-01',
        ))->decisions[0]->status);
    }

    public function testMissingMembershipOrExclusionsNeverConferEligibility(): void
    {
        $unknown = new CooperativeIbsCbsFacts(
            optionConfirmed: true,
            optionEffectiveFrom: new DateTimeImmutable('2027-01-01'),
            optionFormFiled: true,
            associatesListFiled: true,
            supplyRelation: CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE,
            associateIsRegularIbsCbsTaxpayer: true,
        );
        $decision = (new CooperativeIbsCbsEligibilityAssessor())->assess($this->context($unknown))->decisions[0];
        self::assertSame('unverifiable', $decision->status);
    }

    public function testExcludedPurposeOrPersonalConsumptionNeverQualifies(): void
    {
        foreach ([[false, false], [true, true]] as [$purpose, $personal]) {
            $facts = new CooperativeIbsCbsFacts(
                optionConfirmed: true,
                optionEffectiveFrom: new DateTimeImmutable('2027-01-01'),
                optionFormFiled: true,
                associatesListFiled: true,
                supplyRelation: CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE,
                associationVerifiedAtEvent: true,
                associateIsRegularIbsCbsTaxpayer: true,
                withinCooperativePurpose: $purpose,
                forPersonalUseOrConsumption: $personal,
            );
            self::assertSame('not_applicable', (new CooperativeIbsCbsEligibilityAssessor())
                ->assess($this->context($facts))->decisions[0]->status);
        }
    }

    public function testUnrelatedAndCooperativeChainAreDifferentOutcomes(): void
    {
        $assessor = new CooperativeIbsCbsEligibilityAssessor();
        self::assertSame('not_applicable', $assessor->assess($this->context(
            $this->facts(CooperativeSupplyRelation::UNRELATED),
        ))->decisions[0]->status);
        self::assertSame('unsupported', $assessor->assess($this->context(
            $this->facts(CooperativeSupplyRelation::PARTICIPATING_COOPERATIVES),
        ))->decisions[0]->status);
    }

    public function test2026And2028AreNotSilentlyTreatedLike2027(): void
    {
        $assessor = new CooperativeIbsCbsEligibilityAssessor();
        self::assertSame('not_applicable', $assessor->assess($this->context(
            $this->facts(), date: '2026-12-31',
        ))->decisions[0]->status);
        self::assertSame('unsupported', $assessor->assess($this->context(
            $this->facts(), date: '2028-01-01',
        ))->decisions[0]->status);
    }

    public function testReadOnlyAssessmentRetainsCompanyAndInvoiceAmount(): void
    {
        $context = $this->context($this->facts());
        $assessment = (new CooperativeIbsCbsEligibilityAssessor())->assess($context);
        self::assertSame($context, $assessment->context);
        self::assertSame(31, $assessment->context->companyId);
        self::assertSame(1000000, $assessment->context->grossServiceCents);
    }

    private function facts(
        CooperativeSupplyRelation $relation = CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE,
        ?bool $regular = true,
        string $effectiveFrom = '2027-01-01',
    ): CooperativeIbsCbsFacts {
        return new CooperativeIbsCbsFacts(
            optionConfirmed: true,
            optionEffectiveFrom: new DateTimeImmutable($effectiveFrom),
            optionFormFiled: true,
            associatesListFiled: true,
            supplyRelation: $relation,
            associationVerifiedAtEvent: true,
            associateIsRegularIbsCbsTaxpayer: $regular,
            withinCooperativePurpose: true,
            forPersonalUseOrConsumption: false,
        );
    }

    private function context(
        ?CooperativeIbsCbsFacts $facts,
        ?string $date = '2027-06-01',
        ?bool $providerIsCooperative = true,
    ): FiscalContext {
        return new FiscalContext(
            companyId: 31,
            fiscalGroupKey: 'synthetic-cooperative-service',
            taxableEventDate: $date === null ? null : new DateTimeImmutable($date),
            competenceDate: $date === null ? null : new DateTimeImmutable($date),
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
            taxFacts: [],
            providerIsCooperative: $providerIsCooperative,
            cooperativeIbsCbsFacts: $facts,
        );
    }
}

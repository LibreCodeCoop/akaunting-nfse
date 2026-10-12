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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CooperativeIbsCbsEligibilityAssessorTest extends TestCase
{
    /**
     * Independent expected rule, status, field and correction for every reachable
     * single-decision path; never derive expected values from the assessor.
     *
     * @return iterable<string,array{array<string,mixed>,string,string,string,?string}>
     */
    public static function decisionScenarios(): iterable
    {
        yield 'missing fiscal event' => [
            ['event' => null], 'COOP-ZERO-EVENT-UNKNOWN', 'unverifiable', 'taxableEventDate', 'verify_taxable_event_date',
        ];
        yield 'event unknown takes precedence over unproved option' => [
            ['event' => null, 'missingFacts' => true], 'COOP-ZERO-EVENT-UNKNOWN', 'unverifiable', 'taxableEventDate', 'verify_taxable_event_date',
        ];
        yield '2025 outside 2027 rule' => [
            ['event' => '2025-01-01'], 'COOP-ZERO-BEFORE-2027', 'not_applicable', 'taxableEventDate', null,
        ];
        yield 'last day 2026' => [
            ['event' => '2026-12-31'], 'COOP-ZERO-BEFORE-2027', 'not_applicable', 'taxableEventDate', null,
        ];
        yield 'competence cannot move fiscal event into 2027' => [
            ['event' => '2026-12-31', 'competence' => '2027-01-01'], 'COOP-ZERO-BEFORE-2027', 'not_applicable', 'taxableEventDate', null,
        ];
        yield 'first day 2028 unsupported' => [
            ['event' => '2028-01-01'], 'COOP-ZERO-AFTER-2027', 'unsupported', 'taxableEventDate', 'update_applicable_cooperative_policy',
        ];
        yield 'further years unsupported' => [
            ['event' => '2030-01-01'], 'COOP-ZERO-AFTER-2027', 'unsupported', 'taxableEventDate', 'update_applicable_cooperative_policy',
        ];
        yield 'unknown service category' => [
            ['operationCategory' => null], 'COOP-ZERO-OPERATION-UNSUPPORTED', 'unsupported', 'operationCategory', 'classify_supported_service',
        ];
        yield 'other operation category' => [
            ['operationCategory' => 'rental'], 'COOP-ZERO-OPERATION-UNSUPPORTED', 'unsupported', 'operationCategory', 'classify_supported_service',
        ];
        yield 'service code absent' => [
            ['lc116Subitem' => null], 'COOP-ZERO-OPERATION-UNSUPPORTED', 'unsupported', 'operationCategory', 'classify_supported_service',
        ];
        yield 'empty service code' => [
            ['lc116Subitem' => ''], 'COOP-ZERO-OPERATION-UNSUPPORTED', 'unsupported', 'operationCategory', 'classify_supported_service',
        ];
        yield 'whitespace service code' => [
            ['lc116Subitem' => '  '], 'COOP-ZERO-OPERATION-UNSUPPORTED', 'unsupported', 'operationCategory', 'classify_supported_service',
        ];
        yield 'service classification precedes missing election' => [
            ['operationCategory' => 'other', 'missingFacts' => true], 'COOP-ZERO-OPERATION-UNSUPPORTED', 'unsupported', 'operationCategory', 'classify_supported_service',
        ];
        yield 'no fiscal facts' => [
            ['missingFacts' => true], 'COOP-ZERO-ELECTION-UNVERIFIED', 'unverifiable', 'cooperativeIbsCbsFacts.optionConfirmed', 'verify_official_cooperative_election',
        ];
        yield 'option not confirmed' => [
            ['optionConfirmed' => null, 'optionEffectiveFrom' => null], 'COOP-ZERO-ELECTION-UNVERIFIED', 'unverifiable', 'cooperativeIbsCbsFacts.optionConfirmed', 'verify_official_cooperative_election',
        ];
        yield 'filing both forms does not prove option' => [
            ['optionConfirmed' => null, 'optionEffectiveFrom' => null, 'optionFormFiled' => true, 'associatesListFiled' => true], 'COOP-ZERO-ELECTION-UNVERIFIED', 'unverifiable', 'cooperativeIbsCbsFacts.optionConfirmed', 'verify_official_cooperative_election',
        ];
        yield 'explicitly no election' => [
            ['optionConfirmed' => false, 'optionEffectiveFrom' => null], 'COOP-ZERO-NOT-ELECTED', 'not_applicable', 'cooperativeIbsCbsFacts.optionConfirmed', null,
        ];
        yield 'election before effective date' => [
            ['event' => '2027-06-30', 'optionEffectiveFrom' => '2027-07-01'], 'COOP-ZERO-ELECTION-NOT-EFFECTIVE', 'not_applicable', 'cooperativeIbsCbsFacts.optionEffectiveFrom', null,
        ];
        yield 'effective date in following fiscal year' => [
            ['optionEffectiveFrom' => '2028-01-01'], 'COOP-ZERO-ELECTION-NOT-EFFECTIVE', 'not_applicable', 'cooperativeIbsCbsFacts.optionEffectiveFrom', null,
        ];
        yield 'same effective civil date despite different timezones' => [
            ['event' => '2027-01-01T00:01:00+14:00', 'optionEffectiveFrom' => '2027-01-01T00:00:00-12:00'], 'COOP-ZERO-COOP-TO-REGULAR-ASSOCIATE', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null,
        ];
        yield 'following civil date not effective despite earlier UTC instant' => [
            ['event' => '2027-06-30T23:59:59-12:00', 'optionEffectiveFrom' => '2027-07-01T00:00:00+14:00'], 'COOP-ZERO-ELECTION-NOT-EFFECTIVE', 'not_applicable', 'cooperativeIbsCbsFacts.optionEffectiveFrom', null,
        ];
        yield 'off-cycle startup option lacking evidence cannot pass' => [
            ['event' => '2027-07-01', 'optionEffectiveFrom' => '2027-07-01'], 'COOP-ZERO-STARTUP-EVIDENCE-MISSING', 'unverifiable', 'cooperativeIbsCbsFacts.optionAtStartOfOperations', 'verify_start_of_activity_election',
        ];
        yield 'off-cycle option explicitly not at startup cannot pass' => [
            ['event' => '2027-07-01', 'optionEffectiveFrom' => '2027-07-01', 'optionAtStartOfOperations' => false], 'COOP-ZERO-STARTUP-EVIDENCE-MISSING', 'unverifiable', 'cooperativeIbsCbsFacts.optionAtStartOfOperations', 'verify_start_of_activity_election',
        ];
        yield 'off-cycle startup without registration date cannot pass' => [
            ['event' => '2027-07-01', 'optionEffectiveFrom' => '2027-07-01', 'optionAtStartOfOperations' => true], 'COOP-ZERO-STARTUP-EVIDENCE-MISSING', 'unverifiable', 'cooperativeIbsCbsFacts.optionAtStartOfOperations', 'verify_start_of_activity_election',
        ];
        yield 'off-cycle registration date mismatch cannot pass' => [
            ['event' => '2027-07-01', 'optionEffectiveFrom' => '2027-07-01', 'optionAtStartOfOperations' => true, 'cooperativeRegistrationDate' => '2027-06-01'], 'COOP-ZERO-STARTUP-DATE-MISMATCH', 'unverifiable', 'cooperativeIbsCbsFacts.cooperativeRegistrationDate', 'verify_start_of_activity_registration',
        ];
        yield 'verified startup option effective on registration date' => [
            ['event' => '2027-07-01', 'optionEffectiveFrom' => '2027-07-01', 'optionAtStartOfOperations' => true, 'cooperativeRegistrationDate' => '2027-07-01'], 'COOP-ZERO-COOP-TO-REGULAR-ASSOCIATE', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null,
        ];
        yield 'startup registration civil date independent of timezone' => [
            ['event' => '2027-07-01T00:01:00+14:00', 'optionEffectiveFrom' => '2027-07-01T00:00:00-12:00', 'optionAtStartOfOperations' => true, 'cooperativeRegistrationDate' => '2027-07-01T23:59:00+14:00'], 'COOP-ZERO-COOP-TO-REGULAR-ASSOCIATE', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null,
        ];
        yield 'provider business registration in 2026 does not imply startup option' => [
            ['optionEffectiveFrom' => '2026-09-01'], 'COOP-ZERO-STARTUP-EVIDENCE-MISSING', 'unverifiable', 'cooperativeIbsCbsFacts.optionAtStartOfOperations', 'verify_start_of_activity_election',
        ];
        yield 'known non-cooperative taker is not a cooperative supply' => [
            ['supplyRelation' => CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE, 'takerIsCooperative' => false], 'COOP-ZERO-TAKER-NOT-COOPERATIVE', 'not_applicable', 'takerIsCooperative', null,
        ];
        yield 'unknown cooperative taker cannot pass' => [
            ['supplyRelation' => CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE, 'takerIsCooperative' => null], 'COOP-ZERO-TAKER-UNVERIFIED', 'unverifiable', 'takerIsCooperative', 'verify_cooperative_taker',
        ];
        yield 'missing taker cooperative evidence blocks before regularity lookup' => [
            ['supplyRelation' => CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE, 'takerIsCooperative' => null, 'associateIsRegularIbsCbsTaxpayer' => null], 'COOP-ZERO-TAKER-UNVERIFIED', 'unverifiable', 'takerIsCooperative', 'verify_cooperative_taker',
        ];
        yield 'explicit unrelated supply' => [
            ['supplyRelation' => CooperativeSupplyRelation::UNRELATED], 'COOP-ZERO-NO-ASSOCIATION', 'not_applicable', 'cooperativeIbsCbsFacts.associationVerifiedAtEvent', null,
        ];
        yield 'association explicitly absent' => [
            ['associationVerifiedAtEvent' => false], 'COOP-ZERO-NO-ASSOCIATION', 'not_applicable', 'cooperativeIbsCbsFacts.associationVerifiedAtEvent', null,
        ];
        yield 'unrelated overrides purpose exclusion' => [
            ['supplyRelation' => CooperativeSupplyRelation::UNRELATED, 'withinCooperativePurpose' => false], 'COOP-ZERO-NO-ASSOCIATION', 'not_applicable', 'cooperativeIbsCbsFacts.associationVerifiedAtEvent', null,
        ];
        yield 'excluded cooperative purpose' => [
            ['withinCooperativePurpose' => false], 'COOP-ZERO-OUTSIDE-PURPOSE', 'not_applicable', 'cooperativeIbsCbsFacts.withinCooperativePurpose', null,
        ];
        yield 'known purpose exclusion precedes missing membership proof' => [
            ['withinCooperativePurpose' => false, 'associationVerifiedAtEvent' => null], 'COOP-ZERO-OUTSIDE-PURPOSE', 'not_applicable', 'cooperativeIbsCbsFacts.withinCooperativePurpose', null,
        ];
        yield 'personal consumption excluded' => [
            ['forPersonalUseOrConsumption' => true], 'COOP-ZERO-PERSONAL-USE', 'not_applicable', 'cooperativeIbsCbsFacts.forPersonalUseOrConsumption', null,
        ];
        yield 'purpose exclusion precedes personal use' => [
            ['withinCooperativePurpose' => false, 'forPersonalUseOrConsumption' => true], 'COOP-ZERO-OUTSIDE-PURPOSE', 'not_applicable', 'cooperativeIbsCbsFacts.withinCooperativePurpose', null,
        ];
        yield 'membership unknown' => [
            ['associationVerifiedAtEvent' => null], 'COOP-ZERO-FACTS-INCOMPLETE', 'unverifiable', 'cooperativeIbsCbsFacts', 'verify_operation_scope_and_membership',
        ];
        yield 'purpose unknown' => [
            ['withinCooperativePurpose' => null], 'COOP-ZERO-FACTS-INCOMPLETE', 'unverifiable', 'cooperativeIbsCbsFacts', 'verify_operation_scope_and_membership',
        ];
        yield 'personal consumption unknown' => [
            ['forPersonalUseOrConsumption' => null], 'COOP-ZERO-FACTS-INCOMPLETE', 'unverifiable', 'cooperativeIbsCbsFacts', 'verify_operation_scope_and_membership',
        ];
        yield 'relation unknown' => [
            ['supplyRelation' => null], 'COOP-ZERO-FACTS-INCOMPLETE', 'unverifiable', 'cooperativeIbsCbsFacts', 'verify_operation_scope_and_membership',
        ];
        yield 'election not confirmed before known membership exclusion' => [
            ['optionConfirmed' => null, 'optionEffectiveFrom' => null, 'associationVerifiedAtEvent' => false], 'COOP-ZERO-ELECTION-UNVERIFIED', 'unverifiable', 'cooperativeIbsCbsFacts.optionConfirmed', 'verify_official_cooperative_election',
        ];
        yield 'not yet effective before purpose exclusion' => [
            ['optionEffectiveFrom' => '2027-07-01', 'withinCooperativePurpose' => false], 'COOP-ZERO-ELECTION-NOT-EFFECTIVE', 'not_applicable', 'cooperativeIbsCbsFacts.optionEffectiveFrom', null,
        ];
        yield 'supplier known not cooperative in coop-to-member direction' => [
            ['providerIsCooperative' => false], 'COOP-ZERO-PROVIDER-NOT-COOPERATIVE', 'not_applicable', 'providerIsCooperative', null,
        ];
        yield 'supplier cooperative status unknown in coop-to-member direction' => [
            ['providerIsCooperative' => null], 'COOP-ZERO-PROVIDER-UNVERIFIED', 'unverifiable', 'providerIsCooperative', 'verify_cooperative_provider',
        ];
        yield 'cooperative to regular member' => [
            [], 'COOP-ZERO-COOP-TO-REGULAR-ASSOCIATE', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null,
        ];
        yield 'cooperative to nonregular member service not goods exception' => [
            ['associateIsRegularIbsCbsTaxpayer' => false], 'COOP-ZERO-COOP-TO-NONREGULAR-SERVICE', 'not_applicable', 'cooperativeIbsCbsFacts.associateIsRegularIbsCbsTaxpayer', null,
        ];
        yield 'cooperative to member with unknown collection regime' => [
            ['associateIsRegularIbsCbsTaxpayer' => null], 'COOP-ZERO-ASSOCIATE-REGIME-UNKNOWN', 'unverifiable', 'cooperativeIbsCbsFacts.associateIsRegularIbsCbsTaxpayer', 'verify_associate_regular_status',
        ];
        yield 'regular member to cooperative with natural supplier' => [
            ['supplyRelation' => CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE, 'takerIsCooperative' => true, 'providerIsCooperative' => false], 'COOP-ZERO-REGULAR-ASSOCIATE-TO-COOP', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null,
        ];
        yield 'regular member to cooperative does not infer supplier status' => [
            ['supplyRelation' => CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE, 'takerIsCooperative' => true, 'providerIsCooperative' => null], 'COOP-ZERO-REGULAR-ASSOCIATE-TO-COOP', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null,
        ];
        yield 'member to cooperative regularity unknown' => [
            ['supplyRelation' => CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE, 'takerIsCooperative' => true, 'associateIsRegularIbsCbsTaxpayer' => null], 'COOP-ZERO-ASSOCIATE-REGIME-UNKNOWN', 'unverifiable', 'cooperativeIbsCbsFacts.associateIsRegularIbsCbsTaxpayer', 'verify_associate_regular_status',
        ];
        yield 'cooperative chain not classified automatically' => [
            ['supplyRelation' => CooperativeSupplyRelation::PARTICIPATING_COOPERATIVES], 'COOP-ZERO-COOPERATIVE-CHAIN-UNSUPPORTED', 'unsupported', 'cooperativeIbsCbsFacts.supplyRelation', 'verify_cooperative_chain_conditions',
        ];
        yield 'start 2027 included' => [
            ['event' => '2027-01-01'], 'COOP-ZERO-COOP-TO-REGULAR-ASSOCIATE', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null,
        ];
        yield 'end 2027 included' => [
            ['event' => '2027-12-31'], 'COOP-ZERO-COOP-TO-REGULAR-ASSOCIATE', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null,
        ];
        yield 'effective on event day' => [
            ['event' => '2027-07-01', 'optionEffectiveFrom' => '2027-07-01'], 'COOP-ZERO-STARTUP-EVIDENCE-MISSING', 'unverifiable', 'cooperativeIbsCbsFacts.optionAtStartOfOperations', 'verify_start_of_activity_election',
        ];
        yield 'fiscal event controls over older competence' => [
            ['event' => '2027-01-01', 'competence' => '2026-12-31'], 'COOP-ZERO-COOP-TO-REGULAR-ASSOCIATE', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null,
        ];
        yield 'service zero amount does not change classification' => [
            ['grossServiceCents' => 0], 'COOP-ZERO-COOP-TO-REGULAR-ASSOCIATE', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null,
        ];
        yield 'different tenant does not change policy' => [
            ['companyId' => 86], 'COOP-ZERO-COOP-TO-REGULAR-ASSOCIATE', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null,
        ];
    }

    /** @param array<string,mixed> $overrides */
    #[DataProvider('decisionScenarios')]
    public function testEachClassificationHasAnIndependentExpectedResult(
        array $overrides,
        string $expectedRule,
        string $expectedStatus,
        string $expectedField,
        ?string $expectedCorrection,
    ): void {
        $context = self::context($overrides);
        $assessment = (new CooperativeIbsCbsEligibilityAssessor())->assess($context);

        self::assertSame($context, $assessment->context);
        self::assertCount(1, $assessment->decisions);
        $decision = $assessment->decisions[0];
        self::assertSame($expectedRule, $decision->ruleId);
        self::assertSame($expectedStatus, $decision->status);
        self::assertSame($expectedField, $decision->fieldPath);
        self::assertSame($expectedCorrection, $decision->correctionCode);
        self::assertSame('normative', $decision->category);
        self::assertSame(in_array($expectedStatus, ['pass', 'not_applicable'], true) ? 'info' : 'warn', $decision->severity);
        self::assertSame('2027-01-01', $decision->effectiveFrom?->format('Y-m-d'));
        self::assertSame('2027-12-31', $decision->effectiveUntil?->format('Y-m-d'));
        self::assertNull($decision->expectedCents);
        self::assertNull($decision->observedCents);
        self::assertStringContainsString('camara.leg.br', (string) $decision->sourceUrl);
        self::assertSame($context->companyId, $assessment->context->companyId);
        self::assertSame($context->grossServiceCents, $assessment->context->grossServiceCents);
    }

    /**
     * Explicit dual-source result is not a 'pass' or a zero-rate assumption.
     *
     * @return iterable<string,array{array<string,mixed>}>
     */
    public static function statutoryConflictScenarios(): iterable
    {
        yield 'natural nonregular member supplies cooperative' => [[
            'supplyRelation' => CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE,
            'takerIsCooperative' => true,
            'associateIsRegularIbsCbsTaxpayer' => false,
            'providerIsCooperative' => false,
        ]];
        yield 'nonregular status remains conflict even if provider cooperative' => [[
            'supplyRelation' => CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE,
            'takerIsCooperative' => true,
            'associateIsRegularIbsCbsTaxpayer' => false,
            'providerIsCooperative' => true,
        ]];
        yield 'conflict remains on first day of 2027' => [[
            'event' => '2027-01-01',
            'supplyRelation' => CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE,
            'takerIsCooperative' => true,
            'associateIsRegularIbsCbsTaxpayer' => false,
        ]];
    }

    /** @param array<string,mixed> $overrides */
    #[DataProvider('statutoryConflictScenarios')]
    public function testConflictProducesTwoDistinctNormativeSources(array $overrides): void
    {
        $assessment = (new CooperativeIbsCbsEligibilityAssessor())->assess(self::context($overrides));
        self::assertCount(2, $assessment->decisions);
        self::assertSame('COOP-ZERO-CONFLICT-LC271-I', $assessment->decisions[0]->ruleId);
        self::assertSame('COOP-ZERO-CONFLICT-DEC392-II', $assessment->decisions[1]->ruleId);
        self::assertSame('art. 271, I', $assessment->decisions[0]->sourceProvision);
        self::assertSame('art. 392, II', $assessment->decisions[1]->sourceProvision);
        self::assertStringContainsString('leicomplementar-214', (string) $assessment->decisions[0]->sourceUrl);
        self::assertStringContainsString('decreto-12955', (string) $assessment->decisions[1]->sourceUrl);

        foreach ($assessment->decisions as $decision) {
            self::assertSame('unverifiable', $decision->status);
            self::assertSame('warn', $decision->severity);
            self::assertSame('resolve_statute_regulation_conflict', $decision->correctionCode);
            self::assertNull($decision->expectedCents);
            self::assertNull($decision->observedCents);
        }
    }

    /** @param array<string,mixed> $overrides */
    private static function context(array $overrides = []): FiscalContext
    {
        // All records use fictional fiscal entities. Each case changes exactly
        // the relevant facts; explicit null remains different from false.
        $data = array_replace([
            'companyId' => 31,
            'event' => '2027-06-01',
            'competence' => '2027-06-01',
            'operationCategory' => 'ordinary_lc116',
            'lc116Subitem' => '01.01',
            'grossServiceCents' => 1000000,
            'providerIsCooperative' => true,
            'takerIsCooperative' => false,
            'missingFacts' => false,
            'optionConfirmed' => true,
            'optionEffectiveFrom' => '2027-01-01',
            'optionFormFiled' => true,
            'associatesListFiled' => true,
            'optionAtStartOfOperations' => null,
            'cooperativeRegistrationDate' => null,
            'supplyRelation' => CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE,
            'associationVerifiedAtEvent' => true,
            'associateIsRegularIbsCbsTaxpayer' => true,
            'withinCooperativePurpose' => true,
            'forPersonalUseOrConsumption' => false,
        ], $overrides);

        $facts = $data['missingFacts'] ? null : new CooperativeIbsCbsFacts(
            optionConfirmed: $data['optionConfirmed'],
            optionEffectiveFrom: self::date($data['optionEffectiveFrom']),
            optionFormFiled: $data['optionFormFiled'],
            associatesListFiled: $data['associatesListFiled'],
            optionAtStartOfOperations: $data['optionAtStartOfOperations'],
            cooperativeRegistrationDate: self::date($data['cooperativeRegistrationDate']),
            supplyRelation: $data['supplyRelation'],
            associationVerifiedAtEvent: $data['associationVerifiedAtEvent'],
            associateIsRegularIbsCbsTaxpayer: $data['associateIsRegularIbsCbsTaxpayer'],
            withinCooperativePurpose: $data['withinCooperativePurpose'],
            forPersonalUseOrConsumption: $data['forPersonalUseOrConsumption'],
        );

        return new FiscalContext(
            companyId: $data['companyId'],
            fiscalGroupKey: 'synthetic-cooperative-service',
            taxableEventDate: self::date($data['event']),
            competenceDate: self::date($data['competence']),
            providerRegime: 'lucro_real',
            operationCategory: $data['operationCategory'],
            lc116Subitem: $data['lc116Subitem'],
            nationalTaxCode: '010101',
            nbsCode: null,
            incidenceMunicipalityIbge: '3304557',
            takerType: 'legal_entity',
            takerMunicipalityIbge: '3304557',
            takerCountryIso2: 'BR',
            takerIsSimples: false,
            grossServiceCents: $data['grossServiceCents'],
            taxFacts: [],
            providerIsCooperative: $data['providerIsCooperative'],
            cooperativeIbsCbsFacts: $facts,
            takerIsCooperative: $data['takerIsCooperative'],
        );
    }

    private static function date(?string $date): ?DateTimeImmutable
    {
        return $date === null ? null : new DateTimeImmutable($date);
    }
}

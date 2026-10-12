<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Fiscal;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Nfse\Application\CooperativeIbsCbsEligibilityAssessor;
use Modules\Nfse\Contracts\CooperativeIbsCbsFacts;
use Modules\Nfse\Contracts\CooperativeSupplyRelation;
use Modules\Nfse\Contracts\FiscalContext;
use PHPUnit\Framework\TestCase;

final class IndependentCooperativeCounterexamplesTest extends TestCase
{
    public function testSixHistoricalCounterexamplesDoNotTurnMissingProofIntoTaxExemption(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/fiscal-normative/nfse_427_cooperativas_casos_a_revisar.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('synthetic_review_only_not_an_approved_zero_rate', $data['status']);
        self::assertCount(6, $data['cases']);

        foreach ($data['cases'] as $case) {
            $effective = ($case['option_status_for_2027'] === 'effective'
                || $case['option_status_for_2027'] === 'effective_only_from_2027');
            $facts = new CooperativeIbsCbsFacts(
                optionConfirmed: $effective ? true : null,
                optionEffectiveFrom: $effective ? new DateTimeImmutable('2027-01-01') : null,
                optionFormFiled: $effective ? true : null,
                associatesListFiled: $case['associates_list_filed'],
                supplyRelation: $case['service_to_registered_member']
                    ? CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE
                    : CooperativeSupplyRelation::UNRELATED,
                associationVerifiedAtEvent: $case['service_to_registered_member'],
                associateIsRegularIbsCbsTaxpayer: $case['member_regular'],
            );

            self::assertSame($case['associates_list_filed'], $facts->associatesListFiled, $case['id']);
            if ($case['associates_list_filed'] === null) {
                self::assertNull($facts->optionConfirmed, $case['id']);
            }
            if ($case['competence'] < '2027-01-01') {
                self::assertGreaterThan(new DateTimeImmutable($case['competence']), $facts->optionEffectiveFrom, $case['id']);
            }
            // Input validation alone never grants a zero IBS/CBS rate.
        }
    }

    public function testSixAuditCounterexamplesReachTheActualEligibilityAssessor(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/fiscal-normative/nfse_427_cooperativas_casos_a_revisar.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $reference = [
            'COOP-2027-01' => ['pass', 'COOP-ZERO-COOP-TO-REGULAR-ASSOCIATE'],
            'COOP-2027-02' => ['not_applicable', 'COOP-ZERO-NOT-ELECTED'],
            'COOP-2027-03' => ['not_applicable', 'COOP-ZERO-NO-ASSOCIATION'],
            'COOP-2027-04' => ['not_applicable', 'COOP-ZERO-NOT-ELECTED'],
            'COOP-2027-05' => ['unverifiable', 'COOP-ZERO-ELECTION-UNVERIFIED'],
            'COOP-2026-06' => ['not_applicable', 'COOP-ZERO-BEFORE-2027'],
        ];
        self::assertCount(6, $data['cases']);

        foreach ($data['cases'] as $case) {
            [$status, $rule] = $reference[$case['id']];
            $effective = in_array($case['option_status_for_2027'], [
                'effective',
                'effective_only_from_2027',
            ], true);
            $option = $effective ? true : ($case['associates_list_filed'] === false ? false : null);
            $facts = new CooperativeIbsCbsFacts(
                optionConfirmed: $option,
                optionEffectiveFrom: $effective ? new DateTimeImmutable('2027-01-01') : null,
                optionFormFiled: $effective ? true : null,
                associatesListFiled: $case['associates_list_filed'],
                supplyRelation: $case['service_to_registered_member']
                    ? CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE
                    : CooperativeSupplyRelation::UNRELATED,
                associationVerifiedAtEvent: $case['service_to_registered_member'],
                associateIsRegularIbsCbsTaxpayer: $case['member_regular'],
                withinCooperativePurpose: true,
                forPersonalUseOrConsumption: false,
            );
            $date = new DateTimeImmutable($case['competence']);
            $context = new FiscalContext(
                companyId: 31,
                fiscalGroupKey: 'synthetic-coop-' . $case['id'],
                taxableEventDate: $date,
                competenceDate: $date,
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
                providerIsCooperative: $case['provider_coop'],
                cooperativeIbsCbsFacts: $facts,
            );

            $assessment = (new CooperativeIbsCbsEligibilityAssessor())->assess($context);
            self::assertCount(1, $assessment->decisions, $case['id']);
            self::assertSame($rule, $assessment->decisions[0]->ruleId, $case['id']);
            self::assertSame($status, $assessment->decisions[0]->status, $case['id']);
            self::assertNotEmpty($assessment->decisions[0]->sourceUrl, $case['id']);
            self::assertNull($assessment->decisions[0]->expectedCents, $case['id']);
        }
    }

    public function testConfirmedOptionRejectsMissingMandatoryList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CooperativeIbsCbsFacts(
            optionConfirmed: true,
            optionEffectiveFrom: new DateTimeImmutable('2027-01-01'),
            optionFormFiled: true,
            associatesListFiled: false,
        );
    }
}

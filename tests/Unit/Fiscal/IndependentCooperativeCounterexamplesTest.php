<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Fiscal;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Nfse\Contracts\CooperativeIbsCbsFacts;
use Modules\Nfse\Contracts\CooperativeSupplyRelation;
use PHPUnit\Framework\TestCase;

final class IndependentCooperativeCounterexamplesTest extends TestCase
{
    public function testSixHistoricalCounterexamplesDoNotTurnMissingProofIntoTaxExemption(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/fiscal-normative/nfse_427_cooperativas_casos_a_revisar.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('review_proposal_only_not_implemented_in_product', $data['status']);
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
            // There is no production fiscal eligibility selector yet. A
            // CooperativeIbsCbsFacts DTO by itself cannot assert zero IBS/CBS.
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

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use DateTimeImmutable;
use Modules\Nfse\Application\RtcSupplyAssessmentPolicy;
use Modules\Nfse\Contracts\FiscalContext;
use PHPUnit\Framework\TestCase;

final class RtcSupplyAssessmentPolicyTest extends TestCase
{
    /**
     * Expected dates and obligation flags are transcribed independently
     * from Ato Conjunto RFB/CGIBS 4/2026, art. 1, III and §1.
     *
     * @dataProvider officialCalendarCases
     */
    public function testOfficialOperationCalendar(
        string $category,
        string $lc116,
        string $before,
        string $on,
        string $effective,
    ): void {
        $policy = new RtcSupplyAssessmentPolicy();
        $prior = $policy->schedule(self::context(category: $category, event: $before, lc116: $lc116));
        $current = $policy->schedule(self::context(category: $category, event: $on, lc116: $lc116));

        self::assertFalse($prior['required']);
        self::assertTrue($current['required']);
        self::assertSame($effective, $prior['effective_date']);
        self::assertSame($effective, $current['effective_date']);
        self::assertSame('document_obligation_started', $current['reason']);
    }

    /** @return array<string,array{string,string,string,string,string}> */
    public static function officialCalendarCases(): array
    {
        return [
            'LC116 common' => ['ordinary_lc116', '0107', '2026-09-30', '2026-10-01', '2026-10-01'],
            'LC116 1.03' => ['ordinary_lc116', '0103', '2026-11-30', '2026-12-01', '2026-12-01'],
            'LC116 1.05' => ['ordinary_lc116', '0105', '2026-11-30', '2026-12-01', '2026-12-01'],
            'LC116 1.09' => ['ordinary_lc116', '0109', '2026-11-30', '2026-12-01', '2026-12-01'],
            'LC116 16.01' => ['ordinary_lc116', '1601', '2026-11-30', '2026-12-01', '2026-12-01'],
            'digital platform' => ['digital_platform', '0107', '2026-11-30', '2026-12-01', '2026-12-01'],
            'non ISS intangible' => ['non_iss_intangible', '', '2026-11-30', '2026-12-01', '2026-12-01'],
            'condominium revenue' => ['condominium_revenue', '', '2026-11-30', '2026-12-01', '2026-12-01'],
            'lease' => ['lease', '0107', '2026-11-30', '2026-12-01', '2026-12-01'],
            'residual services' => ['residual_service', '', '2026-11-30', '2026-12-01', '2026-12-01'],
        ];
    }

    public function testPlatformOperationOverridesOtherwiseOrdinaryLc116Calendar(): void
    {
        $policy = new RtcSupplyAssessmentPolicy();
        $ordinary = $policy->schedule(self::context(category: 'ordinary_lc116', event: '2026-10-01'));
        $platform = $policy->schedule(self::context(category: 'digital_platform', event: '2026-10-01'));

        self::assertTrue($ordinary['required']);
        self::assertFalse($platform['required']);
        self::assertSame('art. 1, III, a', $platform['provision']);
    }

    /** @dataProvider simplesCases */
    public function testSimplesHasOwnDocumentCalendarRegardlessOfPaymentElection(int $option, ?string $election): void
    {
        $policy = new RtcSupplyAssessmentPolicy();
        $before = $policy->schedule(self::context(
            category: 'ordinary_lc116',
            event: '2026-12-31',
            simples: $option,
            ibsRegime: $election,
        ));
        $after = $policy->schedule(self::context(
            category: 'ordinary_lc116',
            event: '2027-01-01',
            simples: $option,
            ibsRegime: $election,
        ));

        self::assertFalse($before['required']);
        self::assertTrue($after['required']);
        self::assertSame('2027-01-01', $after['effective_date']);
        self::assertStringContainsString('§1', (string) $after['provision']);
    }

    /** @return array<string,array{int,?string}> */
    public static function simplesCases(): array
    {
        return [
            'MEI' => [2, null],
            'ME EPP within Simples' => [3, 'simples'],
            'ME EPP regular IBS CBS election' => [3, 'regular'],
        ];
    }

    public function testUnknownCategoryNeverDefaultsToOrdinaryEvenWithLc116Code(): void
    {
        $policy = new RtcSupplyAssessmentPolicy();

        foreach ([null, '', 'not_registered_operation'] as $category) {
            $schedule = $policy->schedule(self::context(category: $category));
            self::assertNull($schedule['required']);
            self::assertSame('operation_category_unverifiable', $schedule['reason']);
        }
    }

    public function testAnOrdinaryCategoryWithoutVerifiedServiceSubitemRemainsUnknown(): void
    {
        $schedule = (new RtcSupplyAssessmentPolicy())->schedule(self::context(lc116: null));

        self::assertNull($schedule['required']);
        self::assertSame('lc116_subitem_unverifiable', $schedule['reason']);
    }

    public function testUnknownSimplesOptionDoesNotCreateAnAssumed2026Duty(): void
    {
        $schedule = (new RtcSupplyAssessmentPolicy())->schedule(self::context(simples: null));

        self::assertNull($schedule['required']);
        self::assertSame('simples_option_unverifiable', $schedule['reason']);
    }

    public function testEventDateIsNotReplacedWithCompetenceOrInvoiceIssueDate(): void
    {
        $policy = new RtcSupplyAssessmentPolicy();
        $before = $policy->schedule(self::context(event: '2026-09-30', competence: '2026-10-15'));
        $after = $policy->schedule(self::context(event: '2026-10-01', competence: '2026-09-01'));
        $noEvent = $policy->schedule(self::context(event: null, competence: '2027-02-01'));

        self::assertFalse($before['required']);
        self::assertTrue($after['required']);
        self::assertNull($noEvent['required']);
        self::assertSame('taxable_event_date_unverifiable', $noEvent['reason']);
    }

    public function testCompanyGroupTakerAndMunicipalityDoNotTurnIntoGlobalCategory(): void
    {
        $policy = new RtcSupplyAssessmentPolicy();
        $first = self::context(category: 'ordinary_lc116', companyId: 31, takerMunicipality: '3304557');
        $second = self::context(category: 'lease', companyId: 32, takerMunicipality: '3550308');

        $ordinary = $policy->assess($first);
        $lease = $policy->assess($second);

        self::assertSame($first, $ordinary->context);
        self::assertSame($second, $lease->context);
        self::assertTrue($policy->schedule($first)['required']);
        self::assertFalse($policy->schedule($second)['required']);
        self::assertCount(1, $ordinary->decisions);
        self::assertCount(2, $lease->decisions);
    }

    public function testEvidenceAndTechnicalCapabilityAreIndependentOfCalendar(): void
    {
        $policy = new RtcSupplyAssessmentPolicy();
        $assessment = $policy->assess(self::context(category: 'lease', event: '2026-12-01'));
        [$norm, $protocol] = $assessment->decisions;

        self::assertSame('normative', $norm->category);
        self::assertSame('unverifiable', $norm->status);
        self::assertSame('block', $norm->severity);
        self::assertSame(RtcSupplyAssessmentPolicy::SOURCE_URL, $norm->sourceUrl);
        self::assertSame('verify_required_ibscbs_document_fields', $norm->correctionCode);
        self::assertSame('protocol', $protocol->category);
        self::assertSame('unsupported', $protocol->status);
        self::assertSame('unsupported_current_lc116_dps_modality', $protocol->correctionCode);
        self::assertNull($norm->expectedCents);
        self::assertNull($norm->observedCents);
    }

    public function testPermissiveFakeSefinDoesNotRetroactivelyProveLegalCompliance(): void
    {
        // A fake transport authorization is intentionally not an input
        // to this pure fiscal rule. No fiscal network access is possible.
        $fakeSefin = static fn (): array => ['authorized' => true];
        self::assertTrue($fakeSefin()['authorized']);

        $assessment = (new RtcSupplyAssessmentPolicy())->assess(
            self::context(category: 'ordinary_lc116', event: '2026-10-01'),
        );

        self::assertSame('unverifiable', $assessment->decisions[0]->status);
        self::assertSame('block', $assessment->decisions[0]->severity);
    }

    public function testBeforeStartIsExplicitlyNotApplicableNotAnImplicitPass(): void
    {
        $assessment = (new RtcSupplyAssessmentPolicy())->assess(
            self::context(category: 'ordinary_lc116', event: '2026-09-30'),
        );

        self::assertSame('not_applicable', $assessment->decisions[0]->status);
        self::assertSame('info', $assessment->decisions[0]->severity);
        self::assertSame('2026-10-01', $assessment->decisions[0]->effectiveFrom?->format('Y-m-d'));
    }

    private static function context(
        ?string $category = 'ordinary_lc116',
        ?string $event = '2026-10-01',
        ?string $competence = '2026-10-01',
        ?string $lc116 = '0107',
        ?int $simples = 1,
        ?string $ibsRegime = null,
        int $companyId = 31,
        ?string $takerMunicipality = '3304557',
    ): FiscalContext {
        return new FiscalContext(
            companyId: $companyId,
            fiscalGroupKey: 'synthetic-company-' . $companyId,
            taxableEventDate: $event !== null ? new DateTimeImmutable($event) : null,
            competenceDate: $competence !== null ? new DateTimeImmutable($competence) : null,
            providerRegime: null,
            operationCategory: $category,
            lc116Subitem: $lc116,
            nationalTaxCode: null,
            nbsCode: null,
            incidenceMunicipalityIbge: '3304557',
            takerType: 'company',
            takerMunicipalityIbge: $takerMunicipality,
            takerCountryIso2: 'BR',
            takerIsSimples: null,
            grossServiceCents: 10000,
            opcaoSimplesNacional: $simples,
            ibsCbsRegime: $ibsRegime,
        );
    }
}

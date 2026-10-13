<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use DateTimeImmutable;
use Modules\Nfse\Application\RtcSupplyAssessmentPolicy;
use Modules\Nfse\Contracts\FiscalContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RtcSupplyAssessmentPolicyTest extends TestCase
{
    /**
     * Expected dates and obligation flags are transcribed independently
     * from Ato Conjunto RFB/CGIBS 4/2026, art. 1, III and §1.
     */
    #[DataProvider('officialCalendarCases')]
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

    #[DataProvider('simplesCases')]
    public function testSimplesCalendarDoesNotDependOnIbsCbsCollectionElection(
        string $category,
        ?string $lc116,
        int $option,
        ?string $election,
    ): void {
        $policy = new RtcSupplyAssessmentPolicy();
        $before = $policy->schedule(self::context(
            category: $category,
            event: '2026-12-31',
            lc116: $lc116,
            simples: $option,
            ibsRegime: $election,
        ));
        $after = $policy->schedule(self::context(
            category: $category,
            event: '2027-01-01',
            lc116: $lc116,
            simples: $option,
            ibsRegime: $election,
        ));

        self::assertFalse($before['required']);
        self::assertTrue($after['required']);
        self::assertSame('2027-01-01', $before['effective_date']);
        self::assertSame('2027-01-01', $after['effective_date']);
        self::assertStringContainsString('§1', (string) $after['provision']);
    }

    /** @return array<string,array{string,?string,int,?string}> */
    public static function simplesCases(): array
    {
        return [
            'MEI ordinary service' => ['ordinary_lc116', '0107', 2, null],
            'ME/EPP ordinary service' => ['ordinary_lc116', '0107', 3, 'simples'],
            'ME/EPP regular IBS CBS collection election' => ['ordinary_lc116', '0107', 3, 'regular'],
            'ME/EPP deferred ISS' => ['ordinary_lc116', '0103', 3, null],
            'ME/EPP non-ISS lease' => ['lease', null, 3, null],
            'MEI platform' => ['digital_platform', null, 2, null],
        ];
    }

    #[DataProvider('unverifiableInputs')]
    public function testUnknownFactsNeverBecomeDefaultNonOptantOrCompliant(
        ?string $category,
        ?string $service,
        ?string $event,
        ?int $simples,
        string $expectedReason,
        string $expectedField,
    ): void {
        $context = self::context(category: $category, lc116: $service, event: $event, simples: $simples);
        $policy = new RtcSupplyAssessmentPolicy();
        $schedule = $policy->schedule($context);
        $assessment = $policy->assess($context);
        $decision = $assessment->decisions[0];

        self::assertNull($schedule['required']);
        self::assertSame($expectedReason, $schedule['reason']);
        self::assertSame('unverifiable', $decision->status);
        self::assertSame('warn', $decision->severity);
        self::assertSame($expectedField, $decision->fieldPath);
        self::assertSame($expectedReason, $decision->correctionCode);
        self::assertNull($decision->expectedCents);
        self::assertNull($decision->observedCents);
    }

    /** @return array<string,array{?string,?string,?string,?int,string,string}> */
    public static function unverifiableInputs(): array
    {
        return [
            'category missing' => [null, '0107', '2026-10-01', 1, 'operation_category_unverifiable', 'operationCategory'],
            'category blank' => ['', '0107', '2026-10-01', 1, 'operation_category_unverifiable', 'operationCategory'],
            'category unknown' => ['not_registered_operation', '0107', '2026-10-01', 1, 'operation_category_unverifiable', 'operationCategory'],
            'ISS subitem missing' => ['ordinary_lc116', null, '2026-10-01', 1, 'lc116_subitem_unverifiable', 'lc116Subitem'],
            'ISS subitem invalid' => ['ordinary_lc116', 'bad code', '2026-10-01', 1, 'lc116_subitem_unverifiable', 'lc116Subitem'],
            'Simples option unknown' => ['ordinary_lc116', '0107', '2026-10-01', null, 'simples_option_unverifiable', 'opcaoSimplesNacional'],
            'event date unknown' => ['ordinary_lc116', '0107', null, 1, 'taxable_event_date_unverifiable', 'taxableEventDate'],
            'non ISS event unknown' => ['lease', null, null, 1, 'taxable_event_date_unverifiable', 'taxableEventDate'],
            'non ISS regime unknown' => ['digital_platform', null, '2026-12-01', null, 'simples_option_unverifiable', 'opcaoSimplesNacional'],
        ];
    }

    #[DataProvider('conflictingDateCases')]
    public function testTaxableEventDateNeverFallsBackToCompetence(
        ?string $event,
        ?string $competence,
        ?bool $expected,
    ): void {
        $schedule = (new RtcSupplyAssessmentPolicy())->schedule(
            self::context(event: $event, competence: $competence),
        );

        self::assertSame($expected, $schedule['required']);
    }

    /** @return array<string,array{?string,?string,?bool}> */
    public static function conflictingDateCases(): array
    {
        return [
            'event before start and competence after' => ['2026-09-30', '2026-10-15', false],
            'event on start and competence before' => ['2026-10-01', '2026-09-01', true],
            'event missing and competence in 2027' => [null, '2027-02-01', null],
            'event after start and competence missing' => ['2026-10-02', null, true],
            'previous historical event and current competence' => ['2025-10-01', '2026-10-01', false],
        ];
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
        self::assertSame('warn', $norm->severity);
        self::assertSame(RtcSupplyAssessmentPolicy::SOURCE_URL, $norm->sourceUrl);
        self::assertSame('verify_required_ibscbs_document_fields', $norm->correctionCode);
        self::assertSame('protocol', $protocol->category);
        self::assertSame('unsupported', $protocol->status);
        self::assertSame('unsupported_current_lc116_dps_modality', $protocol->correctionCode);
        self::assertNull($norm->expectedCents);
        self::assertNull($norm->observedCents);
    }

    #[DataProvider('simulatedSefinOutcomes')]
    public function testFakeSefinResponseCannotRewriteLegalCalendar(bool $authorized): void
    {
        // The pure assessment has no external transport input. Even a fake
        // successful response is insufficient evidence of complete IBS/CBS.
        $fakeSefin = static fn (): array => ['authorized' => $authorized];
        self::assertSame($authorized, $fakeSefin()['authorized']);

        $assessment = (new RtcSupplyAssessmentPolicy())->assess(
            self::context(category: 'ordinary_lc116', event: '2026-10-01'),
        );
        self::assertSame('unverifiable', $assessment->decisions[0]->status);
        self::assertSame('warn', $assessment->decisions[0]->severity);
        self::assertSame('verify_required_ibscbs_document_fields', $assessment->decisions[0]->correctionCode);
    }

    /** @return array<string,array{bool}> */
    public static function simulatedSefinOutcomes(): array
    {
        return ['fake accepts' => [true], 'fake rejects' => [false]];
    }

    #[DataProvider('nonLc116Modalities')]
    public function testUnsupportedProtocolIsSeparateFromDocumentCalendar(
        string $category,
        string $expectedProvision,
    ): void {
        $policy = new RtcSupplyAssessmentPolicy();

        foreach (['2026-11-30', '2026-12-01'] as $date) {
            $context = self::context(category: $category, lc116: null, event: $date);
            $assessment = $policy->assess($context);
            self::assertCount(2, $assessment->decisions);
            self::assertSame($expectedProvision, $assessment->decisions[0]->sourceProvision);
            self::assertSame($date === '2026-11-30' ? 'not_applicable' : 'unverifiable', $assessment->decisions[0]->status);
            self::assertSame('protocol', $assessment->decisions[1]->category);
            self::assertSame('unsupported', $assessment->decisions[1]->status);
            self::assertSame('block', $assessment->decisions[1]->severity);
        }
    }

    /** @return array<string,array{string,string}> */
    public static function nonLc116Modalities(): array
    {
        return [
            'digital platforms' => ['digital_platform', 'art. 1, III, a'],
            'non ISS intangibles' => ['non_iss_intangible', 'art. 1, III, e'],
            'condominium revenue' => ['condominium_revenue', 'art. 1, III, f'],
            'leasing' => ['lease', 'art. 1, III, g'],
            'residual' => ['residual_service', 'art. 1, III, h'],
        ];
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

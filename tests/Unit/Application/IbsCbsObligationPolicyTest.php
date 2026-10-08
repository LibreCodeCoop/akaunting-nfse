<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\IbsCbsEmissionReadiness;
use Modules\Nfse\Application\IbsCbsObligationPolicy;
use PHPUnit\Framework\TestCase;

final class IbsCbsObligationPolicyTest extends TestCase
{
    public function testGeneralLc116ServiceBecomesRequiredOnOctoberFirst2026(): void
    {
        $policy = new IbsCbsObligationPolicy();

        self::assertFalse($policy->evaluate('2026-09-30', 1, '0101')['required']);
        self::assertTrue($policy->evaluate('2026-10-01', 1, '0101')['required']);
    }

    /**
     * @dataProvider deferredServices
     */
    public function testDeferredLc116ServicesBecomeRequiredOnDecemberFirst2026(string $service): void
    {
        $policy = new IbsCbsObligationPolicy();

        self::assertFalse($policy->evaluate('2026-11-30', 1, $service)['required']);
        self::assertTrue($policy->evaluate('2026-12-01', 1, $service)['required']);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function deferredServices(): array
    {
        return [
            '1.03' => ['0103'],
            '1.05' => ['0105'],
            '1.09' => ['0109'],
            '16.01' => ['1601'],
        ];
    }

    /**
     * @dataProvider simplesOptions
     */
    public function testSimplesBecomesRequiredOnlyIn2027(int $option): void
    {
        $policy = new IbsCbsObligationPolicy();

        self::assertFalse($policy->evaluate('2026-12-31', $option, '0101')['required']);
        self::assertTrue($policy->evaluate('2027-01-01', $option, '0101')['required']);
    }

    /**
     * @return array<string,array{0:int}>
     */
    public static function simplesOptions(): array
    {
        return [
            'MEI' => [2],
            'ME/EPP' => [3],
        ];
    }

    public function testUnknownSimplesRegimeIsUnverifiable(): void
    {
        $result = (new IbsCbsObligationPolicy())->evaluate('2026-10-08', 9, '0101');

        self::assertFalse($result['required']);
        self::assertSame('unverifiable', $result['reason']);
        self::assertNull($result['effective_date']);
    }

    public function testUnverifiableContextBlocksIssuanceInsteadOfGuessing(): void
    {
        $result = (new IbsCbsEmissionReadiness())->evaluate(
            '',
            1,
            '',
            [],
        );

        self::assertFalse($result['isReady']);
        self::assertFalse($result['required']);
        self::assertSame('unverifiable', $result['reason']);
        self::assertSame(['ibs_cbs_obligation_context'], $result['missing']);
    }

    public function testRequiredOperationBlocksWhenIbsCbsConfigurationIsIncomplete(): void
    {
        $result = (new IbsCbsEmissionReadiness())->evaluate(
            '2026-10-08',
            1,
            '0101',
            [
                'ibs_cbs_enabled' => false,
                'ibs_cbs_c_ind_op' => '',
                'ibs_cbs_ind_dest' => '',
                'ibs_cbs_cst' => '',
                'ibs_cbs_c_class_trib' => '',
            ],
        );

        self::assertFalse($result['isReady']);
        self::assertTrue($result['required']);
        self::assertSame(
            [
                'ibs_cbs_enabled',
                'ibs_cbs_c_ind_op',
                'ibs_cbs_ind_dest',
                'ibs_cbs_cst',
                'ibs_cbs_c_class_trib',
            ],
            $result['missing'],
        );
    }

    public function testOptionalIbsCbsConfigurationIsValidatedWhenEnabledBeforeCutoff(): void
    {
        $result = (new IbsCbsEmissionReadiness())->evaluate(
            '2026-09-30',
            1,
            '0101',
            [
                'ibs_cbs_enabled' => true,
                'ibs_cbs_c_ind_op' => '123',
                'ibs_cbs_ind_dest' => '0',
                'ibs_cbs_cst' => '000',
                'ibs_cbs_c_class_trib' => '000001',
            ],
        );

        self::assertFalse($result['isReady']);
        self::assertFalse($result['required']);
        self::assertSame('invalid_configuration', $result['reason']);
        self::assertSame(['ibs_cbs_c_ind_op'], $result['missing']);
    }

    /**
     * @dataProvider nonLc116Categories
     */
    public function testNonLc116RtcDateIsKnownButDpsRemainsUnsupported(string $category): void
    {
        $readiness = new IbsCbsEmissionReadiness();

        foreach ([
            '2026-11-30' => false,
            '2026-12-01' => true,
        ] as $date => $required) {
            $result = $readiness->evaluate($date, 1, '0107', [], $category);
            self::assertFalse($result['isReady']);
            self::assertSame($required, $result['required']);
            self::assertSame('2026-12-01', $result['effective_date']);
            self::assertSame('unsupported_rtc_supply_category', $result['reason']);
            self::assertSame(['rtc_supply_category'], $result['missing']);
        }
    }

    /** @return array<string,array{string}> */
    public static function nonLc116Categories(): array
    {
        return [
            'platform' => ['digital_platform'],
            'intangible' => ['non_iss_intangible'],
            'condominium' => ['condominium_revenue'],
            'lease' => ['lease'],
            'residual' => ['residual_service'],
        ];
    }

    public function testNonLc116SimplesCalendarDoesNotConferTechnicalSupport(): void
    {
        $readiness = new IbsCbsEmissionReadiness();
        $before = $readiness->evaluate('2026-12-31', 3, '', [], 'lease');
        $after = $readiness->evaluate('2027-01-01', 3, '', [], 'lease');

        self::assertFalse($before['required']);
        self::assertTrue($after['required']);
        self::assertSame('2027-01-01', $after['effective_date']);
        self::assertFalse($before['isReady']);
        self::assertFalse($after['isReady']);
    }

    public function testUnknownRtcCategoryIsUnverifiableInsteadOfOrdinary(): void
    {
        $result = (new IbsCbsEmissionReadiness())->evaluate(
            '2026-12-01', 1, '0107', [], 'not_a_category',
        );

        self::assertFalse($result['isReady']);
        self::assertSame('unverifiable', $result['reason']);
        self::assertSame(['ibs_cbs_obligation_context'], $result['missing']);
    }

    public function testOptionalIbsCbsMayRemainDisabledBeforeCutoff(): void
    {
        $result = (new IbsCbsEmissionReadiness())->evaluate(
            '2026-09-30',
            1,
            '0101',
            ['ibs_cbs_enabled' => false],
        );

        self::assertTrue($result['isReady']);
        self::assertFalse($result['required']);
        self::assertSame([], $result['missing']);
    }

    public function testRequiredOperationRejectsMalformedIbsCbsCodes(): void
    {
        $result = (new IbsCbsEmissionReadiness())->evaluate(
            '2026-10-08',
            1,
            '0101',
            [
                'ibs_cbs_enabled' => true,
                'ibs_cbs_ind_final' => '2',
                'ibs_cbs_c_ind_op' => '123',
                'ibs_cbs_ind_dest' => '0',
                'ibs_cbs_cst' => '00',
                'ibs_cbs_c_class_trib' => 'ABC001',
            ],
        );

        self::assertFalse($result['isReady']);
        self::assertSame(
            [
                'ibs_cbs_ind_final',
                'ibs_cbs_c_ind_op',
                'ibs_cbs_cst',
                'ibs_cbs_c_class_trib',
            ],
            $result['missing'],
        );
    }

    public function testRequiredOperationIsReadyWithCompleteIbsCbsConfiguration(): void
    {
        $result = (new IbsCbsEmissionReadiness())->evaluate(
            '2026-10-08',
            1,
            '0101',
            [
                'ibs_cbs_enabled' => true,
                'ibs_cbs_c_ind_op' => '010101',
                'ibs_cbs_ind_dest' => '0',
                'ibs_cbs_cst' => '000',
                'ibs_cbs_c_class_trib' => '000001',
            ],
        );

        self::assertTrue($result['isReady']);
        self::assertTrue($result['required']);
        self::assertSame([], $result['missing']);
    }
}

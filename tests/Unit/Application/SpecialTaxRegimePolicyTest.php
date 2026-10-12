<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\SpecialTaxRegimePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpecialTaxRegimePolicyTest extends TestCase
{
    public function testMissingConfigurationDoesNotBecomeOfficialZero(): void
    {
        $policy = new SpecialTaxRegimePolicy();

        self::assertSame('missing', $policy->evaluate(null, 1, 1)['state']);
        self::assertFalse($policy->evaluate('', 1, 1)['can_emit']);
        self::assertSame('none', $policy->evaluate('0', 1, 1)['state']);
        self::assertSame(0, $policy->forIssuance('0', 1, 1, null));
    }

    /** @return iterable<string,array{int}> */
    public static function nonzeroCodes(): iterable
    {
        foreach ([1, 2, 3, 4, 5, 6, 9] as $code) {
            yield 'official_' . $code => [$code];
        }
    }

    #[DataProvider('nonzeroCodes')]
    public function testEachNonzeroOfficialCodeRemainsDistinguishableAndRequiresMunicipalReview(int $code): void
    {
        $assessment = (new SpecialTaxRegimePolicy())->evaluate((string) $code, 1, 1, '2026-10-05');

        self::assertSame($code, $assessment['code']);
        self::assertSame('special', $assessment['state']);
        self::assertTrue($assessment['can_emit']);
        self::assertTrue($assessment['municipal_review_required']);
        self::assertSame('municipal_evidence_unverified', $assessment['reason']);
    }

    /** @return iterable<string,array{int}> */
    public static function nonTaxableIssqn(): iterable
    {
        yield 'immunity' => [2];
        yield 'export' => [3];
        yield 'non_incidence' => [4];
    }

    #[DataProvider('nonTaxableIssqn')]
    public function testNonTaxableIssqnRequiresZeroUnderE0172(int $issqn): void
    {
        $policy = new SpecialTaxRegimePolicy();
        self::assertSame('non_taxable_requires_none', $policy->evaluate('6', 1, $issqn)['reason']);
        self::assertFalse($policy->evaluate('6', 1, $issqn)['can_emit']);
        self::assertTrue($policy->evaluate('0', 1, $issqn)['can_emit']);
    }

    public function testMeiAlwaysRequiresZeroUnderE0174(): void
    {
        $policy = new SpecialTaxRegimePolicy();
        self::assertSame('mei_requires_none', $policy->evaluate('1', 2, 1)['reason']);
        self::assertTrue($policy->evaluate('0', 2, 1)['can_emit']);
    }

    public function testMeEppNonzeroIsUnsupportedWithoutRegApTribSnRatherThanLegallyForbidden(): void
    {
        $assessment = (new SpecialTaxRegimePolicy())->evaluate('1', 3, 1, '2026-10-05');

        self::assertSame('simples_apuration_not_supported', $assessment['reason']);
        self::assertSame('unsupported', $assessment['state']);
        self::assertFalse($assessment['can_emit']);
        self::assertTrue((new SpecialTaxRegimePolicy())->evaluate('0', 3, 1)['can_emit']);
    }

    public function testUndeclaredAndMalformedCodesAreRejectedWithoutCoercion(): void
    {
        $policy = new SpecialTaxRegimePolicy();

        foreach (['', '00', '7', '8', '10', '-1', '1x', '0.0', true, []] as $invalid) {
            self::assertFalse($policy->evaluate($invalid, 1, 1)['can_emit']);
        }
    }

    public function testActualCompetenceMustBeValidForNonzeroRegime(): void
    {
        $policy = new SpecialTaxRegimePolicy();

        self::assertSame('invalid_competence', $policy->evaluate('1', 1, 1, '2026-02-30')['reason']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_or_invalid_competence');
        $policy->forIssuance('1', 1, 1, null);
    }

    public function testDifferentConfirmedIssIncidenceMunicipalityRestrictsSpecialCode(): void
    {
        $policy = new SpecialTaxRegimePolicy();
        $crossed = $policy->evaluate('6', 1, 1, '2026-10-05', '3303302', '3304557');
        self::assertSame('cross_municipality_requires_none_or_autonomous', $crossed['reason']);
        self::assertFalse($crossed['can_emit']);
        self::assertTrue($policy->evaluate('5', 1, 1, '2026-10-05', '3303302', '3304557')['can_emit']);
        self::assertTrue($policy->evaluate('6', 1, 1, '2026-10-05', '3303302', '3303302')['can_emit']);
        self::assertTrue($policy->evaluate('6', 1, 1, '2026-10-05', '3303302', null)['can_emit']);
    }

    public function testEmittableRegimeKeepsOriginalOfficialCode(): void
    {
        self::assertSame(9, (new SpecialTaxRegimePolicy())->forIssuance('9', 1, 1, '2026-12-31'));
    }
}

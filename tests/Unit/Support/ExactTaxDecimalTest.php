<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\ExactTaxDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExactTaxDecimalTest extends TestCase
{
    #[DataProvider('observedAmountCases')]
    public function testPersistedAmountIsPreservedWithoutGuessingARoundingRule(
        string|int|null $amount,
        ?int $expectedCents,
    ): void {
        self::assertSame($expectedCents, ExactTaxDecimal::cents($amount));
    }

    /**
     * @return array<string,array{0:string|int|null,1:?int}>
     */
    public static function observedAmountCases(): array
    {
        return [
            'unknown amount remains unknown' => [null, null],
            'zero remains an observed zero' => ['0.00', 0],
            'large exact monetary observation' => ['1234567.89', 123456789],
            'negative accounting credit is preserved at ingestion' => ['-1.99', -199],
            'only trailing zero subcents may be removed' => ['1.0500', 105],
            'integer whole amount is accepted' => [7, 700],
        ];
    }

    #[DataProvider('invalidObservationCases')]
    public function testTaxAmountWithoutExactDecimalEvidenceCannotBeSilentlyRounded(mixed $value): void
    {
        $this->expectException(\DomainException::class);
        ExactTaxDecimal::cents($value);
    }

    /**
     * @return array<string,array{0:mixed}>
     */
    public static function invalidObservationCases(): array
    {
        return [
            'binary floating point is not a decimal source' => [0.1],
            'fractional cent requires a verified rounding policy' => ['1.005'],
            'locale-formatted decimal is not an exact database value' => ['1,05'],
            'exponential notation cannot be assumed exact' => ['1e3'],
        ];
    }

    #[DataProvider('nt007HalfEvenCases')]
    public function testNt007HalfEvenCalculationOnlyUsesAGivenVerifiedBaseAndRate(
        int $baseCents,
        string $ratePercent,
        int $expectedCents,
    ): void {
        self::assertSame($expectedCents, ExactTaxDecimal::percentOfCentsHalfEven($baseCents, $ratePercent));
    }

    /**
     * Independent cent oracles. Percentages and bases below are synthetic,
     * not automatic legal rates or proof of incidence for an invoice.
     *
     * @return array<string,array{0:int,1:string,2:int}>
     */
    public static function nt007HalfEvenCases(): array
    {
        return [
            'even zero-cent tie rounds down' => [100, '0.5', 0],
            'odd one-cent tie rounds up' => [300, '0.5', 2],
            'even two-cent tie rounds down' => [500, '0.5', 2],
            'odd three-cent tie rounds up' => [700, '0.5', 4],
            'fraction below one half rounds down' => [40, '0.5', 0],
            'fraction above one half rounds up' => [150, '0.5', 1],
            'PIS rate is applied only to supplied base' => [10000, '0.65', 65],
            'COFINS-style sample never uses binary floats' => [2000, '1.65', 33],
            'zero legal base yields zero' => [0, '0.65', 0],
        ];
    }

    #[DataProvider('invalidCalculationCases')]
    public function testUnverifiableCalculationIsNotConvertedIntoARoundedTax(
        int $baseCents,
        string $percent,
        string $expectedException,
    ): void {
        $this->expectException($expectedException);
        ExactTaxDecimal::percentOfCentsHalfEven($baseCents, $percent);
    }

    /**
     * @return array<string,array{0:int,1:string,2:class-string<\Throwable>}>
     */
    public static function invalidCalculationCases(): array
    {
        return [
            'negative base needs a dedicated statutory rule' => [-100, '0.65', \DomainException::class],
            'negative rate is not an observed positive burden' => [100, '-0.65', \DomainException::class],
            'unsupported rate precision is not guessed' => [100, '0.6500001', \DomainException::class],
            'integer overflow must fail rather than use float' => [PHP_INT_MAX, '100', \OverflowException::class],
        ];
    }

    #[DataProvider('amountPresentationCases')]
    public function testFormattedAmountMatchesTheObservedIntegerCents(int $cents, string $expected): void
    {
        self::assertSame($expected, ExactTaxDecimal::formatCents($cents));
    }

    /**
     * @return array<string,array{0:int,1:string}>
     */
    public static function amountPresentationCases(): array
    {
        return [
            'zero amount' => [0, '0.00'],
            'normal amount' => [100009, '1000.09'],
            'negative credit amount' => [-25, '-0.25'],
        ];
    }
}

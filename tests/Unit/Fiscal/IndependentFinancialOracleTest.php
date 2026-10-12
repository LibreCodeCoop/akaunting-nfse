<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Fiscal;

use Modules\Nfse\Application\FederalSocialRetentionCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The independent numbers below are hypothesis-specific reference arithmetic,
 * never a tax eligibility decision or a substitute for an authorized XML.
 */
final class IndependentFinancialOracleTest extends TestCase
{
    public function testAllTwentyFiveFinancialCasesHaveTraceableUniqueReferences(): void
    {
        $document = self::fixture('nfse_427_oraculos_sinteticos_2026-10-12.json');
        $cases = $document['cases'];
        self::assertCount(25, $cases);
        self::assertSame(25, count(array_unique(array_column($cases, 'id'))));

        $moneyFields = 0;
        foreach ($cases as $case) {
            self::assertNotEmpty($case['source_refs'], $case['id']);
            foreach ($case['source_refs'] as $reference) {
                self::assertArrayHasKey($reference, $document['source_catalog'], $case['id']);
            }
            foreach ($case['expected'] as $field => $expectation) {
                if ($field !== 'servico_lc116' && is_string($expectation) && preg_match('/^\d+\.\d{2}$/', $expectation) === 1) {
                    $moneyFields++;
                }
            }
        }

        self::assertSame(29, $moneyFields); // Two LC116 item codes also resemble BRL decimals.
    }

    public function testTwentyNineMonetaryAndTwoRetentionTypeExpectationsAreIndependent(): void
    {
        $cases = array_column(self::fixture('nfse_427_oraculos_sinteticos_2026-10-12.json')['cases'], 'expected', 'id');

        // Rates, bases and exemption boundaries come from the independently
        // reviewed scenario facts, not from any production calculator method.
        $reference = [
            'RET-01' => [
                'pis_retido' => self::percent('10000', '0.65'),
                'cofins_retido' => self::percent('10000', '3'),
                'csll_retida' => self::percent('10000', '1'),
                'vRetCSLL' => self::percent('10000', '4.65'),
                'tpRetPisCofins' => 3,
            ],
            'RET-02' => [
                'pis_retido' => self::percent('10000', '0.65'),
                'cofins_retido' => self::percent('10000', '3'),
                'csll_retida' => '0.00',
                'vRetCSLL' => self::percent('10000', '3.65'),
                'tpRetPisCofins' => 4,
            ],
            'RET-03' => [
                'calculated_candidate' => self::percent('200', '4.65'),
                'actually_withheld' => '0.00',
            ],
            'RET-04' => ['actually_withheld' => self::percent('215.27', '4.65')],
            'IRRF-01' => ['irrf' => self::percent('10000', '1.5')],
            'IRRF-02' => ['irrf' => self::percent('10000', '4.8')],
            'IRRF-03' => ['irrf' => self::percent('10000', '4.8')],
            'CP-01' => ['retencao_previdenciaria' => self::percent('10000', '11')],
            'CP-03' => ['retencao_previdenciaria_condicional' => self::percent('10000', '11')],
            'PISCOFINS-01' => [
                'pis_proprio_bruto' => self::percent('10000', '0.65'),
                'cofins_propria_bruta' => self::percent('10000', '3'),
            ],
            'PISCOFINS-02' => [
                'pis_proprio_bruto' => self::percent('10000', '1.65'),
                'cofins_propria_bruta' => self::percent('10000', '7.6'),
            ],
            'IBSCBS-01' => [
                'ibs_test' => self::percent('10000', '0.1'),
                'cbs_test' => self::percent('10000', '0.9'),
            ],
            'IBS-2027' => [
                'ibs_estadual' => self::percent('10000', '0.05'),
                'ibs_municipal' => self::percent('10000', '0.05'),
            ],
            'ISS-ARITH' => ['iss_if_rate_3pct' => self::percent('10000', '3')],
            'ISS-SP-2026-101' => ['iss_proprio_condicional' => self::percent('10000', '2.9')],
            'ISS-SP-2026-109' => ['iss_proprio_condicional' => self::percent('10000', '2')],
            'NT007-HALFEVEN-01' => ['rounded_value' => self::roundThousandths('1.005')],
            'NT007-HALFEVEN-02' => ['rounded_value' => self::roundThousandths('1.015')],
        ];

        $checked = 0;
        foreach ($reference as $caseId => $fields) {
            self::assertArrayHasKey($caseId, $cases);
            foreach ($fields as $field => $value) {
                self::assertSame($value, $cases[$caseId][$field] ?? null, $caseId . '/' . $field);
                $checked++;
            }
        }
        self::assertSame(31, $checked); // 29 BRL amount fields plus two NT007 retention enums.
    }

    public function testConditionalRetentionsMatchProductionBucketsWithoutMixingOwnTaxes(): void
    {
        $cases = array_column(self::fixture('nfse_427_oraculos_sinteticos_2026-10-12.json')['cases'], 'expected', 'id');
        $calculator = new FederalSocialRetentionCalculator();
        $withholdings = [
            ['tax_type' => 'withholding', 'name' => 'PIS', 'amount' => 65.00],
            ['tax_type' => 'withholding', 'name' => 'COFINS', 'amount' => 300.00],
            ['tax_type' => 'withholding', 'name' => 'CSLL', 'amount' => 100.00],
            ['tax_type' => 'normal', 'name' => 'PIS', 'amount' => 165.00],
            ['tax_type' => 'normal', 'name' => 'COFINS', 'amount' => 760.00],
            ['tax_type' => 'withholding', 'name' => 'IRRF', 'amount' => 150.00],
        ];

        $normal = $calculator->calculate('3', $withholdings);
        self::assertSame($cases['RET-01']['vRetCSLL'], $normal['total']);
        self::assertSame($cases['RET-01']['pis_retido'], $normal['pis']);
        self::assertSame($cases['RET-01']['cofins_retido'], $normal['cofins']);
        self::assertSame($cases['RET-01']['csll_retida'], $normal['csll']);

        $cooperative = $calculator->calculate('4', $withholdings);
        self::assertSame($cases['RET-02']['vRetCSLL'], $cooperative['total']);
        self::assertSame('', $cooperative['csll']);

        // This checks the calculator's aggregate output, NOT how an XML field
        // is serialized by InvoiceFederalPayloadResolver (other front owner).
        self::assertSame('', $calculator->calculate('0', $withholdings)['total']);
    }

    public function testMissingLegalInputsNeverBecomeFictitiousTaxAmounts(): void
    {
        $cases = array_column(self::fixture('nfse_427_oraculos_sinteticos_2026-10-12.json')['cases'], 'expected', 'id');
        self::assertSame('unverifiable', $cases['TRP-01']['tax_approximation']);
        self::assertTrue($cases['TRP-01']['cannot_autofill_zero']);
        self::assertSame('optional', $cases['TRP-03']['disclosure_under_decree_8264']);
        self::assertSame('unverifiable', $cases['ISS-ARITH']['fiscal_status']);
        self::assertSame('unverifiable_without_relevant_2027_rate', $cases['IBS-2027']['cbs_rate']);
        self::assertSame('not_required', $cases['RET-05']['social_withholding_under_art30']);
        self::assertSame('not_required', $cases['RET-06']['social_withholding_under_art30']);
    }

    public function testIndependentHalfEvenRoundingIncludesExactTies(): void
    {
        self::assertSame('1.00', self::roundThousandths('1.005'));
        self::assertSame('1.02', self::roundThousandths('1.015'));
        self::assertSame('1.02', self::roundThousandths('1.025'));
        self::assertSame('1.04', self::roundThousandths('1.035'));
        self::assertSame('10.01', self::percent('215.27', '4.65'));
    }

    /** @return array<string,mixed> */
    private static function fixture(string $name): array
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/fixtures/fiscal-normative/' . $name);
        self::assertIsString($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function percent(string $base, string $percent): string
    {
        $cents = self::roundHalfEven(self::scaled($base, 2) * self::scaled($percent, 2), 10000);

        return self::formatCents($cents);
    }

    private static function roundThousandths(string $value): string
    {
        return self::formatCents(self::roundHalfEven(self::scaled($value, 3), 10));
    }

    private static function scaled(string $value, int $places): int
    {
        if (preg_match('/^\d+(?:\.\d{1,' . $places . '})?$/', $value) !== 1) {
            throw new \InvalidArgumentException('Only unsigned exact decimals are supported.');
        }

        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * (10 ** $places) + (int) str_pad($decimal, $places, '0');
    }

    private static function roundHalfEven(int $numerator, int $denominator): int
    {
        $whole = intdiv($numerator, $denominator);
        $rem = $numerator % $denominator;

        return $whole + (int) (
            ($rem * 2 > $denominator)
            || ($rem * 2 === $denominator && $whole % 2 !== 0)
        );
    }

    private static function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}

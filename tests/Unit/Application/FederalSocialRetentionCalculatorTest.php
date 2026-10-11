<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\FederalSocialRetentionCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FederalSocialRetentionCalculatorTest extends TestCase
{
    #[DataProvider('types')]
    public function testNt007RetentionTypes(string $type, string $expected): void
    {
        $rows = [
            ['tax_type' => 'withholding', 'name' => 'PIS', 'amount' => 204.75],
            ['tax_type' => 'withholding', 'name' => 'COFINS', 'amount' => 945.00],
            ['tax_type' => 'withholding', 'name' => 'CSLL', 'amount' => 315.00],
            ['tax_type' => 'withholding', 'name' => 'IRRF', 'amount' => 472.50],
            ['tax_type' => 'normal', 'name' => 'PIS', 'amount' => 204.75],
        ];

        self::assertSame(
            $expected,
            (new FederalSocialRetentionCalculator())->calculate($type, $rows)['total'],
        );
    }

    public static function types(): array
    {
        return [
            'no retention' => ['0', ''],
            'old no retention' => ['2', ''],
            'legacy PIS COFINS retained' => ['1', '1149.75'],
            'all retained' => ['3', '1464.75'],
            'PIS COFINS not CSLL' => ['4', '1149.75'],
            'PIS only' => ['5', '204.75'],
            'COFINS only' => ['6', '945.00'],
            'COFINS and CSLL' => ['7', '1260.00'],
            'CSLL only' => ['8', '315.00'],
            'PIS and CSLL' => ['9', '519.75'],
        ];
    }

    public function testRepeatedSameAmountsOnDistinctItemsAreNotDropped(): void
    {
        $rows = [
            ['name' => 'PIS', 'amount' => 5.00],
            ['name' => 'PIS', 'amount' => 5.00],
        ];

        self::assertSame('10.00', (new FederalSocialRetentionCalculator())->calculate('5', $rows)['total']);
    }

    public function testXmlTotalIncludesPisCofinsWhenCsllItselfIsNotRetained(): void
    {
        $actual = (new FederalSocialRetentionCalculator())->calculate('4', [
            ['tax_type' => 'withholding', 'name' => 'PIS', 'amount' => 204.75],
            ['tax_type' => 'withholding', 'name' => 'COFINS', 'amount' => 945.00],
        ]);

        self::assertSame('1149.75', $actual['total']);
        self::assertSame('', $actual['csll']);
    }

    public function testMissingWithholdingDoesNotProduceFictitiousZeroCsll(): void
    {
        self::assertSame('', (new FederalSocialRetentionCalculator())->calculate('4', [])['total']);
    }
}

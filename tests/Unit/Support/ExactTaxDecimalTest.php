<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\ExactTaxDecimal;
use PHPUnit\Framework\TestCase;

final class ExactTaxDecimalTest extends TestCase
{
    public function testPreservesExactCentsAndDistinguishesUnknownFromZero(): void
    {
        self::assertNull(ExactTaxDecimal::cents(null));
        self::assertSame(0, ExactTaxDecimal::cents('0.00'));
        self::assertSame(123456789, ExactTaxDecimal::cents('1234567.89'));
        self::assertSame(-199, ExactTaxDecimal::cents('-1.99'));
        self::assertSame(105, ExactTaxDecimal::cents('1.0500'));
        self::assertSame('0.650000', ExactTaxDecimal::rate('0.650000'));
    }

    public function testRefusesToRoundLegalAmountsWithoutARule(): void
    {
        $this->expectException(\DomainException::class);
        ExactTaxDecimal::cents('1.005');
    }

    public function testNt007HalfEvenRoundingAtExactHalfCentTies(): void
    {
        // Independent decimal oracles: 1.00 * 0.5% = 0.005 => 0.00,
        // 3.00 * 0.5% = 0.015 => 0.02 under half-even.
        self::assertSame(0, ExactTaxDecimal::percentOfCentsHalfEven(100, '0.5'));
        self::assertSame(2, ExactTaxDecimal::percentOfCentsHalfEven(300, '0.5'));
        self::assertSame(33, ExactTaxDecimal::percentOfCentsHalfEven(2000, '1.65'));
        self::assertSame('1000.09', ExactTaxDecimal::formatCents(100009));
    }

    public function testNt007RejectsOverflowInsteadOfChangingToFloat(): void
    {
        $this->expectException(\OverflowException::class);
        ExactTaxDecimal::percentOfCentsHalfEven(PHP_INT_MAX, '100');
    }

    public function testRefusesBinaryFloatAmounts(): void
    {
        $this->expectException(\DomainException::class);
        ExactTaxDecimal::cents(0.1);
    }
}

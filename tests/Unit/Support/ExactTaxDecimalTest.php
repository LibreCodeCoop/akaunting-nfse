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

    public function testRefusesBinaryFloatAmounts(): void
    {
        $this->expectException(\DomainException::class);
        ExactTaxDecimal::cents(0.1);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\SubstitutionDpsNumber;
use PHPUnit\Framework\TestCase;

final class SubstitutionDpsNumberTest extends TestCase
{
    public function testSameOriginalReceiptAlwaysProducesSameDpsNumber(): void
    {
        $number = new SubstitutionDpsNumber();

        self::assertSame('700000000000042', $number->forOriginalReceipt(42));
        self::assertSame(
            $number->forOriginalReceipt(42),
            $number->forOriginalReceipt(42),
        );
    }

    public function testDifferentOriginalReceiptsProduceDifferentDpsNumbers(): void
    {
        $number = new SubstitutionDpsNumber();

        self::assertNotSame(
            $number->forOriginalReceipt(42),
            $number->forOriginalReceipt(43),
        );
    }

    public function testRejectsInvalidReceiptId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SubstitutionDpsNumber())->forOriginalReceipt(0);
    }
}

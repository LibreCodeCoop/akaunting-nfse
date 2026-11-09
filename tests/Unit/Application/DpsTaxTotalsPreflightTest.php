<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\DpsTaxTotalsPreflight;
use Modules\Nfse\Application\FiscalIssuanceBlocked;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DpsTaxTotalsPreflightTest extends TestCase
{
    #[DataProvider('restrictedRegimes')]
    public function testUnknownTotalsBlockBeforeFiscalReservation(int $regime, string $code): void
    {
        $this->expectException(FiscalIssuanceBlocked::class);
        $this->expectExceptionMessage($code);

        (new DpsTaxTotalsPreflight())->assertForNewDps($regime, '', '', '');
    }

    /** @return iterable<string, array{int, string}> */
    public static function restrictedRegimes(): iterable
    {
        yield 'non-Simples' => [1, 'E0713'];
        yield 'ME/EPP' => [3, 'E0712'];
    }

    public function testPartialTotalsCannotBeFilledWithInventedZeros(): void
    {
        $this->expectException(FiscalIssuanceBlocked::class);
        $this->expectExceptionMessage('APPROXIMATE-TAX-SOURCE-REQUIRED');

        (new DpsTaxTotalsPreflight())->assertForNewDps(1, '9.80', '', '2.00');
    }

    public function testThreeSourcedPercentagesPermitNewDpsWithoutMutatingThem(): void
    {
        (new DpsTaxTotalsPreflight())->assertForNewDps(1, '12.34', '0.00', '2.00');
        self::assertTrue(true);
    }

    public function testMeiMayUseOfficialIndicatorWhenEstimateIsUnknown(): void
    {
        (new DpsTaxTotalsPreflight())->assertForNewDps(2, '', '', '');
        self::assertTrue(true);
    }
}

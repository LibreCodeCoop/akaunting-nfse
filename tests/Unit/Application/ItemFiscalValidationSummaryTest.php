<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\ItemFiscalValidationSummary;
use PHPUnit\Framework\TestCase;

final class ItemFiscalValidationSummaryTest extends TestCase
{
    public function testMunicipalWarningMakesValidNationalProfileWarning(): void
    {
        $result = (new ItemFiscalValidationSummary())->combine(
            ['status' => 'valid', 'issues' => []],
            ['status' => 'warning', 'issues' => ['municipal_rate_mismatch'], 'official_rate' => '5.00'],
        );

        self::assertSame('warning', $result['status']);
        self::assertSame('warning', $result['municipal_status']);
    }

    public function testMunicipalUnverifiableDoesNotDowngradeValidNationalProfile(): void
    {
        $result = (new ItemFiscalValidationSummary())->combine(
            ['status' => 'valid', 'issues' => []],
            ['status' => 'unverifiable', 'issues' => ['municipal_parameters_unavailable']],
        );

        self::assertSame('valid', $result['status']);
        self::assertSame('unverifiable', $result['municipal_status']);
    }

    public function testNationalInvalidityAlwaysWins(): void
    {
        $result = (new ItemFiscalValidationSummary())->combine(
            ['status' => 'invalid', 'issues' => ['invalid_national_code']],
            ['status' => 'valid', 'issues' => []],
        );

        self::assertSame('invalid', $result['status']);
    }
}

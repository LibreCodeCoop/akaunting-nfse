<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\ItemFiscalValidationSummary;
use PHPUnit\Framework\TestCase;

final class ItemFiscalValidationSummaryTest extends TestCase
{
    public function testMunicipalWarningDoesNotBecomeObjectiveInvalidation(): void
    {
        $result = (new ItemFiscalValidationSummary())->combine(
            [
                'status' => 'valid',
                'issues' => [],
                'correlation_status' => 'unverifiable',
                'source_versions' => ['service_nbs' => 'official'],
            ],
            [
                'status' => 'warning',
                'issues' => ['municipal_rate_mismatch'],
                'official_rate' => '5.00',
                'source' => ['fetched_at' => '2026-10-05'],
            ],
        );

        self::assertSame('warning', $result['status']);
        self::assertSame('warning', $result['municipal_status']);
        self::assertSame(['municipal_rate_mismatch'], $result['municipal_issues']);
    }

    public function testNationalInvalidityAlwaysWins(): void
    {
        $result = (new ItemFiscalValidationSummary())->combine(
            [
                'status' => 'invalid',
                'issues' => ['invalid_national_code'],
                'correlation_status' => 'unverifiable',
                'source_versions' => [],
            ],
            [
                'status' => 'valid',
                'issues' => [],
                'official_rate' => '2.00',
                'source' => [],
            ],
        );

        self::assertSame('invalid', $result['status']);
    }
}

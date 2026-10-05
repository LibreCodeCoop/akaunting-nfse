<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\ItemMunicipalParameterValidator;
use PHPUnit\Framework\TestCase;

final class ItemMunicipalParameterValidatorTest extends TestCase
{
    public function testMatchingUniqueOfficialRateIsValid(): void
    {
        $result = (new ItemMunicipalParameterValidator())->validate(
            '5.00',
            ['aliquota' => ['aliquotas' => [['Aliq' => 5.0]]]],
            [
                'source' => 'live',
                'stale' => false,
                'fetched_at' => '2026-10-05T10:00:00-03:00',
                'environment' => 'production',
            ],
        );

        self::assertSame('valid', $result['status']);
        self::assertSame('5.00', $result['official_rate']);
        self::assertSame([], $result['issues']);
    }

    public function testRateMismatchIsAdvisoryWarning(): void
    {
        $result = (new ItemMunicipalParameterValidator())->validate(
            '2.00',
            ['aliquota' => ['aliquotas' => [['Aliq' => 5.0]]]],
        );

        self::assertSame('warning', $result['status']);
        self::assertSame(['municipal_rate_mismatch'], $result['issues']);
        self::assertSame('5.00', $result['official_rate']);
    }

    public function testMultipleOfficialRatesRemainUnverifiable(): void
    {
        $result = (new ItemMunicipalParameterValidator())->validate(
            '5.00',
            ['aliquota' => ['aliquotas' => [['Aliq' => 5.0], ['Aliq' => 3.0]]]],
        );

        self::assertSame('unverifiable', $result['status']);
        self::assertSame(['municipal_rate_ambiguous'], $result['issues']);
    }

    public function testMissingSnapshotRemainsUnverifiable(): void
    {
        $result = (new ItemMunicipalParameterValidator())->validate('5.00', null);

        self::assertSame('unverifiable', $result['status']);
        self::assertSame(['municipal_parameters_unavailable'], $result['issues']);
    }

    public function testStaleMatchingSnapshotIsWarningWithProvenance(): void
    {
        $result = (new ItemMunicipalParameterValidator())->validate(
            '5',
            ['aliquota' => ['aliquotas' => [['Aliq' => '5.00']]]],
            [
                'source' => 'cache',
                'stale' => true,
                'fetched_at' => '2026-10-01T10:00:00-03:00',
                'environment' => 'sandbox',
            ],
        );

        self::assertSame('warning', $result['status']);
        self::assertSame(['municipal_snapshot_stale'], $result['issues']);
        self::assertSame('2026-10-01T10:00:00-03:00', $result['source']['fetched_at']);
        self::assertTrue($result['source']['stale']);
    }
}

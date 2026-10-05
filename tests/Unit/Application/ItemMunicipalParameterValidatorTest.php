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
        );

        self::assertSame('valid', $result['status']);
        self::assertSame('5.00', $result['official_rate']);
    }

    public function testMismatchIsAdvisoryWarning(): void
    {
        $result = (new ItemMunicipalParameterValidator())->validate(
            '2.00',
            ['aliquota' => ['aliquotas' => [['Aliq' => 5.0]]]],
        );

        self::assertSame('warning', $result['status']);
        self::assertSame(['municipal_rate_mismatch'], $result['issues']);
    }

    public function testMissingConfiguredRateIsUnverifiable(): void
    {
        $result = (new ItemMunicipalParameterValidator())->validate(
            null,
            ['aliquota' => ['aliquotas' => [['Aliq' => 5.0]]]],
        );

        self::assertSame('unverifiable', $result['status']);
        self::assertSame(['item_tax_rate_unverifiable'], $result['issues']);
    }

    public function testMultipleOfficialRatesAreUnverifiable(): void
    {
        $result = (new ItemMunicipalParameterValidator())->validate(
            '5.00',
            ['aliquota' => ['aliquotas' => [['Aliq' => 5.0], ['Aliq' => 3.0]]]],
        );

        self::assertSame('unverifiable', $result['status']);
        self::assertSame(['municipal_rate_ambiguous'], $result['issues']);
    }

    public function testStaleMatchingSnapshotIsWarning(): void
    {
        $result = (new ItemMunicipalParameterValidator())->validate(
            '5.00',
            ['aliquota' => ['aliquotas' => [['Aliq' => 5.0]]]],
            ['source' => 'cache', 'stale' => true, 'fetched_at' => '2026-10-01T10:00:00-03:00'],
        );

        self::assertSame('warning', $result['status']);
        self::assertSame(['municipal_snapshot_stale'], $result['issues']);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\RtcSupplyCategoryPolicy;
use PHPUnit\Framework\TestCase;

final class RtcSupplyCategoryPolicyTest extends TestCase
{
    /**
     * @dataProvider categoryCases
     */
    public function testExplicitNonLc116CategoryBeginsOnDecemberFirst2026(string $category): void
    {
        $policy = new RtcSupplyCategoryPolicy();
        $before = $policy->evaluate($category, '2026-11-30', 1);
        $after = $policy->evaluate($category, '2026-12-01', 1);

        self::assertFalse($before['required']);
        self::assertTrue($after['required']);
        self::assertSame('2026-12-01', $after['effective_date']);
        self::assertSame($category, $after['reason']);
    }

    /**
     * @return array<string,array{string}>
     */
    public static function categoryCases(): array
    {
        return [
            'platform' => [RtcSupplyCategoryPolicy::DIGITAL_PLATFORM],
            'non ISS intangible' => [RtcSupplyCategoryPolicy::NON_ISS_INTANGIBLE],
            'condominium' => [RtcSupplyCategoryPolicy::CONDOMINIUM_REVENUE],
            'lease' => [RtcSupplyCategoryPolicy::LEASE],
            'residual service' => [RtcSupplyCategoryPolicy::RESIDUAL_SERVICE],
        ];
    }

    public function testSimplesTransitionIsKeptDistinct(): void
    {
        $policy = new RtcSupplyCategoryPolicy();
        self::assertFalse($policy->evaluate(RtcSupplyCategoryPolicy::LEASE, '2026-12-31', 3)['required']);
        $result = $policy->evaluate(RtcSupplyCategoryPolicy::LEASE, '2027-01-01', 3);
        self::assertTrue($result['required']);
        self::assertSame('simples_nacional', $result['reason']);
    }

    public function testUnsupportedOrMissingCategoryIsUnverifiable(): void
    {
        $policy = new RtcSupplyCategoryPolicy();
        foreach (['', 'ordinary_lc116', 'unknown'] as $category) {
            $result = $policy->evaluate($category, '2026-12-01', 1);
            self::assertSame('unverifiable', $result['reason']);
            self::assertNull($result['effective_date']);
        }
        self::assertSame('unverifiable', $policy->evaluate(RtcSupplyCategoryPolicy::LEASE, '2026-02-31', 1)['reason']);
    }
}

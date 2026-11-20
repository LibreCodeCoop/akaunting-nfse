<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\RtcSupplyCategoryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RtcSupplyCategoryPolicyTest extends TestCase
{
    #[DataProvider('categoryCases')]
    public function testExplicitNonLc116CategoryUsesRevised2026Calendar(string $category, string $cutover): void
    {
        $policy = new RtcSupplyCategoryPolicy();
        $previous = (new \DateTimeImmutable($cutover))->modify('-1 day')->format('Y-m-d');
        $before = $policy->evaluate($category, $previous, 1);
        $after = $policy->evaluate($category, $cutover, 1);

        self::assertFalse($before['required']);
        self::assertTrue($after['required']);
        self::assertSame($cutover, $after['effective_date']);
        self::assertSame($category, $after['reason']);
    }

    /** @return array<string,array{string,string}> */
    public static function categoryCases(): array
    {
        return [
            'platform' => [RtcSupplyCategoryPolicy::DIGITAL_PLATFORM, '2027-03-01'],
            'non ISS intangible' => [RtcSupplyCategoryPolicy::NON_ISS_INTANGIBLE, '2027-01-01'],
            'condominium' => [RtcSupplyCategoryPolicy::CONDOMINIUM_REVENUE, '2027-01-01'],
            'lease' => [RtcSupplyCategoryPolicy::LEASE, '2027-01-01'],
            'residual service' => [RtcSupplyCategoryPolicy::RESIDUAL_SERVICE, '2027-01-01'],
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

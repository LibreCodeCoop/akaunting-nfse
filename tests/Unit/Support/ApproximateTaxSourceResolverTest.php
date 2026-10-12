<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\ApproximateTaxSourceResolver;
use PHPUnit\Framework\TestCase;

final class ApproximateTaxSourceResolverTest extends TestCase
{
    public function testNoSourceRetainsMissingAsUnknownRatherThanZero(): void
    {
        $resolver = new ApproximateTaxSourceResolver(static fn (): ?array => null);
        $result = $resolver->resolve(5, '010701', 1, '2026-10-01');

        self::assertSame('unknown', $result['status']);
        self::assertSame('source_not_configured', $result['reason']);
        self::assertSame('', $result['federal']);
        self::assertSame('', $result['municipal']);
        self::assertNull($result['source']);
    }

    public function testDocumentedZeroIsDistinctFromUnknown(): void
    {
        $resolver = new ApproximateTaxSourceResolver(static fn (): ?array => self::source([
            'source_kind' => 'documented_zero',
            'federal_percent' => '0.00',
            'state_percent' => '0.00',
            'municipal_percent' => '0.00',
        ]));
        $result = $resolver->resolve(5, '010701', 1, '2026-10-01');

        self::assertSame('confirmed_zero', $result['status']);
        self::assertSame('0.00', $result['municipal']);
        self::assertSame('synth-2026.2', $result['source']['version']);
    }

    public function testSourcedPercentagesDoNotDependOnIssRate(): void
    {
        $resolver = new ApproximateTaxSourceResolver(static fn (): ?array => self::source([
            'federal_percent' => '7.35',
            'state_percent' => '0.00',
            'municipal_percent' => '4.10',
        ]));
        $result = $resolver->resolve(5, '010701', 1, '2026-10-01');

        self::assertSame('sourced_estimate', $result['status']);
        self::assertSame('7.35', $result['federal']);
        self::assertSame('0.00', $result['state']);
        self::assertSame('4.10', $result['municipal']);
    }

    public function testWrongCompanyIsNotSilentlyReused(): void
    {
        $resolver = new ApproximateTaxSourceResolver(static fn (): ?array => self::source(['company_id' => 6]));

        $this->expectException(\LogicException::class);
        $resolver->resolve(5, '010701', 1, '2026-10-01');
    }

    public function testSourceOutsideCompetenceCannotBeReapplied(): void
    {
        $resolver = new ApproximateTaxSourceResolver(static fn (): ?array => self::source([
            'competence_from' => '2026-11-01',
        ]));

        $this->expectException(\LogicException::class);
        $resolver->resolve(5, '010701', 1, '2026-10-01');
    }

    public function testDocumentedZeroRefusesUnprovenNonzero(): void
    {
        $resolver = new ApproximateTaxSourceResolver(static fn (): ?array => self::source([
            'source_kind' => 'documented_zero',
            'municipal_percent' => '2.00',
        ]));

        $this->expectException(\LogicException::class);
        $resolver->resolve(5, '010701', 1, '2026-10-01');
    }

    /** @param array<string,mixed> $overrides */
    private static function source(array $overrides): array
    {
        return array_replace([
            'id' => 8,
            'company_id' => 5,
            'national_service_code' => '010701',
            'simples_option' => 1,
            'competence_from' => '2026-09-01',
            'competence_until' => '2026-12-31',
            'source_kind' => 'independent_estimate',
            'source_name' => 'Synthetic reference',
            'source_version' => 'synth-2026.2',
            'federal_percent' => '7.35',
            'state_percent' => '0.00',
            'municipal_percent' => '4.10',
        ], $overrides);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\FederalTaxReadiness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FederalTaxReadinessTest extends TestCase
{
    #[DataProvider('requiredBucketsProvider')]
    public function testRequiredBuckets(
        mixed $enforce,
        string $situation,
        string $retention,
        array $expected,
    ): void {
        self::assertSame(
            $expected,
            (new FederalTaxReadiness())->requiredBuckets($enforce, $situation, $retention),
        );
    }

    public static function requiredBucketsProvider(): array
    {
        return [
            'disabled boolean' => [false, '1', '3', []],
            'disabled string' => ['off', '1', '3', []],
            'no configured federal taxes' => [true, '', '', []],
            'pis cofins only' => [true, '1', '0', ['pis', 'cofins']],
            'payer withholding code four require individual retained tax values' => [true, '1', '4', ['retained_pis', 'retained_cofins']],
            'payer withholding code one require individual retained tax values' => [true, '1', '1', ['retained_pis', 'retained_cofins']],
            'csll retention only' => [true, '0', '3', ['csll']],
            'pis cofins and csll' => [true, '1', '7', ['pis', 'cofins', 'csll']],
        ];
    }

    public function testEvaluateReportsOnlyRequiredMissingBuckets(): void
    {
        $result = (new FederalTaxReadiness())->evaluate([
            'pis_value' => '1.00',
            'cofins_value' => '',
            'csll_value' => '',
        ], ['pis', 'cofins']);

        self::assertFalse($result['isReady']);
        self::assertSame(['cofins'], $result['missing']);
    }

    public function testRetainedPisCofinsRequireBothConfirmedAmounts(): void
    {
        $policy = new FederalTaxReadiness();
        $required = $policy->requiredBuckets(true, '1', '4');

        self::assertSame(['retained_pis', 'retained_cofins'], $required);
        self::assertSame(
            ['isReady' => true, 'missing' => []],
            $policy->evaluate([
                'pis_value' => '',
                'cofins_value' => '',
                'retained_pis_value' => '2.50',
                'retained_cofins_value' => '9.00',
            ], $required),
        );
        self::assertSame(
            ['isReady' => false, 'missing' => ['retained_pis']],
            $policy->evaluate([
                'pis_value' => '',
                'cofins_value' => '',
                'retained_pis_value' => '',
                'retained_cofins_value' => '9.00',
            ], $required),
        );
    }

    public function testNoRequiredBucketsIsReady(): void
    {
        self::assertSame(
            ['isReady' => true, 'missing' => []],
            (new FederalTaxReadiness())->evaluate([], []),
        );
    }
}

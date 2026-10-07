<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\FederalTaxSnapshotBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FederalTaxSnapshotBuilderTest extends TestCase
{
    #[DataProvider('bucketProvider')]
    public function testClassifiesFederalTaxNames(string $name, ?string $expected): void
    {
        self::assertSame($expected, (new FederalTaxSnapshotBuilder())->bucketFromName($name));
    }

    public static function bucketProvider(): array
    {
        return [
            'pis' => ['PIS', 'pis'],
            'pasep' => ['Contribuicao PASEP sobre servicos', 'pis'],
            'cofins' => ['Contribuicao para o Financiamento da Seguridade Social', 'cofins'],
            'irrf' => ['Imposto de Renda Retido na Fonte', 'irrf'],
            'csll accented' => ['Contribuição social sobre o lucro líquido', 'csll'],
            'cp' => ['Contribuição previdenciária patronal', 'cp'],
            'code hint' => ['cst:cofins', 'cofins'],
            'bracket hint' => ['Retencao [csll]', 'csll'],
            'municipal tax' => ['ISSQN municipal', null],
            'empty' => ['', null],
        ];
    }

    public function testBuildsOnlyFromSelectedDocumentItems(): void
    {
        $items = [
            [
                'id' => 101,
                'item_taxes' => [
                    ['name' => 'PIS', 'amount' => 10.00, 'rate' => 10.00],
                    ['name' => 'COFINS', 'amount' => 20.00, 'rate' => 20.00],
                ],
            ],
            [
                'id' => 102,
                'item_taxes' => [
                    ['name' => 'PIS', 'amount' => 30.00, 'rate' => 15.00],
                ],
            ],
        ];

        $snapshot = (new FederalTaxSnapshotBuilder())->build(
            items: $items,
            baseAmount: 100.00,
            documentItemIds: [101],
            taxRateResolver: static fn (mixed $tax): ?float => is_array($tax) && is_numeric($tax['rate'] ?? null)
                ? (float) $tax['rate']
                : null,
        );

        self::assertSame('10.00', $snapshot['pis_value']);
        self::assertSame('20.00', $snapshot['cofins_value']);
        self::assertSame('10.00', $snapshot['pis_rate']);
        self::assertSame('20.00', $snapshot['cofins_rate']);
        self::assertSame('30.00', $snapshot['federal_percent']);
    }

    public function testDeduplicatesSameTaxAcrossAkauntingRepresentations(): void
    {
        $tax = ['tax_id' => 7, 'name' => 'PIS', 'amount' => 5.00, 'rate' => 5.00];

        $snapshot = (new FederalTaxSnapshotBuilder())->build(
            items: [['id' => 1, 'taxes' => [$tax], 'item_taxes' => [$tax]]],
            baseAmount: 100.00,
            documentItemIds: null,
            taxRateResolver: static fn (): ?float => 5.00,
        );

        self::assertSame('5.00', $snapshot['pis_value']);
        self::assertSame('5.00', $snapshot['pis_rate']);
    }
    public function testApproximateFederalPercentExcludesWithholdingTaxes(): void
    {
        $snapshot = (new FederalTaxSnapshotBuilder())->build(
            items: [
                [
                    'id' => 1,
                    'item_taxes' => [
                        ['name' => 'PIS', 'amount' => 204.75, 'rate' => 0.65],
                        ['name' => 'COFINS', 'amount' => 945.00, 'rate' => 3.00],
                        ['name' => 'IRRF', 'amount' => 472.50, 'rate' => 1.50],
                    ],
                ],
            ],
            baseAmount: 31500.00,
            documentItemIds: null,
            taxRateResolver: static fn (mixed $tax): ?float => is_array($tax) && is_numeric($tax['rate'] ?? null)
                ? (float) $tax['rate']
                : null,
        );

        self::assertSame('3.65', $snapshot['federal_percent']);
    }

}

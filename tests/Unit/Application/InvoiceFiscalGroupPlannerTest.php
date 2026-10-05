<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\InvoiceFiscalGroupPlanner;
use PHPUnit\Framework\TestCase;

final class InvoiceFiscalGroupPlannerTest extends TestCase
{
    public function testKeepsCompatibleItemsInOneGroupAndReconcilesAmount(): void
    {
        $groups = (new InvoiceFiscalGroupPlanner())->plan(
            items: [
                ['item_id' => 10, 'name' => 'Consultoria', 'total' => '100.10'],
                ['item_id' => 11, 'name' => 'Suporte', 'total' => '49.90'],
            ],
            profileMap: [
                10 => ['item_lista_servico' => '01.07', 'codigo_tributacao_nacional' => '010701'],
                11 => ['item_lista_servico' => '0107', 'codigo_tributacao_nacional' => '010701'],
            ],
            taxRateMap: [10 => '2.00', 11 => '2.00'],
            defaultServiceCode: '',
            defaultNationalCode: '',
            defaultRate: '',
            unnamedItemLabel: 'N/A',
        );

        self::assertCount(1, $groups);
        self::assertSame('150.00', $groups[0]['amount']);
        self::assertSame([10, 11], $groups[0]['item_ids']);
        self::assertSame(['[0107] Consultoria', '[0107] Suporte'], $groups[0]['line_items']);
    }

    public function testSeparatesItemsByTheSameFiscalSignatureUsedForSplitDetection(): void
    {
        $groups = (new InvoiceFiscalGroupPlanner())->plan(
            items: [
                ['item_id' => 10, 'name' => 'Consultoria', 'total' => '100.00'],
                ['item_id' => 20, 'name' => 'Hospedagem', 'total' => '80.00'],
                ['item_id' => 30, 'name' => 'Treinamento', 'total' => '20.00'],
            ],
            profileMap: [
                10 => ['item_lista_servico' => '0107', 'codigo_tributacao_nacional' => '010701'],
                20 => ['item_lista_servico' => '0103', 'codigo_tributacao_nacional' => '010301'],
                30 => ['item_lista_servico' => '0107', 'codigo_tributacao_nacional' => '010701'],
            ],
            taxRateMap: [10 => '2.00', 20 => '5.00', 30 => '2.00'],
            defaultServiceCode: '',
            defaultNationalCode: '',
            defaultRate: '',
            unnamedItemLabel: 'N/A',
        );

        self::assertCount(2, $groups);
        self::assertSame('120.00', $groups[0]['amount']);
        self::assertSame([10, 30], $groups[0]['item_ids']);
        self::assertSame('80.00', $groups[1]['amount']);
        self::assertSame([20], $groups[1]['item_ids']);
        self::assertNotSame($groups[0]['key'], $groups[1]['key']);
    }

    public function testUsesDefaultsWithoutMutatingOrRedistributingAmounts(): void
    {
        $groups = (new InvoiceFiscalGroupPlanner())->plan(
            items: [
                ['item_id' => 0, 'name' => '', 'total' => '10.01'],
                ['item_id' => 0, 'name' => 'Outro', 'total' => '10.02'],
            ],
            profileMap: [],
            taxRateMap: [],
            defaultServiceCode: '1.07',
            defaultNationalCode: '010701',
            defaultRate: '2.00',
            unnamedItemLabel: 'Sem nome',
        );

        self::assertCount(1, $groups);
        self::assertSame('0107', $groups[0]['item_lista_servico']);
        self::assertSame('010701', $groups[0]['codigo_tributacao_nacional']);
        self::assertSame('2.00', $groups[0]['aliquota']);
        self::assertSame('20.03', $groups[0]['amount']);
        self::assertSame([], $groups[0]['item_ids']);
    }

    public function testGroupKeyIsStableForEquivalentFiscalSignature(): void
    {
        $planner = new InvoiceFiscalGroupPlanner();
        $args = [
            'items' => [['item_id' => 1, 'name' => 'A', 'total' => '1.00']],
            'profileMap' => [1 => ['item_lista_servico' => '0107', 'codigo_tributacao_nacional' => '010701']],
            'taxRateMap' => [1 => '2.00'],
            'defaultServiceCode' => '',
            'defaultNationalCode' => '',
            'defaultRate' => '',
            'unnamedItemLabel' => 'N/A',
        ];

        $first = $planner->plan(...$args);
        $second = $planner->plan(...$args);

        self::assertSame($first[0]['key'], $second[0]['key']);
    }
}

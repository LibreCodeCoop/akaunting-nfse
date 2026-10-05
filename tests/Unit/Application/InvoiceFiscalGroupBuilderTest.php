<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\InvoiceFiscalGroupBuilder;
use PHPUnit\Framework\TestCase;

final class InvoiceFiscalGroupBuilderTest extends TestCase
{
    public function testOneFiscalSignatureKeepsAllItemsInOneGroup(): void
    {
        $groups = (new InvoiceFiscalGroupBuilder())->build(
            items: [
                ['id' => 10, 'item_id' => 1, 'name' => 'A', 'total' => '10.01'],
                ['id' => 11, 'item_id' => 2, 'name' => 'B', 'total' => '20.02'],
            ],
            profileMap: [
                1 => ['item_lista_servico' => '1.07', 'codigo_tributacao_nacional' => '010701'],
                2 => ['item_lista_servico' => '0107', 'codigo_tributacao_nacional' => '010701'],
            ],
            taxRateMap: [1 => '2.00', 2 => '2.00'],
            defaultServiceCode: '',
            defaultNationalCode: '',
            defaultRate: '',
        );

        self::assertCount(1, $groups);
        self::assertSame('service:0107|tax:010701|rate:2.00', $groups[0]['key']);
        self::assertSame('30.03', $groups[0]['amount']);
        self::assertCount(2, $groups[0]['items']);
    }

    public function testDifferentFiscalSignaturesProduceIndependentGroupsWithoutRedistribution(): void
    {
        $groups = (new InvoiceFiscalGroupBuilder())->build(
            items: [
                ['id' => 20, 'item_id' => 1, 'name' => 'Consultoria', 'total' => '33.33'],
                ['id' => 21, 'item_id' => 2, 'name' => 'Suporte', 'total' => '66.67'],
            ],
            profileMap: [
                1 => ['item_lista_servico' => '0107', 'codigo_tributacao_nacional' => '010701'],
                2 => ['item_lista_servico' => '0101', 'codigo_tributacao_nacional' => '010101'],
            ],
            taxRateMap: [1 => '2.00', 2 => '5.00'],
            defaultServiceCode: '',
            defaultNationalCode: '',
            defaultRate: '',
        );

        self::assertCount(2, $groups);
        self::assertSame('33.33', $groups[0]['amount']);
        self::assertSame('66.67', $groups[1]['amount']);
        self::assertSame(20, $groups[0]['items'][0]['document_item_id']);
        self::assertSame(21, $groups[1]['items'][0]['document_item_id']);
    }

    public function testMissingItemProfileUsesExplicitDefaults(): void
    {
        $groups = (new InvoiceFiscalGroupBuilder())->build(
            items: [
                ['id' => 30, 'item_id' => 9, 'name' => 'Default', 'total' => '100.00'],
            ],
            profileMap: [],
            taxRateMap: [],
            defaultServiceCode: '1.07',
            defaultNationalCode: '010701',
            defaultRate: '2.00',
        );

        self::assertSame('service:0107|tax:010701|rate:2.00', $groups[0]['key']);
        self::assertSame('100.00', $groups[0]['amount']);
    }

    public function testRejectsLineTotalsWithMoreThanTwoDecimalPlaces(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new InvoiceFiscalGroupBuilder())->build(
            items: [['id' => 1, 'item_id' => 1, 'name' => 'A', 'total' => '10.001']],
            profileMap: [],
            taxRateMap: [],
            defaultServiceCode: '0107',
            defaultNationalCode: '010701',
            defaultRate: '2.00',
        );
    }
}

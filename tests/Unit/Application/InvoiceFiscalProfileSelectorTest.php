<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\InvoiceFiscalProfileSelector;
use PHPUnit\Framework\TestCase;

final class InvoiceFiscalProfileSelectorTest extends TestCase
{
    private InvoiceFiscalProfileSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->selector = new InvoiceFiscalProfileSelector();
    }

    public function testSelectsLoadedItemProfileAndTaxRate(): void
    {
        $result = $this->selector->select(
            items: [['item_id' => 10, 'name' => 'Consultoria']],
            profileMap: [10 => [
                'item_lista_servico' => '01.07',
                'codigo_tributacao_nacional' => '010701',
            ]],
            taxRateMap: [10 => '3.50'],
            defaultServiceCode: '01.01',
            defaultNationalCode: '010101',
            defaultRate: '5.00',
            unnamedItemLabel: 'N/A',
        );

        self::assertSame('0107', $result['item_lista_servico']);
        self::assertSame('010701', $result['codigo_tributacao_nacional']);
        self::assertSame('3.50', $result['aliquota']);
        self::assertSame(['[0107] Consultoria'], $result['line_items']);
        self::assertFalse($result['requires_split']);
    }

    public function testFallsBackToConfiguredDefaultsWithoutFrameworkState(): void
    {
        $result = $this->selector->select(
            items: [['item_id' => 10, 'name' => '']],
            profileMap: [],
            taxRateMap: [],
            defaultServiceCode: '01.07',
            defaultNationalCode: '010701',
            defaultRate: '5.00',
            unnamedItemLabel: 'N/A',
        );

        self::assertSame('0107', $result['item_lista_servico']);
        self::assertSame('010701', $result['codigo_tributacao_nacional']);
        self::assertSame('5.00', $result['aliquota']);
        self::assertSame(['[0107] N/A'], $result['line_items']);
        self::assertFalse($result['requires_split']);
    }

    public function testMarksInvoicesWithDifferentFiscalSignaturesForSplit(): void
    {
        $result = $this->selector->select(
            items: [
                ['item_id' => 10, 'name' => 'Servico A'],
                ['item_id' => 20, 'name' => 'Servico B'],
            ],
            profileMap: [
                10 => ['item_lista_servico' => '01.07', 'codigo_tributacao_nacional' => '010701'],
                20 => ['item_lista_servico' => '01.01', 'codigo_tributacao_nacional' => '010101'],
            ],
            taxRateMap: [10 => '5.00', 20 => '2.00'],
            defaultServiceCode: '',
            defaultNationalCode: '',
            defaultRate: '5.00',
            unnamedItemLabel: 'N/A',
        );

        self::assertTrue($result['requires_split']);
        self::assertSame('0107', $result['item_lista_servico']);
        self::assertSame('010701', $result['codigo_tributacao_nacional']);
    }

    public function testDoesNotDependOnDatabaseHttpOrTranslationHelpers(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../Application/InvoiceFiscalProfileSelector.php');

        self::assertIsString($source);
        self::assertStringNotContainsString('request(', $source);
        self::assertStringNotContainsString('setting(', $source);
        self::assertStringNotContainsString('trans(', $source);
        self::assertStringNotContainsString('::query(', $source);
        self::assertStringNotContainsString('catch (\\Throwable', $source);
    }
}

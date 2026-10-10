<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\FiscalDescriptionComposer;
use PHPUnit\Framework\TestCase;

final class FiscalDescriptionComposerTest extends TestCase
{
    private FiscalDescriptionComposer $composer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->composer = new FiscalDescriptionComposer();
    }

    public function testGroupUsesNamesAndIgnoresItemDescriptions(): void
    {
        self::assertSame(
            "Item A | Item B | Item C\n\nObservação geral",
            $this->composer->group([
                ['name' => 'Item A', 'description' => 'Atividade contratada\nCentro de custo: TESTE-001'],
                ['name' => 'Item B', 'description' => ''],
                ['name' => 'Item C', 'description' => 'Segunda atividade'],
            ], 'Observação geral'),
        );
    }

    public function testGroupFallsBackToItemNameAndIgnoresEmptyItems(): void
    {
        self::assertSame('Serviço A | Serviço B', $this->composer->group([
            ['name' => 'Serviço A', 'description' => ' '],
            ['name' => '', 'description' => ''],
            ['name' => 'Serviço B'],
        ]));
    }

    public function testGroupCustomOverrideWinsAndBlankOverrideFallsBack(): void
    {
        $items = [['name' => 'Serviço A']];

        self::assertSame('Texto personalizado', $this->composer->group($items, 'Observação', ' Texto personalizado '));
        self::assertSame("Serviço A\n\nObservação", $this->composer->group($items, 'Observação', '  '));
    }

    public function testGroupDeduplicatesIdenticalServiceAndAdditionalText(): void
    {
        self::assertSame('Serviço A', $this->composer->group([['name' => 'Serviço A']], 'Serviço A'));
    }

    public function testAdditionalNotesAndDefaultAreDeduplicatedInOrder(): void
    {
        self::assertSame(
            "Nota geral\n\nDados de pagamento",
            $this->composer->additional('Nota geral', 'Dados de pagamento'),
        );
        self::assertSame('Dados de pagamento', $this->composer->additional(' Dados de pagamento ', 'Dados de pagamento'));
        self::assertSame('', $this->composer->additional(null, '  '));
    }

    public function testInvoicePrefersInvoiceDescriptionWhenNotesExist(): void
    {
        self::assertSame(
            "Descrição da fatura\n\nObservação geral\n\nDados de pagamento",
            $this->composer->invoice(
                invoiceDescription: 'Descrição da fatura',
                lineItems: ['[0107] Descrição do item'],
                fallbackNames: ['Nome do item'],
                notes: 'Observação geral',
                defaultDescription: 'Dados de pagamento',
                customDescription: null,
                fallbackLabel: 'Serviço',
            ),
        );
    }

    public function testInvoiceUsesItemLinesWhenDescriptionMissingAndDeduplicatesNotes(): void
    {
        self::assertSame(
            "[0107] Serviço informado\n\nObservação geral",
            $this->composer->invoice(
                invoiceDescription: null,
                lineItems: ['[0107] Serviço informado'],
                fallbackNames: ['Nome comercial'],
                notes: 'Observação geral',
                defaultDescription: 'Observação geral',
                customDescription: null,
                fallbackLabel: 'Serviço',
            ),
        );
    }

    public function testInvoiceKeepsOriginalNoNotesPrecedenceAndFallback(): void
    {
        self::assertSame('Linha fiscal', $this->composer->invoice(null, ['Linha fiscal'], ['Nome comercial'], null, null, null, 'Serviço'));
        self::assertSame('Nome comercial', $this->composer->invoice('Descrição da fatura', [], ['Nome comercial'], null, null, null, 'Serviço'));
        self::assertSame('Descrição da fatura', $this->composer->invoice('Descrição da fatura', [], [], null, null, null, 'Serviço'));
        self::assertSame('Serviço', $this->composer->invoice(null, [], [], null, null, null, 'Serviço'));
    }

    public function testInvoiceReturnsExplicitOverrideWithoutAppendingOtherText(): void
    {
        self::assertSame(
            'Personalizado',
            $this->composer->invoice(
                'Descrição da fatura',
                ['Linha fiscal'],
                ['Nome comercial'],
                'Observação',
                'Dados de pagamento',
                'Personalizado',
                'Serviço',
            ),
        );
    }

    public function testLiteralAndRealLineBreaksAreNormalized(): void
    {
        self::assertSame(
            "Observação\nOutra linha",
            $this->composer->additional('Observação\nOutra linha', null),
        );
        self::assertSame(
            "Observação\nOutra linha",
            $this->composer->additional("Observação\r\nOutra linha", null),
        );
    }
}

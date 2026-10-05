<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\SubstitutionDpsBuilder;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use PHPUnit\Framework\TestCase;

final class SubstitutionDpsBuilderTest extends TestCase
{
    public function testBuildsReplacementDpsWithoutMutatingBaseFiscalFields(): void
    {
        $receipt = new NfseReceipt();
        $receipt->id = 42;
        $receipt->chave_acesso = str_repeat('1', 50);

        $base = new DpsData(
            cnpjPrestador: '11222333000181',
            municipioIbge: '3303302',
            itemListaServico: '0107',
            valorServico: '100.00',
            aliquota: '2.00',
            discriminacao: 'Servico original',
            numeroDps: '123',
        );

        $replacement = (new SubstitutionDpsBuilder())->build(
            $base,
            $receipt,
            '01',
            'Correcao de dados fiscais da nota original.',
        );

        self::assertSame('700000000000042', $replacement->numeroDps);
        self::assertSame('100.00', $replacement->valorServico);
        self::assertSame(str_repeat('1', 50), $replacement->substituicao?->chaveNfseSubstituida);
        self::assertSame('01', $replacement->substituicao?->codigoMotivo);
    }

    public function testRejectsOriginalReceiptWithoutOfficialAccessKey(): void
    {
        $receipt = new NfseReceipt();
        $receipt->id = 42;
        $receipt->chave_acesso = 'TEST-ACCESS-KEY';

        $base = new DpsData(
            cnpjPrestador: '11222333000181',
            municipioIbge: '3303302',
            itemListaServico: '0107',
            valorServico: '100.00',
            aliquota: '2.00',
            discriminacao: 'Servico original',
        );

        $this->expectException(\InvalidArgumentException::class);

        (new SubstitutionDpsBuilder())->build($base, $receipt, '01');
    }
}

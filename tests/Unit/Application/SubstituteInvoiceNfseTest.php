<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\SubstituteInvoiceNfse;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\SubstitutionData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NetworkException;
use PHPUnit\Framework\TestCase;

final class SubstituteInvoiceNfseTest extends TestCase
{
    public function testIssuesReplacementThroughNormalDpsEndpoint(): void
    {
        $originalKey = str_repeat('1', 50);
        $expected = $this->receipt('200', str_repeat('2', 50));

        $client = new class ($expected) implements NfseClientInterface {
            public ?DpsData $emitted = null;

            public function __construct(private readonly ReceiptData $result)
            {
            }

            public function emit(DpsData $dps): ReceiptData
            {
                $this->emitted = $dps;

                return $this->result;
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                throw new \LogicException('query must not be used on successful issuance');
            }

            public function cancel(string $chaveAcesso, string $motivo): bool
            {
                return false;
            }

            public function getDanfse(string $nfseXml): string
            {
                return '';
            }
        };

        $dps = $this->dps($originalKey);
        $actual = (new SubstituteInvoiceNfse())->issue($client, $dps, $originalKey);

        self::assertSame($expected, $actual);
        self::assertSame($dps, $client->emitted);
    }

    public function testRecoversReplacementByDpsAfterAmbiguousNetworkFailure(): void
    {
        $originalKey = str_repeat('1', 50);
        $replacementKey = str_repeat('3', 50);
        $expected = $this->receipt('201', $replacementKey);

        $client = new class ($replacementKey, $expected) implements NfseClientInterface {
            public string $queriedDps = '';
            public string $queriedAccessKey = '';

            public function __construct(
                private readonly string $replacementKey,
                private readonly ReceiptData $result,
            ) {
            }

            public function emit(DpsData $dps): ReceiptData
            {
                throw new NetworkException('connection closed after POST');
            }

            public function queryDps(string $dpsId): string
            {
                $this->queriedDps = $dpsId;

                return $this->replacementKey;
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                $this->queriedAccessKey = $chaveAcesso;

                return $this->result;
            }

            public function cancel(string $chaveAcesso, string $motivo): bool
            {
                return false;
            }

            public function getDanfse(string $nfseXml): string
            {
                return '';
            }
        };

        $dps = $this->dps($originalKey);
        $actual = (new SubstituteInvoiceNfse())->issue($client, $dps, $originalKey);

        self::assertSame($expected, $actual);
        self::assertSame(
            '3303302' . '2' . '11222333000181' . '00001' . str_pad('77', 15, '0', STR_PAD_LEFT),
            $client->queriedDps,
        );
        self::assertSame($replacementKey, $client->queriedAccessKey);
    }

    public function testRejectsDpsWithoutOfficialSubstitutionReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SubstituteInvoiceNfse())->issue(
            $this->unusedClient(),
            $this->dps(null),
            str_repeat('1', 50),
        );
    }

    public function testRejectsReferenceToDifferentOriginalReceipt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SubstituteInvoiceNfse())->issue(
            $this->unusedClient(),
            $this->dps(str_repeat('1', 50)),
            str_repeat('9', 50),
        );
    }

    private function dps(?string $substitutedKey): DpsData
    {
        return new DpsData(
            cnpjPrestador: '11222333000181',
            municipioIbge: '3303302',
            itemListaServico: '0107',
            valorServico: '100.00',
            aliquota: '2.00',
            discriminacao: 'Substituicao de servico autorizada',
            serie: '00001',
            numeroDps: '77',
            codigoTributacaoNacional: '010701',
            substituicao: $substitutedKey === null
                ? null
                : new SubstitutionData(
                    chaveNfseSubstituida: $substitutedKey,
                    codigoMotivo: '01',
                    descricaoMotivo: 'Correcao dos dados fiscais da nota',
                ),
        );
    }

    private function receipt(string $number, string $key): ReceiptData
    {
        return new ReceiptData(
            nfseNumber: $number,
            chaveAcesso: $key,
            dataEmissao: '2026-10-05T10:00:00-03:00',
        );
    }

    private function unusedClient(): NfseClientInterface
    {
        return new class () implements NfseClientInterface {
            public function emit(DpsData $dps): ReceiptData
            {
                throw new \LogicException('emit must not be reached');
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                throw new \LogicException('query must not be reached');
            }

            public function cancel(string $chaveAcesso, string $motivo): bool
            {
                throw new \LogicException('cancel must not be reached');
            }

            public function getDanfse(string $nfseXml): string
            {
                throw new \LogicException('getDanfse must not be reached');
            }
        };
    }
}

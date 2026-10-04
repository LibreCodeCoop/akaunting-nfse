<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\RecoverInvoiceEmission;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use PHPUnit\Framework\TestCase;

final class RecoverInvoiceEmissionTest extends TestCase
{
    public function testRecoversByDpsIdentifierAndThenQueriesAuthorizedNfse(): void
    {
        $client = new class () implements NfseClientInterface {
            public string $queriedDps = '';
            public string $queriedNfse = '';

            public function queryDps(string $idDps): string
            {
                $this->queriedDps = $idDps;

                return 'ACCESS-42';
            }

            public function emit(DpsData $dps): ReceiptData
            {
                throw new \LogicException('Not used.');
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                $this->queriedNfse = $chaveAcesso;

                return new ReceiptData('42', $chaveAcesso, '2026-10-04T12:00:00-03:00');
            }

            public function cancel(string $chaveAcesso, string $motivo): bool
            {
                throw new \LogicException('Not used.');
            }

            public function getDanfse(string $nfseXml): string
            {
                throw new \LogicException('Not used.');
            }
        };

        $dps = $this->dps();
        $receipt = (new RecoverInvoiceEmission())->recover($client, $dps);

        self::assertInstanceOf(ReceiptData::class, $receipt);
        self::assertSame('330330221122233300018100001000000000000042', $client->queriedDps);
        self::assertSame('ACCESS-42', $client->queriedNfse);
    }

    public function testReturnsNullWhenClientDoesNotExposeDpsLookupCapability(): void
    {
        $client = new class () implements NfseClientInterface {
            public function emit(DpsData $dps): ReceiptData
            {
                throw new \LogicException('Not used.');
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                throw new \LogicException('Not used.');
            }

            public function cancel(string $chaveAcesso, string $motivo): bool
            {
                throw new \LogicException('Not used.');
            }

            public function getDanfse(string $nfseXml): string
            {
                throw new \LogicException('Not used.');
            }
        };

        self::assertNull((new RecoverInvoiceEmission())->recover($client, $this->dps()));
    }

    public function testProtocolFailurePropagatesToCallingAdapter(): void
    {
        $client = new class () implements NfseClientInterface {
            public function queryDps(string $idDps): string
            {
                throw new \RuntimeException('SEFIN unavailable');
            }

            public function emit(DpsData $dps): ReceiptData
            {
                throw new \LogicException('Not used.');
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                throw new \LogicException('Not used.');
            }

            public function cancel(string $chaveAcesso, string $motivo): bool
            {
                throw new \LogicException('Not used.');
            }

            public function getDanfse(string $nfseXml): string
            {
                throw new \LogicException('Not used.');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SEFIN unavailable');

        (new RecoverInvoiceEmission())->recover($client, $this->dps());
    }

    private function dps(): DpsData
    {
        return new DpsData(
            cnpjPrestador: '11222333000181',
            municipioIbge: '3303302',
            itemListaServico: '0107',
            valorServico: '100.00',
            aliquota: '2.00',
            discriminacao: 'Recovery test',
            serie: '1',
            numeroDps: '42',
        );
    }
}

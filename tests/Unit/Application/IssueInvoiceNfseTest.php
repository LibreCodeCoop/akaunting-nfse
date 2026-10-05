<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\IssueInvoiceNfse;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NetworkException;
use PHPUnit\Framework\TestCase;

final class IssueInvoiceNfseTest extends TestCase
{
    public function testReturnsDirectIssuanceReceipt(): void
    {
        $client = new class () implements NfseClientInterface {
            public function emit(DpsData $dps): ReceiptData
            {
                return new ReceiptData('10', str_repeat('1', 50), '2026-10-05T10:00:00-03:00');
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

        $receipt = (new IssueInvoiceNfse())->issue($client, $this->dps());

        self::assertSame('10', $receipt->nfseNumber);
    }

    public function testRecoversAmbiguousPostByDpsBeforeReturningFailure(): void
    {
        $client = new class () implements NfseClientInterface {
            public function emit(DpsData $dps): ReceiptData
            {
                throw new NetworkException('timeout');
            }

            public function queryDps(string $idDps): string
            {
                return str_repeat('2', 50);
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                return new ReceiptData('11', $chaveAcesso, '2026-10-05T10:00:00-03:00');
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

        $receipt = (new IssueInvoiceNfse())->issue($client, $this->dps());

        self::assertSame('11', $receipt->nfseNumber);
        self::assertSame(str_repeat('2', 50), $receipt->chaveAcesso);
    }

    public function testRethrowsNetworkFailureWhenRecoveryCannotResolveOutcome(): void
    {
        $client = new class () implements NfseClientInterface {
            public function emit(DpsData $dps): ReceiptData
            {
                throw new NetworkException('timeout');
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

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('timeout');

        (new IssueInvoiceNfse())->issue($client, $this->dps());
    }

    private function dps(): DpsData
    {
        return new DpsData(
            cnpjPrestador: '11222333000181',
            municipioIbge: '3303302',
            itemListaServico: '0107',
            valorServico: '100.00',
            aliquota: '2.00',
            discriminacao: 'Issuance use case test',
            serie: '1',
            numeroDps: '42',
        );
    }
}

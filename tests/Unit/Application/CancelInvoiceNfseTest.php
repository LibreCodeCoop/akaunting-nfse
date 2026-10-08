<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\CancelInvoiceNfse;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use PHPUnit\Framework\TestCase;

final class CancelInvoiceNfseTest extends TestCase
{
    public function testCancelsRemoteDocumentBeforePersistingCancelledState(): void
    {
        $client = new class () implements NfseClientInterface {
            public string $accessKey = '';
            public string $reasonCode = '';
            public string $reason = '';

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
                throw new \LogicException('Legacy cancellation path must not be used.');
            }

            public function cancelWithReason(string $chaveAcesso, string $codigoMotivo, string $motivo): bool
            {
                $this->accessKey = $chaveAcesso;
                $this->reasonCode = $codigoMotivo;
                $this->reason = $motivo;

                return true;
            }

            public function getDanfse(string $nfseXml): string
            {
                throw new \LogicException('Not used.');
            }
        };

        $receipt = $this->receipt('ACCESS-42', 'emitted');

        (new CancelInvoiceNfse())->cancel($client, $receipt, '1', 'Erro na emissão - teste');

        self::assertSame('ACCESS-42', $client->accessKey);
        self::assertSame('1', $client->reasonCode);
        self::assertSame('Erro na emissão - teste', $client->reason);
        self::assertSame('cancelled', $receipt->status);
    }

    public function testProtocolFailurePropagatesWithoutChangingLocalState(): void
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
                throw new \LogicException('Legacy cancellation path must not be used.');
            }

            public function cancelWithReason(string $chaveAcesso, string $codigoMotivo, string $motivo): bool
            {
                throw new \RuntimeException('SEFIN unavailable');
            }

            public function getDanfse(string $nfseXml): string
            {
                throw new \LogicException('Not used.');
            }
        };

        $receipt = $this->receipt('ACCESS-42', 'emitted');

        try {
            (new CancelInvoiceNfse())->cancel($client, $receipt, '2', 'Serviço não prestado');
            self::fail('Expected cancellation failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('SEFIN unavailable', $exception->getMessage());
        }

        self::assertSame('emitted', $receipt->status);
    }

    private function receipt(string $accessKey, string $status): NfseReceipt
    {
        return new class ($accessKey, $status) extends NfseReceipt {
            public function __construct(string $accessKey, string $status)
            {
                $this->chave_acesso = $accessKey;
                $this->status = $status;
            }

            public function update(array $values): void
            {
                foreach ($values as $key => $value) {
                    $this->{$key} = $value;
                }
            }
        };
    }
}

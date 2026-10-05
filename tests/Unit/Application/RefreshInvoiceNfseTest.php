<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\ReceiptNumberResolver;
use Modules\Nfse\Application\RefreshInvoiceNfse;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use PHPUnit\Framework\TestCase;

final class RefreshInvoiceNfseTest extends TestCase
{
    public function testRefreshQueriesExistingAccessKeyAndPersistsRemoteReceipt(): void
    {
        $client = new class () implements NfseClientInterface {
            public string $queried = '';

            public function emit(DpsData $dps): ReceiptData
            {
                throw new \LogicException('Not used.');
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                $this->queried = $chaveAcesso;

                return new ReceiptData(
                    nfseNumber: '42',
                    chaveAcesso: 'NEW-ACCESS',
                    dataEmissao: '2026-10-05T10:00:00-03:00',
                    codigoVerificacao: 'CV42',
                );
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

        $receipt = $this->receipt('OLD-ACCESS', 'processing');
        $updated = (new RefreshInvoiceNfse())->refresh($client, $receipt);

        self::assertSame('OLD-ACCESS', $client->queried);
        self::assertSame('42', $receipt->nfse_number);
        self::assertSame('NEW-ACCESS', $receipt->chave_acesso);
        self::assertSame('emitted', $receipt->status);
        self::assertSame('NEW-ACCESS', $updated->chaveAcesso);
    }

    public function testQueryFailurePropagatesWithoutChangingLocalReceipt(): void
    {
        $client = new class () implements NfseClientInterface {
            public function emit(DpsData $dps): ReceiptData
            {
                throw new \LogicException('Not used.');
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                throw new \RuntimeException('SEFIN unavailable');
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

        $receipt = $this->receipt('OLD-ACCESS', 'processing');

        $this->expectException(\RuntimeException::class);

        try {
            (new RefreshInvoiceNfse())->refresh($client, $receipt);
        } finally {
            self::assertSame('OLD-ACCESS', $receipt->chave_acesso);
            self::assertSame('processing', $receipt->status);
        }
    }

    public function testReceiptNumberResolverFallsBackToAuthorizedXml(): void
    {
        $receipt = new ReceiptData(
            nfseNumber: '',
            chaveAcesso: 'ACCESS',
            dataEmissao: '2026-10-05T10:00:00-03:00',
            rawXml: '<NFSe><infNFSe><nNFSe>987</nNFSe></infNFSe></NFSe>',
        );

        self::assertSame('987', (new ReceiptNumberResolver())->resolve($receipt));
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

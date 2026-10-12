<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Application\RefreshInvoiceNfse;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Tests\Feature\FeatureTestCase;

final class RefreshInvoiceNfseAuthorizedXmlTest extends FeatureTestCase
{
    public function testRefreshingAlreadyAuthorizedDocumentCannotReplaceStoredEstimatedTaxes(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '955',
            'chave_acesso' => str_repeat('5', 50),
            'status' => 'emitted',
        ]);
        $historicXml = $this->xml('3.20');
        $receipt->payload()->create(['authorized_xml' => $historicXml]);

        $client = $this->client($this->xml('4.10'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('archived original');

        try {
            (new RefreshInvoiceNfse())->refresh($client, $receipt);
        } finally {
            self::assertSame($historicXml, $receipt->fresh()->payload()->value('authorized_xml'));
            self::assertSame(str_repeat('5', 50), $receipt->fresh()->chave_acesso);
            self::assertSame('emitted', $receipt->fresh()->status);
        }
    }

    public function testRefreshingWithoutOriginalStoresExactAuthorizedXmlWithSnapshot(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '956',
            'chave_acesso' => str_repeat('6', 50),
            'status' => 'emitted',
        ]);
        $xml = $this->xml('0.00') . "\n";

        $updated = (new RefreshInvoiceNfse())->refresh($this->client($xml), $receipt);

        self::assertSame($xml, $updated->rawXml);
        self::assertSame($xml, $receipt->fresh()->payload()->value('authorized_xml'));
        self::assertSame(hash('sha256', $xml), $receipt->fresh()->authorized_fiscal_snapshot['source_sha256']);
        self::assertSame('0.00', $receipt->fresh()->authorized_fiscal_snapshot['approximate_taxes']['municipal_percent']);
    }

    private function xml(string $municipal): string
    {
        return '<NFSe><infNFSe><DPS><infDPS><dCompet>2026-10-05</dCompet><valores><trib><totTrib><pTotTrib><pTotTribFed>6.25</pTotTribFed><pTotTribEst>0.00</pTotTribEst><pTotTribMun>'
            . $municipal
            . '</pTotTribMun></pTotTrib></totTrib></trib></valores></infDPS></DPS></infNFSe></NFSe>';
    }

    private function client(string $authorizedXml): NfseClientInterface
    {
        return new class ($authorizedXml) implements NfseClientInterface {
            public function __construct(private readonly string $authorizedXml)
            {
            }

            public function emit(DpsData $dps): ReceiptData
            {
                throw new \LogicException('Fake transport does not issue fiscal documents.');
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                return new ReceiptData(
                    nfseNumber: '956',
                    chaveAcesso: $chaveAcesso,
                    dataEmissao: '2026-10-05T10:00:00-03:00',
                    rawXml: $this->authorizedXml,
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
    }
}

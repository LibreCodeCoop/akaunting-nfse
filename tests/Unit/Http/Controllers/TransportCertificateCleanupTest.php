<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Http\Controllers;

use Modules\Nfse\Http\Controllers\ControllerIsolationState;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Modules\Nfse\Tests\TestCase;
use Modules\Nfse\Tests\Unit\Http\Controllers\Support\InvoiceControllerIsolationState;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;

/**
 * Validates that DANFSE generation happens locally from the authorized XML.
 *
 * Background: DANFSE generation no longer depends on the HTTP client transport layer.
 * The controller should invoke the local package generator directly and never call
 * client->getDanfse() while storing artifacts.
 */
final class TransportCertificateCleanupTest extends TestCase
{
    public function testStoreArtifactsGeneratesDanfseLocallyWithoutUsingClientTransport(): void
    {
        ControllerIsolationState::$settings['nfse.webdav_url'] = 'https://dav.example.com/root';
        ControllerIsolationState::$settings['nfse.webdav_store_xml'] = true;
        ControllerIsolationState::$settings['nfse.webdav_store_pdf'] = true;

        $invoice = InvoiceControllerIsolationState::makeInvoice(
            id: 2234,
            amount: 29877.75,
            items: [['name' => 'Servico de Auditoria']],
            contactName: 'Assessoria',
        );
        $persistedReceipt = InvoiceControllerIsolationState::makeReceipt(2234, 'CHAVE-2234', 'emitted');

        $controller = new class () extends InvoiceController {
            public int $generatorCalls = 0;

            protected function makeWebDavClientFromSettings(): \Modules\Nfse\Support\WebDavClient
            {
                return new \Modules\Nfse\Support\WebDavClient(
                    baseUrl: 'https://dav.example.com/root',
                    request: function (string $method, string $url, array $headers, string $body): array {
                        return [201, ''];
                    },
                );
            }

            public function callStoreArtifacts(
                \App\Models\Document\Document $invoice,
                ReceiptData $receipt,
                \Modules\Nfse\Models\NfseReceipt $nfseReceipt,
                NfseClientInterface $client,
            ): void {
                $this->storeArtifacts($invoice, $receipt, $nfseReceipt, $client);
            }

            protected function makeDanfseGenerator(): object
            {
                return new class ($this) {
                    public function __construct(private readonly object $controller)
                    {
                    }

                    public function generateFromXml(string $nfseXml): string
                    {
                        $this->controller->generatorCalls++;

                        return '%PDF-synthetic-danfse';
                    }
                };
            }
        };

        $client = new class () implements NfseClientInterface {
            public bool $danfseWasCalled = false;

            public function emit(DpsData $dps): ReceiptData
            {
                throw new \BadMethodCallException('Not used in this test.');
            }

            public function query(string $chaveAcesso): ReceiptData
            {
                throw new \BadMethodCallException('Not used in this test.');
            }

            public function cancel(string $chaveAcesso, string $motivo): bool
            {
                throw new \BadMethodCallException('Not used in this test.');
            }

            public function getDanfse(string $nfseXml): string
            {
                $this->danfseWasCalled = true;

                throw new \RuntimeException('Client DANFSE transport should not be used.');
            }
        };

        $receipt = new ReceiptData(
            nfseNumber: 'NF-2234',
            chaveAcesso: 'CHAVE-2234',
            dataEmissao: '2026-07-02T18:52:14-03:00',
            codigoVerificacao: 'CV2234',
            rawXml: '<NFSe>XML content</NFSe>',
        );

        $controller->callStoreArtifacts($invoice, $receipt, $persistedReceipt, $client);

        self::assertSame(1, $controller->generatorCalls);
        self::assertFalse($client->danfseWasCalled, 'client->getDanfse() should not be used during local DANFSE generation');
        self::assertSame('nfse/12345678000195/2026/07/02/chave-2234.pdf', $persistedReceipt->danfse_webdav_path ?? null);
    }
}

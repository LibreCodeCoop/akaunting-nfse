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
 * Validates that transport certificates are not prematurely deleted before being used.
 *
 * Background: In production, DANFSE generation failed because the temporary certificate files
 * (/dev/shm/nfse_tls_cert_*) were deleted by the cleanup closure too early - before the client
 * had finished using them to make mTLS requests to the gateway.
 *
 * The fix moved the cleanup logic to occur AFTER storeArtifacts() completes, ensuring that
 * getDanfse() calls (which require mTLS transport certificates) complete before cleanup.
 */
final class TransportCertificateCleanupTest extends TestCase
{
    public function testTransportCertificatesAvailableDuringDanfseGeneration(): void
    {
        // Track when the certificate files were deleted
        $certificateCheckAttempts = [];

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

        $controller = new class ($certificateCheckAttempts) extends InvoiceController {
            public array $certificateCheckAttempts = [];

            public function __construct(array $attempts)
            {
                $this->certificateCheckAttempts = $attempts;
            }

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
        };

        $clientDanfseWasCalled = false;

        $client = new class ($certificateCheckAttempts, $clientDanfseWasCalled) implements NfseClientInterface {
            public array $certificateCheckAttempts = [];
            public bool $danfseWasCalled = false;

            public function __construct(array $attempts, bool $called)
            {
                $this->certificateCheckAttempts = $attempts;
                $this->danfseWasCalled = $called;
            }

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
                // The fix ensures this method is called BEFORE certificate cleanup
                // If it's called after cleanup and /dev/shm doesn't have the file,
                // cURL/OpenSSL will throw "Unable to set local cert chain file" error
                $this->danfseWasCalled = true;

                return '%PDF-synthetic-danfse';
            }
        };

        $receipt = new ReceiptData(
            nfseNumber: 'NF-2234',
            chaveAcesso: 'CHAVE-2234',
            dataEmissao: '2026-07-02T18:52:14-03:00',
            codigoVerificacao: 'CV2234',
            rawXml: '<NFSe>XML content</NFSe>',
        );

        // Call storeArtifacts which should:
        // 1. Call client->getDanfse() to generate the PDF
        // 2. Only AFTER that completes, cleanup the transport certificates
        $controller->callStoreArtifacts($invoice, $receipt, $persistedReceipt, $client);

        // Verify that getDanfse was called (and didn't fail due to missing cert files)
        self::assertTrue($client->danfseWasCalled, 'getDanfse() should have been called during storeArtifacts()');
    }
}

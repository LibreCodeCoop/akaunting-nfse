<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    require_once dirname(__DIR__) . '/Http/Controllers/Support/InvoiceControllerIsolationState.php';
}

namespace Modules\Nfse\Tests\Unit\Application {
    use Modules\Nfse\Application\NfseArtifactStorage;
    use Modules\Nfse\Http\Controllers\ControllerIsolationState;
    use Modules\Nfse\Support\WebDavClient;
    use Modules\Nfse\Tests\TestCase;
    use Modules\Nfse\Tests\Unit\Http\Controllers\Support\InvoiceControllerIsolationState;
    use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;

    final class NfseArtifactStorageTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            InvoiceControllerIsolationState::reset();
            ControllerIsolationState::$settings['nfse.webdav_url'] = 'https://dav.example.com/root';
            ControllerIsolationState::$settings['nfse.webdav_store_xml'] = true;
            ControllerIsolationState::$settings['nfse.webdav_store_pdf'] = true;
            ControllerIsolationState::$settings['nfse.cnpj_prestador'] = '12345678000195';
        }

        public function testStoresXmlEvenWhenDanfseGenerationFails(): void
        {
            $writes = [];
            $errors = [];
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 777,
                amount: 210.0,
                items: [['name' => 'Servico X']],
                contactName: 'Cliente X',
            );
            $persistedReceipt = InvoiceControllerIsolationState::makeReceipt(777, 'CHAVE-777', 'emitted');

            $storage = new NfseArtifactStorage(
                webDavFactory: static fn (): WebDavClient => new WebDavClient(
                    baseUrl: 'https://dav.example.com/root',
                    request: static function (string $method, string $url, array $headers, string $body) use (&$writes): array {
                        if ($method === 'PUT') {
                            $writes[] = [$url, $body];
                        }

                        return [201, ''];
                    },
                ),
                danfseGenerator: static function (string $xml): string {
                    throw new \RuntimeException('DANFSE unavailable');
                },
                logError: static function (string $message, array $context) use (&$errors): void {
                    $errors[] = compact('message', 'context');
                },
                settingResolver: static fn (string $key, mixed $default = null): mixed => ControllerIsolationState::$settings[$key] ?? $default,
            );

            $storage->store($invoice, $this->receipt('<xml>conteudo</xml>'), $persistedReceipt);

            self::assertCount(1, $writes);
            self::assertStringEndsWith('/chave-777.xml', $writes[0][0]);
            self::assertSame('<xml>conteudo</xml>', $writes[0][1]);
            self::assertSame('nfse/12345678000195/2026/04/14/chave-777.xml', $persistedReceipt->xml_webdav_path ?? null);
            self::assertNull($persistedReceipt->danfse_webdav_path ?? null);
            self::assertCount(1, $errors);
            self::assertStringContainsString('DANFSE', $errors[0]['message']);
        }

        public function testStoresPdfGeneratedDirectlyFromAuthorizedXml(): void
        {
            ControllerIsolationState::$settings['nfse.webdav_store_xml'] = false;

            $writes = [];
            $capturedXml = null;
            $invoice = InvoiceControllerIsolationState::makeInvoice(
                id: 779,
                amount: 210.0,
                items: [['name' => 'Servico X']],
                contactName: 'Cliente X',
            );
            $persistedReceipt = InvoiceControllerIsolationState::makeReceipt(779, 'CHAVE-777', 'emitted');

            $storage = new NfseArtifactStorage(
                webDavFactory: static fn (): WebDavClient => new WebDavClient(
                    baseUrl: 'https://dav.example.com/root',
                    request: static function (string $method, string $url, array $headers, string $body) use (&$writes): array {
                        if ($method === 'PUT') {
                            $writes[] = [$url, $body];
                        }

                        return [201, ''];
                    },
                ),
                danfseGenerator: static function (string $xml) use (&$capturedXml): string {
                    $capturedXml = $xml;

                    return '%PDF-1.4';
                },
                settingResolver: static fn (string $key, mixed $default = null): mixed => ControllerIsolationState::$settings[$key] ?? $default,
            );

            $storage->store($invoice, $this->receipt('<NFSe>autorizada</NFSe>'), $persistedReceipt);

            self::assertSame('<NFSe>autorizada</NFSe>', $capturedXml);
            self::assertCount(1, $writes);
            self::assertStringEndsWith('/chave-777.pdf', $writes[0][0]);
            self::assertSame('%PDF-1.4', $writes[0][1]);
            self::assertSame('nfse/12345678000195/2026/04/14/chave-777.pdf', $persistedReceipt->danfse_webdav_path ?? null);
        }

        public function testDoesNotPersistPathWhenWebDavUploadFails(): void
        {
            ControllerIsolationState::$settings['nfse.webdav_store_pdf'] = false;

            $errors = [];
            $invoice = InvoiceControllerIsolationState::makeInvoice(id: 778, amount: 210.0);
            $persistedReceipt = InvoiceControllerIsolationState::makeReceipt(778, 'CHAVE-777', 'emitted');

            $storage = new NfseArtifactStorage(
                webDavFactory: static fn (): WebDavClient => new WebDavClient(
                    baseUrl: 'https://dav.example.com/root',
                    request: static function (string $method, string $url, array $headers, string $body): array {
                        if ($method === 'PUT') {
                            return [500, 'upload failed'];
                        }

                        return [201, ''];
                    },
                ),
                logError: static function (string $message, array $context) use (&$errors): void {
                    $errors[] = compact('message', 'context');
                },
                settingResolver: static fn (string $key, mixed $default = null): mixed => ControllerIsolationState::$settings[$key] ?? $default,
            );

            $storage->store($invoice, $this->receipt('<xml>conteudo</xml>'), $persistedReceipt);

            self::assertNull($persistedReceipt->xml_webdav_path ?? null);
            self::assertCount(1, $errors);
            self::assertStringContainsString('XML', $errors[0]['message']);
        }

        private function receipt(string $xml): ReceiptData
        {
            return new ReceiptData(
                nfseNumber: 'NF-777',
                chaveAcesso: 'CHAVE-777',
                dataEmissao: '2026-04-14T01:45:00-03:00',
                codigoVerificacao: 'CV777',
                rawXml: $xml,
            );
        }
    }
}

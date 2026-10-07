<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Application\IssuedNfseArtifactStore;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Models\NfseReceiptPayload;
use Modules\Nfse\Support\WebDavClient;
use Tests\Feature\FeatureTestCase;

final class IssuedNfseArtifactStoreTest extends FeatureTestCase
{
    public function testStoresAuthorizedXmlAndGeneratedDanfseOnlyOnce(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '123',
            'chave_acesso' => str_repeat('1', 50),
            'data_emissao' => '2026-10-07T10:00:00-03:00',
            'status' => 'emitted',
        ]);

        NfseReceiptPayload::query()->create([
            'receipt_id' => $receipt->id,
            'authorized_xml' => '<NFSe>authorized</NFSe>',
        ]);

        $requests = [];

        $store = new class ($requests) extends IssuedNfseArtifactStore {
            public function __construct(private array &$requests)
            {
            }

            protected function webDavEnabled(): bool
            {
                return true;
            }

            protected function storeXmlEnabled(): bool
            {
                return true;
            }

            protected function storePdfEnabled(): bool
            {
                return true;
            }

            protected function makeWebDavClient(): WebDavClient
            {
                return new WebDavClient(
                    baseUrl: 'https://dav.example.test/root',
                    request: function (string $method, string $url, array $headers, string $body): array {
                        $this->requests[] = [$method, $url, $body];

                        return [201, ''];
                    },
                );
            }

            protected function generateDanfse(string $authorizedXml): string
            {
                self::assertSame('<NFSe>authorized</NFSe>', $authorizedXml);

                return '%PDF-1.4 generated';
            }
        };

        $store->store((int) $invoice->id, (int) $receipt->id);
        $store->store((int) $invoice->id, (int) $receipt->id);

        $fresh = $receipt->fresh();
        self::assertNotEmpty($fresh->xml_webdav_path);
        self::assertNotEmpty($fresh->danfse_webdav_path);

        $puts = array_values(array_filter(
            $requests,
            static fn (array $request): bool => $request[0] === 'PUT',
        ));

        self::assertCount(2, $puts);
        self::assertSame('<NFSe>authorized</NFSe>', $puts[0][2]);
        self::assertSame('%PDF-1.4 generated', $puts[1][2]);
    }

    public function testFailsFastWhenAuthorizedXmlWasNotPersisted(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '124',
            'chave_acesso' => str_repeat('2', 50),
            'data_emissao' => '2026-10-07T10:00:00-03:00',
            'status' => 'emitted',
        ]);

        $store = new class () extends IssuedNfseArtifactStore {
            protected function webDavEnabled(): bool
            {
                return true;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('authorized NFS-e XML');

        $store->store((int) $invoice->id, (int) $receipt->id);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Models\NfseReceiptPayload;
use Tests\Feature\FeatureTestCase;

final class PostEmissionStatusEndpointTest extends FeatureTestCase
{
    public function testStatusEndpointReadsOnlyPersistedQueueStateAndArtifactPaths(): void
    {
        $invoice = Document::factory()->invoice()->draft()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '146',
            'chave_acesso' => str_repeat('4', 50),
            'status' => 'emitted',
            'xml_webdav_path' => 'nfse/146.xml',
            'danfse_webdav_path' => null,
        ]);
        NfseReceiptPayload::query()->create([
            'receipt_id' => $receipt->id,
            'authorized_xml' => '<NFSe/>',
            'artifacts_status' => 'processing',
            'email_status' => 'pending',
        ]);

        $response = (new InvoiceController())->postEmissionStatus($invoice);
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $data = $payload['data'];

        self::assertSame('processing', $data['status']);
        self::assertTrue($data['poll']);
        self::assertSame((int) $receipt->id, $data['receipt_id']);
        self::assertSame('processing', $data['stages']['artifacts']);
        self::assertSame('pending', $data['stages']['email']);
        self::assertTrue($data['artifacts']['xml']['ready']);
        self::assertFalse($data['artifacts']['danfse']['ready']);
        self::assertIsString($data['artifacts']['xml']['download_url']);
        self::assertNull($data['artifacts']['danfse']['download_url']);
    }

    public function testStatusEndpointExposesDownloadsFromAuthorizedXmlBeforeWebDavArchival(): void
    {
        $invoice = Document::factory()->invoice()->draft()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '148',
            'chave_acesso' => str_repeat('6', 50),
            'status' => 'emitted',
            'xml_webdav_path' => null,
            'danfse_webdav_path' => null,
        ]);
        NfseReceiptPayload::query()->create([
            'receipt_id' => $receipt->id,
            'authorized_xml' => '<NFSe>authorized</NFSe>',
            'artifacts_status' => 'processing',
            'email_status' => 'pending',
        ]);

        $response = (new InvoiceController())->postEmissionStatus($invoice);
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['data']['artifacts']['xml']['ready']);
        self::assertTrue($payload['data']['artifacts']['danfse']['ready']);
        self::assertIsString($payload['data']['artifacts']['xml']['download_url']);
        self::assertIsString($payload['data']['artifacts']['danfse']['download_url']);
    }

    public function testStatusEndpointStopsPollingAfterSuccessfulPostProcessing(): void
    {
        $invoice = Document::factory()->invoice()->draft()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '147',
            'chave_acesso' => str_repeat('5', 50),
            'status' => 'emitted',
            'xml_webdav_path' => 'nfse/147.xml',
            'danfse_webdav_path' => 'nfse/147.pdf',
        ]);
        NfseReceiptPayload::query()->create([
            'receipt_id' => $receipt->id,
            'authorized_xml' => '<NFSe/>',
            'artifacts_status' => 'completed',
            'email_status' => 'completed',
            'post_emission_email_sent_at' => now(),
        ]);

        $response = (new InvoiceController())->postEmissionStatus($invoice);
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('completed', $payload['data']['status']);
        self::assertFalse($payload['data']['poll']);
        self::assertTrue($payload['data']['artifacts']['xml']['ready']);
        self::assertTrue($payload['data']['artifacts']['danfse']['ready']);
    }
}

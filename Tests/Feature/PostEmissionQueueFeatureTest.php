<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Illuminate\Support\Facades\Bus;
use Modules\Nfse\Application\IssuedNfseArtifactStore;
use Modules\Nfse\Application\IssuedNfseEmailSender;
use Modules\Nfse\Application\PostEmissionState;
use Modules\Nfse\Application\PostEmissionDispatcher;
use Modules\Nfse\Jobs\SendIssuedNfseEmail;
use Modules\Nfse\Jobs\StoreIssuedNfseArtifacts;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Models\NfseReceiptPayload;
use Tests\Feature\FeatureTestCase;

final class PostEmissionQueueFeatureTest extends FeatureTestCase
{
    public function testDispatcherBuildsOrderedLaravelJobChainAndInitializesStatus(): void
    {
        Bus::fake();

        $invoice = Document::factory()->invoice()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '120',
            'chave_acesso' => str_repeat('1', 50),
            'status' => 'emitted',
        ]);
        NfseReceiptPayload::query()->create([
            'receipt_id' => $receipt->id,
            'authorized_xml' => '<NFSe/>',
        ]);

        (new PostEmissionDispatcher())->dispatch(
            invoiceId: (int) $invoice->id,
            receiptId: (int) $receipt->id,
            email: [
                'attach_danfse' => true,
                'attach_xml' => false,
                'custom_mail' => ['to' => 'customer@example.com'],
            ],
        );

        Bus::assertChained([
            new StoreIssuedNfseArtifacts((int) $invoice->id, (int) $receipt->id),
            new SendIssuedNfseEmail(
                invoiceId: (int) $invoice->id,
                receiptId: (int) $receipt->id,
                attachDanfse: true,
                attachXml: false,
                customMail: ['to' => 'customer@example.com'],
            ),
        ]);

        $payload = $receipt->payload()->firstOrFail();
        self::assertSame('pending', $payload->artifacts_status);
        self::assertSame('pending', $payload->email_status);
        self::assertNull($payload->post_processing_error);
    }

    public function testEmailJobDoesNotSendAgainAfterSuccessfulDelivery(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '123',
            'chave_acesso' => str_repeat('1', 50),
            'status' => 'emitted',
        ]);

        NfseReceiptPayload::query()->create([
            'receipt_id' => $receipt->id,
            'authorized_xml' => '<NFSe/>',
        ]);

        $calls = [];
        $sender = new class ($calls) extends IssuedNfseEmailSender {
            public function __construct(private array &$calls)
            {
            }

            public function send(
                Document $invoice,
                NfseReceipt $receipt,
                bool $attachDanfse,
                bool $attachXml,
                array $customMail,
            ): void {
                $this->calls[] = [$invoice->id, $receipt->id, $customMail['to'] ?? null];
            }
        };

        $job = new SendIssuedNfseEmail(
            invoiceId: (int) $invoice->id,
            receiptId: (int) $receipt->id,
            attachDanfse: true,
            attachXml: true,
            customMail: ['to' => 'customer@example.com'],
        );

        $state = new PostEmissionState();

        $job->handle($sender, $state);
        $job->handle($sender, $state);

        self::assertCount(1, $calls);
        $payload = NfseReceiptPayload::query()->where('receipt_id', $receipt->id)->firstOrFail();
        self::assertNotNull($payload->post_emission_email_sent_at);
        self::assertSame('completed', $payload->email_status);
    }

    public function testArtifactJobMovesFromProcessingToCompleted(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '121',
            'chave_acesso' => str_repeat('2', 50),
            'status' => 'emitted',
        ]);
        NfseReceiptPayload::query()->create([
            'receipt_id' => $receipt->id,
            'authorized_xml' => '<NFSe/>',
            'artifacts_status' => 'pending',
            'email_status' => 'not_requested',
        ]);

        $calls = [];
        $store = new class ($calls) extends IssuedNfseArtifactStore {
            public function __construct(private array &$calls)
            {
            }

            public function store(int $invoiceId, int $receiptId): void
            {
                $this->calls[] = [$invoiceId, $receiptId];
            }
        };

        $job = new StoreIssuedNfseArtifacts((int) $invoice->id, (int) $receipt->id);
        $job->handle($store, new PostEmissionState());

        self::assertSame([[(int) $invoice->id, (int) $receipt->id]], $calls);

        $payload = $receipt->payload()->firstOrFail();
        self::assertSame('completed', $payload->artifacts_status);
        self::assertNotNull($payload->artifacts_completed_at);
    }

    public function testStateSnapshotStopsPollingAfterTerminalStages(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '122',
            'chave_acesso' => str_repeat('3', 50),
            'status' => 'emitted',
        ]);
        NfseReceiptPayload::query()->create([
            'receipt_id' => $receipt->id,
            'authorized_xml' => '<NFSe/>',
            'artifacts_status' => 'completed',
            'email_status' => 'not_requested',
        ]);

        $snapshot = (new PostEmissionState())->snapshot($receipt);

        self::assertSame('completed', $snapshot['overall_status']);
        self::assertFalse($snapshot['poll']);
    }
}

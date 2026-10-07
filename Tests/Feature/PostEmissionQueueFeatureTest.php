<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Illuminate\Support\Facades\Bus;
use Modules\Nfse\Application\IssuedNfseEmailSender;
use Modules\Nfse\Application\PostEmissionDispatcher;
use Modules\Nfse\Jobs\SendIssuedNfseEmail;
use Modules\Nfse\Jobs\StoreIssuedNfseArtifacts;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Models\NfseReceiptPayload;
use Tests\Feature\FeatureTestCase;

final class PostEmissionQueueFeatureTest extends FeatureTestCase
{
    public function testDispatcherBuildsOrderedLaravelJobChain(): void
    {
        Bus::fake();

        (new PostEmissionDispatcher())->dispatch(
            invoiceId: 10,
            receiptId: 20,
            email: [
                'attach_danfse' => true,
                'attach_xml' => false,
                'custom_mail' => ['to' => 'customer@example.com'],
            ],
        );

        Bus::assertChained([
            new StoreIssuedNfseArtifacts(10, 20),
            new SendIssuedNfseEmail(
                invoiceId: 10,
                receiptId: 20,
                attachDanfse: true,
                attachXml: false,
                customMail: ['to' => 'customer@example.com'],
            ),
        ]);
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

        $job->handle($sender);
        $job->handle($sender);

        self::assertCount(1, $calls);
        self::assertNotNull(
            NfseReceiptPayload::query()
                ->where('receipt_id', $receipt->id)
                ->value('post_emission_email_sent_at'),
        );
    }
}

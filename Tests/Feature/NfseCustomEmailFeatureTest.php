<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Events\Document\DocumentSending;
use App\Events\Document\DocumentSent;
use App\Models\Document\Document;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\Nfse\Jobs\ProcessNfsePostEmission;
use Modules\Nfse\Jobs\SendNfseCustomEmail;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Notifications\NfseIssued;
use Tests\Feature\FeatureTestCase;

final class NfseCustomEmailFeatureTest extends FeatureTestCase
{
    public function testCustomEmailJobUsesAkauntingNotificationWithSelectedFiscalAttachments(): void
    {
        Notification::fake();

        $invoice = Document::factory()->invoice()->create();
        $invoice->contact->forceFill(['email' => 'customer@example.test'])->saveQuietly();

        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '100',
            'chave_acesso' => str_repeat('1', 50),
            'status' => 'emitted',
        ]);

        (new SendNfseCustomEmail(
            invoice: $invoice->fresh(['contact']),
            receipt: $receipt,
            attachDanfse: true,
            attachXml: false,
            customMail: [
                'to' => ['customer@example.test'],
                'subject' => 'NFS-e emitida',
                'body' => 'Documento fiscal disponível.',
            ],
        ))->handle();

        Notification::assertSentTo(
            $invoice->contact->fresh(),
            NfseIssued::class,
            static function (NfseIssued $notification) use ($invoice, $receipt): bool {
                return $notification->invoice->is($invoice)
                    && $notification->receipt->is($receipt)
                    && $notification->attachDanfse
                    && !$notification->attachXml;
            },
        );
    }

    public function testCustomEmailControllerUsesNativeDocumentLifecycleAroundDelivery(): void
    {
        Notification::fake();
        Event::fake([DocumentSending::class, DocumentSent::class]);

        $invoice = Document::factory()->invoice()->create();
        $invoice->contact->forceFill(['email' => 'customer@example.test'])->saveQuietly();

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '101',
            'chave_acesso' => str_repeat('2', 50),
            'status' => 'emitted',
        ]);

        $this->loginAs()
            ->post(route('nfse.modals.invoices.emails.store', $invoice), [
                'document_id' => $invoice->id,
                'to' => ['customer@example.test'],
                'subject' => 'NFS-e emitida',
                'body' => 'Documento fiscal disponível.',
                'nfse_attach_danfse' => 0,
                'nfse_attach_xml' => 0,
            ])
            ->assertOk();

        Event::assertDispatched(DocumentSending::class, static fn (DocumentSending $event): bool => $event->document->is($invoice));
        Event::assertDispatched(DocumentSent::class, static fn (DocumentSent $event): bool => $event->document->is($invoice));
    }

    public function testCustomEmailJobDoesNothingWithoutPersistedReceipt(): void
    {
        Notification::fake();

        $invoice = Document::factory()->invoice()->create();
        $invoice->contact->forceFill(['email' => 'customer@example.test'])->saveQuietly();

        (new SendNfseCustomEmail(
            invoice: $invoice->fresh(['contact']),
            receipt: null,
            attachDanfse: true,
            attachXml: true,
            customMail: [
                'to' => ['customer@example.test'],
                'subject' => 'NFS-e emitida',
                'body' => 'Documento fiscal disponível.',
            ],
        ))->handle();

        Notification::assertNotSentTo($invoice->contact->fresh(), NfseIssued::class);
    }

    public function testPostEmissionJobSendsEmailWhenWebDavIsDisabled(): void
    {
        Notification::fake();

        setting(['nfse.webdav_url' => '']);
        setting()->save();

        $invoice = Document::factory()->invoice()->create();
        $invoice->contact->forceFill(['email' => 'customer@example.test'])->saveQuietly();

        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '102',
            'chave_acesso' => str_repeat('3', 50),
            'status' => 'emitted',
        ]);

        (new ProcessNfsePostEmission(
            invoiceId: (int) $invoice->id,
            receiptId: (int) $receipt->id,
            authorizedXml: '',
            email: [
                'attach_danfse' => false,
                'attach_xml' => false,
                'custom_mail' => [
                    'to' => 'customer@example.test',
                    'subject' => 'NFS-e emitida',
                    'body' => 'Documento fiscal disponível.',
                    'attach_invoice_pdf' => false,
                ],
            ],
        ))->handle();

        Notification::assertSentTo(
            $invoice->contact->fresh(),
            NfseIssued::class,
        );
    }
}

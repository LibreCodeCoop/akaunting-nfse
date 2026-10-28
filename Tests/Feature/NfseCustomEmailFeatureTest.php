<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Events\Document\DocumentSending;
use App\Events\Document\DocumentSent;
use App\Models\Common\Company;
use App\Models\Document\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
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

        Notification::assertSentOnDemand(
            NfseIssued::class,
            static function (NfseIssued $notification, array $channels, object $notifiable) use ($invoice, $receipt): bool {
                return $notification->invoice->is($invoice)
                    && $notification->receipt->is($receipt)
                    && $notification->attachDanfse
                    && !$notification->attachXml
                    && in_array('mail', $channels, true)
                    && ($notifiable->routes['mail'] ?? null) === ['customer@example.test'];
            },
        );
    }

    public function testCustomEmailUsesPersistedReceiptOwnerAndRestoresReusedWorkerContext(): void
    {
        $this->loginAs();
        $previous = $this->company;
        $ownerId = DB::table('companies')->insertGetId([
            'domain' => 'custom-email-owner.example.test',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $owner = Company::query()->findOrFail($ownerId);
        $owner->makeCurrent();
        setting()->set([
            'company.name' => 'Synthetic fiscal owner',
            'company.email' => 'sender-owner@example.test',
            'company.tax_number' => '11222333000181',
        ]);
        setting()->save();
        $invoice = Document::factory()->invoice()->draft()->create(['company_id' => $ownerId]);
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => 'SYNTH-EMAIL-OWNER',
            'chave_acesso' => str_repeat('6', 50),
            'status' => 'emitted',
        ]);

        $previous->makeCurrent();
        Notification::fake();
        $transitions = [];
        Event::listen(
            \App\Events\Common\CompanyMadeCurrent::class,
            static function (\App\Events\Common\CompanyMadeCurrent $event) use (&$transitions): void {
                $transitions[] = [
                    (int) $event->company->id,
                    (string) setting('company.email', ''),
                ];
            },
        );
        try {
            (new SendNfseCustomEmail(
                $invoice,
                $receipt,
                false,
                false,
                [
                    'to' => ['recipient@example.test'],
                    'subject' => 'Synthetic NFS-e notification',
                    'body' => 'Synthetic fiscal notice.',
                ],
            ))->handle();

            Notification::assertSentOnDemand(
                NfseIssued::class,
                static fn (NfseIssued $notification): bool =>
                    (int) $notification->invoice->company_id === (int) $ownerId,
            );
            self::assertCount(2, $transitions);
            self::assertSame([(int) $ownerId, 'sender-owner@example.test'], $transitions[0]);
            self::assertSame((int) $previous->id, $transitions[1][0]);
            self::assertSame((int) $previous->id, (int) Company::getCurrent()?->id);
        } finally {
            $previous->makeCurrent();
        }
    }

    public function testCustomEmailRefusesMismatchedReceiptWithoutSending(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->draft()->create();
        $other = Document::factory()->invoice()->draft()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $other->id,
            'nfse_number' => 'SYNTH-EMAIL-DENY',
            'chave_acesso' => str_repeat('5', 50),
            'status' => 'emitted',
        ]);
        Notification::fake();

        try {
            (new SendNfseCustomEmail(
                $invoice,
                $receipt,
                false,
                false,
                ['to' => ['recipient@example.test']],
            ))->handle();
            self::fail('Expected mismatched receipt ownership to fail.');
        } catch (\DomainException $error) {
            self::assertSame('Post-emission operation has no verified company owner.', $error->getMessage());
        }

        Notification::assertNothingSent();
    }

    public function testNotificationIssuerIdentityComesFromAuthorizedXmlNotLegacySettings(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '104',
            'chave_acesso' => str_repeat('4', 50),
            'status' => 'emitted',
        ]);
        $xml = file_get_contents(__DIR__ . '/../../tests/fixtures/nfse_exemplo.xml');
        self::assertIsString($xml);
        $receipt->payload()->create(['authorized_xml' => $xml]);

        setting()->set(['nfse.cnpj_prestador' => '99999999000199']);
        setting()->save();

        $notification = new NfseIssued($invoice->fresh(['company']), $receipt, false, false);
        self::assertSame('11222333000181', $notification->getTagsReplacement()[0]);
    }

    public function testNativeMailerEnvelopesHavePrivateBccRecipientsWithoutPublicBccHeaders(): void
    {
        $this->loginAs();

        config([
            'mail.default' => 'null',
            'mail.mailers.null' => ['transport' => 'null'],
            'mail.from.address' => 'no-reply@example.test',
            'mail.from.name' => 'Example Company',
        ]);

        $invoice = Document::factory()->invoice()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '102',
            'chave_acesso' => str_repeat('3', 50),
            'status' => 'emitted',
        ]);

        // Model factories may send unrelated mail. Observe only the delivery
        // being tested, after the invoice and receipt have been created.
        $messages = [];
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Mail\Events\MessageSending::class,
            static function (\Illuminate\Mail\Events\MessageSending $event) use (&$messages): void {
                $messages[] = $event->message;
            },
        );

        (new \Modules\Nfse\Application\IssuedNfseEmailSender())->send(
            invoice: $invoice->fresh(['contact']),
            receipt: $receipt,
            attachDanfse: false,
            attachXml: false,
            customMail: [
                'to' => ['first@example.test', 'second@example.test'],
                'cc' => ['copy@example.test'],
                'bcc' => ['hidden@example.test', 'self@example.test'],
                'attach_invoice_pdf' => false,
                'subject' => 'Fictitious NFS-e',
                'body' => 'Synthetic notification.',
            ],
        );

        self::assertCount(1, $messages, 'One notification must produce exactly one native mail message.');

        $message = $messages[0];
        $emails = static fn (array $addresses): array => array_map(
            static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(),
            $addresses,
        );

        self::assertSame(['first@example.test', 'second@example.test'], $emails($message->getTo()));
        self::assertSame(['copy@example.test'], $emails($message->getCc()));
        self::assertSame(['hidden@example.test', 'self@example.test'], $emails($message->getBcc()));

        $transported = new \Symfony\Component\Mailer\SentMessage(
            $message,
            \Symfony\Component\Mailer\Envelope::create($message),
        );
        self::assertSame(
            ['first@example.test', 'second@example.test', 'copy@example.test', 'hidden@example.test', 'self@example.test'],
            $emails($transported->getEnvelope()->getRecipients()),
        );

        $rawMessage = $transported->toString();
        self::assertStringContainsString('Cc: copy@example.test', $rawMessage);
        self::assertStringNotContainsString('Bcc:', $rawMessage);
        self::assertStringNotContainsString('hidden@example.test', $rawMessage);
        self::assertStringNotContainsString('self@example.test', $rawMessage);
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

    public function testInvalidCopyIsRejectedBeforeAnyDocumentSendingEvent(): void
    {
        Notification::fake();
        Event::fake([DocumentSending::class, DocumentSent::class]);

        $invoice = Document::factory()->invoice()->create();
        $this->loginAs();

        // Creating the document may dispatch native Akaunting lifecycle events.
        // Compare with the state immediately before the rejected request.
        $sendingBefore = Event::dispatched(DocumentSending::class)->count();
        $sentBefore = Event::dispatched(DocumentSent::class)->count();

        $this->post(route('nfse.modals.invoices.emails.store', $invoice), [
                'document_id' => $invoice->id,
                'to' => ['primary@example.test'],
                'cc' => 'copy@example.test',
                'bcc' => "hidden@example.test\r\nBcc: stolen@example.test",
            ])
            ->assertStatus(422);

        self::assertCount($sendingBefore, Event::dispatched(DocumentSending::class));
        self::assertCount($sentBefore, Event::dispatched(DocumentSent::class));
        // Login/model hooks may send unrelated notifications: check the NFS-e
        // mail notification specifically, after fiscal pre-validation failed.
        Notification::assertSentOnDemandTimes(NfseIssued::class, 0);
    }

    public function testEmailStoreCannotSwitchInvoiceByTamperingWithDocumentId(): void
    {
        Notification::fake();
        Event::fake([DocumentSending::class, DocumentSent::class]);

        $invoice = Document::factory()->invoice()->create();
        $otherInvoice = Document::factory()->invoice()->create();

        $this->withExceptionHandling();
        $this->loginAs();

        // Record fixture/authentication events before the rejected request;
        // the 404 must emit no additional document lifecycle events.
        $sendingBefore = Event::dispatched(DocumentSending::class)->count();
        $sentBefore = Event::dispatched(DocumentSent::class)->count();

        // Keep fixture and authentication notifications intercepted, but
        // observe only notifications emitted by this rejected HTTP request.
        Notification::fake();

        $this->post(route('nfse.modals.invoices.emails.store', $invoice), [
                'document_id' => $otherInvoice->id,
                'to' => ['recipient@example.test'],
            ])
            ->assertNotFound();

        self::assertCount($sendingBefore, Event::dispatched(DocumentSending::class));
        self::assertCount($sentBefore, Event::dispatched(DocumentSent::class));
        Notification::assertNothingSent();
    }

    public function testCustomEmailJobDoesNothingWithoutPersistedReceipt(): void
    {
        // Keep fixture-side notifications intercepted as well: test setup
        // must never access an actual notification transport.
        Notification::fake();

        $invoice = Document::factory()->invoice()->create();
        $invoice->contact->forceFill(['email' => 'customer@example.test'])->saveQuietly();

        // Reset the fake after fixture hooks. The final assertion now covers
        // every notification caused by the job, not fixture setup.
        Notification::fake();

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

        Notification::assertNothingSent();
    }
}

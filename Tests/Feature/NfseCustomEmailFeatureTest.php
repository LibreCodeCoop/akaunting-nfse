<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
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

        Notification::assertCount(0, NfseIssued::class);
    }
}

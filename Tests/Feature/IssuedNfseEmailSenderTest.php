<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Illuminate\Support\Facades\Notification;
use Modules\Nfse\Application\IssuedNfseEmailSender;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Notifications\NfseIssued;
use Tests\Feature\FeatureTestCase;

final class IssuedNfseEmailSenderTest extends FeatureTestCase
{
    public function testSenderUsesImmediateDeliveryInsideQueueJobBoundary(): void
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

        (new IssuedNfseEmailSender())->send(
            $invoice->fresh(['contact']),
            $receipt,
            true,
            false,
            ['to' => 'customer@example.test'],
        );

        Notification::assertSentTo(
            $invoice->contact->fresh(),
            NfseIssued::class,
        );
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Jobs;

use App\Models\Document\Document as Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Nfse\Application\IssuedNfseEmailSender;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Models\NfseReceiptPayload;

final class SendIssuedNfseEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    /**
     * @param array<string, mixed> $customMail
     */
    public function __construct(
        public readonly int $invoiceId,
        public readonly int $receiptId,
        public readonly bool $attachDanfse,
        public readonly bool $attachXml,
        public readonly array $customMail,
    ) {
        if ($invoiceId <= 0 || $receiptId <= 0) {
            throw new \InvalidArgumentException('Invoice and receipt identifiers are required.');
        }
    }

    public function handle(IssuedNfseEmailSender $sender): void
    {
        $invoice = Invoice::query()->with('contact')->findOrFail($this->invoiceId);
        $receipt = NfseReceipt::query()->findOrFail($this->receiptId);

        if ((int) $receipt->invoice_id !== $this->invoiceId) {
            throw new \RuntimeException('NFS-e receipt does not belong to the requested invoice.');
        }

        $payload = NfseReceiptPayload::query()->firstOrCreate([
            'receipt_id' => $this->receiptId,
        ]);

        if ($payload->post_emission_email_sent_at !== null) {
            return;
        }

        $sender->send(
            $invoice,
            $receipt,
            $this->attachDanfse,
            $this->attachXml,
            $this->customMail,
        );

        $payload->update([
            'post_emission_email_sent_at' => now(),
        ]);
    }
}

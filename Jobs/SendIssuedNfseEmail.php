<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Jobs;

use App\Models\Document\Document as Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Nfse\Application\IssuedNfseEmailSender;
use Modules\Nfse\Application\PostEmissionState;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\NfsePostEmissionCompanyContext;

final class SendIssuedNfseEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

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

    public function handle(IssuedNfseEmailSender $sender, PostEmissionState $state): void
    {
        app(NfsePostEmissionCompanyContext::class)->run(
            $this->invoiceId,
            $this->receiptId,
            fn (): mixed => $this->sendWithinCompany($sender, $state),
        );
    }

    private function sendWithinCompany(IssuedNfseEmailSender $sender, PostEmissionState $state): void
    {
        $invoice = Invoice::query()->with('contact')->findOrFail($this->invoiceId);
        $receipt = NfseReceipt::query()->findOrFail($this->receiptId);

        if ((int) $receipt->invoice_id !== $this->invoiceId) {
            throw new \RuntimeException('NFS-e receipt does not belong to the requested invoice.');
        }

        $payload = $receipt->payload()->firstOrCreate();

        if ($payload->post_emission_email_sent_at !== null) {
            return;
        }

        if (!$state->claimEmailProcessing($this->receiptId)) {
            // A different worker has already claimed or completed this send.
            return;
        }

        try {
            $sender->send(
                $invoice,
                $receipt,
                $this->attachDanfse,
                $this->attachXml,
                $this->customMail,
            );
            $state->markEmailCompleted($this->receiptId);
        } catch (\Throwable $throwable) {
            // SMTP exceptions can embed private Bcc recipients and transport credentials.
            $state->markEmailFailed($this->receiptId, 'Email delivery failed');

            // Queue workers persist exception messages in logs and failed_jobs.
            // Never chain or rethrow SMTP exceptions containing Bcc or credentials.
            throw new \RuntimeException('NFS-e email delivery failed.');
        }
    }
}

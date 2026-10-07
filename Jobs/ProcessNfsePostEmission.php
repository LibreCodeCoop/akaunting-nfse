<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Jobs;

use App\Models\Document\Document as Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Nfse\Application\NfseArtifactStorage;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Notifications\NfseIssued;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;

/**
 * Finishes work that does not need to block the fiscal authorization response.
 *
 * With QUEUE_CONNECTION=sync Laravel executes this job inline, preserving the
 * legacy deployment model. With an asynchronous driver (Redis, database, etc.)
 * the HTTP request returns after the receipt is persisted and this work is
 * performed by a queue worker.
 */
final class ProcessNfsePostEmission implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param array{
     *   attach_danfse:bool,
     *   attach_xml:bool,
     *   custom_mail:array<string,mixed>
     * }|null $email
     */
    public function __construct(
        public readonly int $invoiceId,
        public readonly int $receiptId,
        public readonly string $authorizedXml,
        public readonly ?array $email = null,
    ) {
        if ($invoiceId <= 0 || $receiptId <= 0) {
            throw new \InvalidArgumentException('Invoice and NFS-e receipt are required.');
        }

        $this->onQueue('default');
    }

    public function handle(): void
    {
        $artifactStorage = new NfseArtifactStorage();

        $invoice = Invoice::query()
            ->with(['contact', 'company'])
            ->find($this->invoiceId);
        $receipt = NfseReceipt::query()->find($this->receiptId);

        if (
            !$invoice instanceof Invoice
            || !$receipt instanceof NfseReceipt
            || (int) $receipt->invoice_id !== (int) $invoice->id
        ) {
            Log::warning('NFS-e post-emission job skipped because persisted records could not be resolved', [
                'invoice_id' => $this->invoiceId,
                'receipt_id' => $this->receiptId,
            ]);

            return;
        }

        $artifactStorage->store($invoice, $this->receiptData($receipt), $receipt);

        $freshReceipt = $receipt->fresh();
        $this->sendEmail(
            $invoice,
            $freshReceipt instanceof NfseReceipt ? $freshReceipt : $receipt,
        );
    }

    private function sendEmail(Invoice $invoice, NfseReceipt $receipt): void
    {
        if (!is_array($this->email)) {
            return;
        }

        $customMail = is_array($this->email['custom_mail'] ?? null)
            ? $this->email['custom_mail']
            : [];

        if (empty($customMail['to'])) {
            return;
        }

        try {
            $notification = new NfseIssued(
                invoice: $invoice,
                receipt: $receipt,
                attachDanfse: (bool) ($this->email['attach_danfse'] ?? true),
                attachXml: (bool) ($this->email['attach_xml'] ?? true),
                custom_mail: $customMail,
            );

            if ($invoice->contact !== null) {
                $invoice->contact->notify($notification);

                return;
            }

            Notification::route('mail', $customMail['to'])
                ->notify($notification);
        } catch (\Throwable $throwable) {
            Log::error('NFS-e post-emission email failed', [
                'invoice_id' => $this->invoiceId,
                'receipt_id' => $this->receiptId,
                'message' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }

    private function receiptData(NfseReceipt $receipt): ReceiptData
    {
        $issueDate = $receipt->data_emissao ?? null;
        $issueDateString = $issueDate instanceof \DateTimeInterface
            ? $issueDate->format(DATE_ATOM)
            : (is_scalar($issueDate) ? trim((string) $issueDate) : '');

        return new ReceiptData(
            nfseNumber: (string) ($receipt->nfse_number ?? ''),
            chaveAcesso: (string) ($receipt->chave_acesso ?? ''),
            dataEmissao: $issueDateString,
            codigoVerificacao: (string) ($receipt->codigo_verificacao ?? ''),
            rawXml: $this->authorizedXml !== '' ? $this->authorizedXml : null,
        );
    }
}

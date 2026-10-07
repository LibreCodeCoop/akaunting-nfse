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
use Modules\Nfse\Application\ArtifactPathBuilder;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Notifications\NfseIssued;
use Modules\Nfse\Support\WebDavClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Danfse\DanfseGenerator;
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

        $this->storeArtifacts($invoice, $receipt);
        $this->sendEmail($invoice, $receipt->fresh() ?? $receipt);
    }

    private function storeArtifacts(Invoice $invoice, NfseReceipt $receipt): void
    {
        if (!$this->webDavEnabled() || trim((string) ($receipt->chave_acesso ?? '')) === '') {
            return;
        }

        $webDav = $this->makeWebDavClient();
        $receiptData = $this->receiptData($receipt);
        $basePath = (new ArtifactPathBuilder())->basePath(
            template: (string) setting('nfse.webdav_path_template', 'nfse/{cnpj}/{year}/{month}/{day}'),
            cnpj: (string) setting('nfse.cnpj_prestador', 'unknown-cnpj'),
            customerName: (string) ($invoice->contact?->name ?? 'sem-cliente'),
            receipt: $receiptData,
        );

        $updates = [];

        if ($this->booleanSetting('nfse.webdav_store_xml', true) && $this->authorizedXml !== '') {
            try {
                $xmlPath = $this->artifactPath($basePath, $invoice, $receiptData, 'xml');
                $webDav->put($xmlPath, $this->authorizedXml);
                $updates['xml_webdav_path'] = $xmlPath;
            } catch (\Throwable $throwable) {
                $this->logFailure('XML', $throwable);
            }
        }

        if ($this->booleanSetting('nfse.webdav_store_pdf', true) && $this->authorizedXml !== '') {
            try {
                $pdf = (new DanfseGenerator())->generateFromXml($this->authorizedXml);

                if ($pdf === '' || !str_starts_with($pdf, '%PDF-')) {
                    throw new \RuntimeException('Generated DANFSE payload is not a PDF.');
                }

                $pdfPath = $this->artifactPath($basePath, $invoice, $receiptData, 'pdf');
                $webDav->put($pdfPath, $pdf);
                $updates['danfse_webdav_path'] = $pdfPath;
            } catch (\Throwable $throwable) {
                $this->logFailure('DANFSE', $throwable);
            }
        }

        if ($updates !== []) {
            $receipt->update($updates);
        }
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

            Notification::route('mail', (string) $customMail['to'])
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

    private function artifactPath(
        string $basePath,
        Invoice $invoice,
        ReceiptData $receipt,
        string $extension,
    ): string {
        return (new ArtifactPathBuilder())->filePath(
            basePath: $basePath,
            template: (string) setting('nfse.webdav_filename_template', '{chave_acesso}'),
            cnpj: (string) setting('nfse.cnpj_prestador', 'unknown-cnpj'),
            customerName: (string) ($invoice->contact?->name ?? 'sem-cliente'),
            receipt: $receipt,
            extension: $extension,
        );
    }

    private function webDavEnabled(): bool
    {
        return trim((string) setting('nfse.webdav_url', '')) !== '';
    }

    private function booleanSetting(string $key, bool $default): bool
    {
        $value = setting($key, null);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }

        return (bool) $value;
    }

    private function makeWebDavClient(): WebDavClient
    {
        return new WebDavClient(
            baseUrl: (string) setting('nfse.webdav_url', ''),
            username: (string) setting('nfse.webdav_username', ''),
            password: (string) setting('nfse.webdav_password', ''),
        );
    }

    private function logFailure(string $artifact, \Throwable $throwable): void
    {
        Log::error("NFS-e {$artifact} post-emission processing failed", [
            'invoice_id' => $this->invoiceId,
            'receipt_id' => $this->receiptId,
            'message' => $throwable->getMessage(),
        ]);
    }
}

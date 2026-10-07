<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document as Invoice;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\WebDavClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Danfse\DanfseGenerator;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;

class IssuedNfseArtifactStore
{
    public function store(int $invoiceId, int $receiptId): void
    {
        $invoice = Invoice::query()->with('contact')->findOrFail($invoiceId);
        $receipt = NfseReceipt::query()->findOrFail($receiptId);

        if ((int) $receipt->invoice_id !== $invoiceId) {
            throw new \RuntimeException('NFS-e receipt does not belong to the requested invoice.');
        }

        if (!$this->webDavEnabled() || trim((string) $receipt->chave_acesso) === '') {
            return;
        }

        $xml = trim((string) $receipt->payload()->value('authorized_xml'));

        if ($xml === '') {
            throw new \RuntimeException('Post-emission processing requires the authorized NFS-e XML.');
        }

        $client = $this->makeWebDavClient();
        $receiptData = $this->receiptData($receipt, $xml);
        $basePath = $this->buildBasePath($invoice, $receiptData);

        if ($this->storeXmlEnabled() && trim((string) $receipt->xml_webdav_path) === '') {
            $xmlPath = $this->buildFilePath($basePath, $invoice, $receiptData, 'xml');
            $client->put($xmlPath, $xml);
            $receipt->update(['xml_webdav_path' => $xmlPath]);
        }

        if ($this->storePdfEnabled() && trim((string) $receipt->danfse_webdav_path) === '') {
            $pdf = $this->generateDanfse($xml);

            if ($pdf === '' || !str_starts_with($pdf, '%PDF-')) {
                throw new \RuntimeException('Generated DANFSE payload is not a PDF.');
            }

            $pdfPath = $this->buildFilePath($basePath, $invoice, $receiptData, 'pdf');
            $client->put($pdfPath, $pdf);
            $receipt->update(['danfse_webdav_path' => $pdfPath]);
        }
    }

    protected function webDavEnabled(): bool
    {
        return trim((string) setting('nfse.webdav_url', '')) !== '';
    }

    protected function storeXmlEnabled(): bool
    {
        return (bool) setting('nfse.webdav_store_xml', true);
    }

    protected function storePdfEnabled(): bool
    {
        return (bool) setting('nfse.webdav_store_pdf', true);
    }

    protected function makeWebDavClient(): WebDavClient
    {
        return new WebDavClient(
            baseUrl: (string) setting('nfse.webdav_url', ''),
            username: (string) setting('nfse.webdav_username', ''),
            password: (string) setting('nfse.webdav_password', ''),
        );
    }

    protected function generateDanfse(string $authorizedXml): string
    {
        return (new DanfseGenerator())->generateFromXml($authorizedXml);
    }

    protected function buildBasePath(Invoice $invoice, ReceiptData $receipt): string
    {
        return (new ArtifactPathBuilder())->basePath(
            template: (string) setting('nfse.webdav_path_template', 'nfse/{cnpj}/{year}/{month}/{day}'),
            cnpj: (string) setting('nfse.cnpj_prestador', 'unknown-cnpj'),
            customerName: (string) ($invoice->contact?->name ?? 'sem-cliente'),
            receipt: $receipt,
        );
    }

    protected function buildFilePath(string $basePath, Invoice $invoice, ReceiptData $receipt, string $extension): string
    {
        return (new ArtifactPathBuilder())->filePath(
            basePath: $basePath,
            template: (string) setting('nfse.webdav_filename_template', '{chave_acesso}'),
            cnpj: (string) setting('nfse.cnpj_prestador', 'unknown-cnpj'),
            customerName: (string) ($invoice->contact?->name ?? 'sem-cliente'),
            receipt: $receipt,
            extension: $extension,
        );
    }

    protected function receiptData(NfseReceipt $receipt, string $rawXml): ReceiptData
    {
        $issueDate = $receipt->data_emissao;
        $issueDateString = $issueDate instanceof \DateTimeInterface
            ? $issueDate->format(DATE_ATOM)
            : trim((string) $issueDate);

        return new ReceiptData(
            nfseNumber: (string) ($receipt->nfse_number ?? ''),
            chaveAcesso: (string) ($receipt->chave_acesso ?? ''),
            dataEmissao: $issueDateString,
            codigoVerificacao: (string) ($receipt->codigo_verificacao ?? ''),
            rawXml: $rawXml,
        );
    }
}

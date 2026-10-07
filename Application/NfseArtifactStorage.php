<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document as Invoice;
use Illuminate\Support\Facades\Log;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\WebDavClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Danfse\DanfseGenerator;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;

final class NfseArtifactStorage
{
    /** @var \Closure():WebDavClient */
    private readonly \Closure $webDavFactory;

    /** @var \Closure(string):string */
    private readonly \Closure $danfseGenerator;

    /** @var \Closure(string,array<string,mixed>):void */
    private readonly \Closure $logError;

    /** @var \Closure(string,mixed):mixed */
    private readonly \Closure $settingResolver;

    /**
     * @param (\Closure():WebDavClient)|null $webDavFactory
     * @param (\Closure(string):string)|null $danfseGenerator
     * @param (\Closure(string,array<string,mixed>):void)|null $logError
     * @param (\Closure(string,mixed):mixed)|null $settingResolver
     */
    public function __construct(
        ?\Closure $webDavFactory = null,
        ?\Closure $danfseGenerator = null,
        ?\Closure $logError = null,
        ?\Closure $settingResolver = null,
    ) {
        $this->settingResolver = $settingResolver
            ?? static fn (string $key, mixed $default = null): mixed => setting($key, $default);

        $this->webDavFactory = $webDavFactory
            ?? fn (): WebDavClient => new WebDavClient(
                baseUrl: (string) $this->setting('nfse.webdav_url', ''),
                username: (string) $this->setting('nfse.webdav_username', ''),
                password: (string) $this->setting('nfse.webdav_password', ''),
            );

        $this->danfseGenerator = $danfseGenerator
            ?? static fn (string $xml): string => (new DanfseGenerator())->generateFromXml($xml);

        $this->logError = $logError
            ?? static function (string $message, array $context): void {
                Log::error($message, $context);
            };
    }

    public function store(Invoice $invoice, ReceiptData $receipt, NfseReceipt $persistedReceipt): void
    {
        if (!$this->webDavEnabled() || trim($receipt->chaveAcesso) === '') {
            return;
        }

        $webDav = ($this->webDavFactory)();
        $basePath = (new ArtifactPathBuilder())->basePath(
            template: (string) $this->setting('nfse.webdav_path_template', 'nfse/{cnpj}/{year}/{month}/{day}'),
            cnpj: (string) $this->setting('nfse.cnpj_prestador', 'unknown-cnpj'),
            customerName: (string) ($invoice->contact?->name ?? 'sem-cliente'),
            receipt: $receipt,
        );

        $updates = [];
        $xml = trim((string) ($receipt->rawXml ?? ''));

        if ($this->booleanSetting('nfse.webdav_store_xml', true) && $xml !== '') {
            try {
                $xmlPath = $this->artifactPath($basePath, $invoice, $receipt, 'xml');
                $webDav->put($xmlPath, $xml);
                $updates['xml_webdav_path'] = $xmlPath;
            } catch (\Throwable $throwable) {
                $this->logArtifactFailure('XML', $invoice, $persistedReceipt, $throwable);
            }
        }

        if ($this->booleanSetting('nfse.webdav_store_pdf', true) && $xml !== '') {
            try {
                $pdf = ($this->danfseGenerator)($xml);

                if ($pdf === '' || !str_starts_with($pdf, '%PDF-')) {
                    throw new \RuntimeException('Generated DANFSE payload is not a PDF.');
                }

                $pdfPath = $this->artifactPath($basePath, $invoice, $receipt, 'pdf');
                $webDav->put($pdfPath, $pdf);
                $updates['danfse_webdav_path'] = $pdfPath;
            } catch (\Throwable $throwable) {
                $this->logArtifactFailure('DANFSE', $invoice, $persistedReceipt, $throwable);
            }
        }

        if ($updates !== []) {
            $persistedReceipt->update($updates);
        }
    }

    private function artifactPath(
        string $basePath,
        Invoice $invoice,
        ReceiptData $receipt,
        string $extension,
    ): string {
        return (new ArtifactPathBuilder())->filePath(
            basePath: $basePath,
            template: (string) $this->setting('nfse.webdav_filename_template', '{chave_acesso}'),
            cnpj: (string) setting('nfse.cnpj_prestador', 'unknown-cnpj'),
            customerName: (string) ($invoice->contact?->name ?? 'sem-cliente'),
            receipt: $receipt,
            extension: $extension,
        );
    }

    private function webDavEnabled(): bool
    {
        return trim((string) $this->setting('nfse.webdav_url', '')) !== '';
    }

    private function booleanSetting(string $key, bool $default): bool
    {
        $value = $this->setting($key, null);

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

    private function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settingResolver)($key, $default);
    }

    private function logArtifactFailure(
        string $artifact,
        Invoice $invoice,
        NfseReceipt $receipt,
        \Throwable $throwable,
    ): void {
        ($this->logError)("NFS-e {$artifact} post-emission processing failed", [
            'invoice_id' => (int) $invoice->id,
            'receipt_id' => (int) ($receipt->id ?? 0),
            'chave_acesso' => (string) ($receipt->chave_acesso ?? ''),
            'message' => $throwable->getMessage(),
        ]);
    }
}

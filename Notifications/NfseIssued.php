<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Notifications;

use App\Abstracts\Notification;
use App\Models\Document\Document as Invoice;
use App\Models\Setting\EmailTemplate;
use Illuminate\Mail\Attachment;
use Illuminate\Notifications\Messages\MailMessage;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\WebDavClient;

class NfseIssued extends Notification
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly NfseReceipt $receipt,
        public readonly bool $attachDanfse = true,
        public readonly bool $attachXml = true,
        array $custom_mail = [],
    ) {
        parent::__construct();

        $this->template = EmailTemplate::alias('nfse_issued_customer')->first();
        $this->custom_mail = $custom_mail;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return [
            '{invoice_number}',
            '{nfse_number}',
            '{customer_name}',
            '{company_name}',
            '{nfse_issue_date}',
        ];
    }

    /**
     * @return list<string>
     */
    public function getTagsReplacement(): array
    {
        $issueDate = '';

        if ($this->receipt->data_emissao instanceof \DateTimeInterface) {
            $issueDate = $this->receipt->data_emissao->format('d/m/Y');
        } elseif ($this->receipt->data_emissao !== null) {
            $issueDate = (string) $this->receipt->data_emissao;
        }

        return [
            (string) ($this->invoice->document_number ?? ''),
            (string) ($this->receipt->nfse_number ?? ''),
            (string) ($this->invoice->contact_name ?? ''),
            (string) ($this->invoice->company?->name ?? ''),
            $issueDate,
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        if (!empty($this->custom_mail['to'])) {
            $notifiable->email = $this->custom_mail['to'];
        }

        $message = $this->initMailMessage();
        $this->attachArtifacts($message);

        return $message;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'invoice_number' => (string) ($this->invoice->document_number ?? ''),
            'nfse_number' => (string) ($this->receipt->nfse_number ?? ''),
        ];
    }

    protected function attachArtifacts(MailMessage $message): void
    {
        if ($this->attachDanfse && !empty($this->receipt->danfse_webdav_path)) {
            try {
                $content = $this->makeWebDavClient()->get((string) $this->receipt->danfse_webdav_path);

                if ($content !== '') {
                    $nfseNumber = (string) ($this->receipt->nfse_number ?? '');
                    $filename = 'nfse' . ($nfseNumber !== '' ? '-' . $nfseNumber : '') . '.pdf';
                    $message->attach(
                        Attachment::fromData(static fn (): string => $content, $filename)
                            ->withMime('application/pdf'),
                    );
                }
            } catch (\Throwable) {
                // Attachment failure must never block email delivery.
            }
        }

        if ($this->attachXml && !empty($this->receipt->xml_webdav_path)) {
            try {
                $content = $this->makeWebDavClient()->get((string) $this->receipt->xml_webdav_path);

                if ($content !== '') {
                    $nfseNumber = (string) ($this->receipt->nfse_number ?? '');
                    $filename = 'nfse' . ($nfseNumber !== '' ? '-' . $nfseNumber : '') . '.xml';
                    $message->attach(
                        Attachment::fromData(static fn (): string => $content, $filename)
                            ->withMime('application/xml'),
                    );
                }
            } catch (\Throwable) {
                // Attachment failure must never block email delivery.
            }
        }
    }

    protected function makeWebDavClient(): WebDavClient
    {
        return new WebDavClient(
            baseUrl: (string) setting('nfse.webdav_url', ''),
            username: (string) setting('nfse.webdav_username'),
            password: (string) setting('nfse.webdav_password'),
        );
    }
}

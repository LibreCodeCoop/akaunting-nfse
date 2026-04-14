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

        $this->template = EmailTemplate::alias('invoice_nfse_issued_customer')->first();
        $this->custom_mail = $custom_mail;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return [
            '{cnpj}',
            '{nfse_issue_year}',
            '{nfse_issue_month}',
            '{nfse_issue_day}',
            '{nfse_issue_month_name}',
            '{invoice_number}',
            '{nfse_number}',
            '{chave_acesso}',
            '{customer_name}',
            '{company_name}',
            '{company_contact_name}',
            '{company_contact_email}',
            '{company_contact_phone}',
            '{nfse_issue_date}',
        ];
    }

    /**
     * @return list<string>
     */
    public function getTagsReplacement(): array
    {
        $issueDateObject = $this->resolveIssueDate();
        $issueDate = '';
        $issueYear = '';
        $issueMonth = '';
        $issueDay = '';
        $issueMonthName = '';

        if ($issueDateObject instanceof \DateTimeInterface) {
            $issueDate = $issueDateObject->format('d/m/Y');
            $issueYear = $issueDateObject->format('Y');
            $issueMonth = $issueDateObject->format('m');
            $issueDay = $issueDateObject->format('d');
            $issueMonthName = $this->monthNameByNumber((int) $issueDateObject->format('n'));
        } elseif ($this->receipt->data_emissao !== null) {
            $issueDate = (string) $this->receipt->data_emissao;
        }

        return [
            $this->resolveCnpj(),
            $issueYear,
            $issueMonth,
            $issueDay,
            $issueMonthName,
            (string) ($this->invoice->document_number ?? ''),
            (string) ($this->receipt->nfse_number ?? ''),
            (string) ($this->receipt->chave_acesso ?? ''),
            (string) ($this->invoice->contact_name ?? ''),
            (string) ($this->invoice->company?->name ?? ''),
            $this->resolveCompanyContactField('name', (string) ($this->invoice->company?->name ?? '')),
            $this->resolveCompanyContactField('email', (string) ($this->invoice->company?->email ?? '')),
            $this->resolveCompanyContactField('phone', (string) ($this->invoice->company?->phone ?? '')),
            $issueDate,
        ];
    }

    protected function resolveCompanyContactField(string $field, string $fallback = ''): string
    {
        $contact = $this->resolveFirstCompanyContact();

        if ($contact === null) {
            return $fallback;
        }

        $value = '';

        if (is_object($contact) && isset($contact->{$field})) {
            $value = (string) $contact->{$field};
        } elseif (is_array($contact) && isset($contact[$field])) {
            $value = (string) $contact[$field];
        }

        return $value !== '' ? $value : $fallback;
    }

    protected function resolveFirstCompanyContact(): mixed
    {
        $company = $this->invoice->company ?? null;

        if (!is_object($company) || !isset($company->contacts)) {
            return null;
        }

        $contacts = $company->contacts;

        if ($contacts instanceof \Illuminate\Support\Collection) {
            return $contacts->first();
        }

        if (is_array($contacts)) {
            return $contacts[0] ?? null;
        }

        if ($contacts instanceof \Traversable) {
            foreach ($contacts as $contact) {
                return $contact;
            }

            return null;
        }

        if (is_object($contacts) && method_exists($contacts, 'first')) {
            return $contacts->first();
        }

        return null;
    }

    protected function resolveIssueDate(): ?\DateTimeInterface
    {
        if ($this->receipt->data_emissao instanceof \DateTimeInterface) {
            return $this->receipt->data_emissao;
        }

        if ($this->receipt->data_emissao === null || $this->receipt->data_emissao === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable((string) $this->receipt->data_emissao);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function monthNameByNumber(int $month): string
    {
        return match ($month) {
            1 => 'janeiro',
            2 => 'fevereiro',
            3 => 'marco',
            4 => 'abril',
            5 => 'maio',
            6 => 'junho',
            7 => 'julho',
            8 => 'agosto',
            9 => 'setembro',
            10 => 'outubro',
            11 => 'novembro',
            12 => 'dezembro',
            default => '',
        };
    }

    protected function resolveCnpj(): string
    {
        if (function_exists('setting')) {
            try {
                $providerCnpj = (string) setting('nfse.cnpj_prestador', '');

                if ($providerCnpj !== '') {
                    return $providerCnpj;
                }
            } catch (\Throwable) {
                // In isolated unit tests the setting container may not be bootstrapped.
            }
        }

        return (string) ($this->invoice->company?->tax_number ?? '');
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

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Jobs;

use App\Abstracts\Job;
use App\Models\Document\Document as Invoice;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Notifications\NfseIssued;

class SendNfseCustomEmail extends Job
{
    /**
     * @param  array<string, mixed>  $customMail
     */
    public function __construct(
        private readonly Invoice $invoice,
        private readonly ?NfseReceipt $receipt,
        private readonly bool $attachDanfse,
        private readonly bool $attachXml,
        private readonly array $customMail,
    ) {
        // Do not call parent::__construct() — the base Job boot methods
        // are not needed for this standalone email job.
    }

    public function handle(): void
    {
        $receipt = $this->receipt;

        if ($receipt === null || !$receipt->exists) {
            return;
        }

        $bcc = $this->customMail['bcc'] ?? null;

        $mailPayload = [
            'to'      => $this->customMail['to'] ?? [],
            'subject' => $this->customMail['subject'] ?? '',
            'body'    => $this->customMail['body'] ?? '',
        ];

        if ($bcc !== null && $bcc !== '') {
            $mailPayload['bcc'] = $bcc;
        }

        $notifiable = $this->invoice->contact;

        $toAddresses = (array) ($mailPayload['to'] ?? []);

        if ($notifiable !== null) {
            $contacts = $notifiable->withPersons();

            $counter = 1;

            foreach ($contacts as $contact) {
                if (!in_array($contact->email, $toAddresses, true)) {
                    continue;
                }

                $contactMail = $mailPayload;

                if ($counter > 1) {
                    unset($contactMail['bcc']);
                }

                $contact->notify(new NfseIssued(
                    $this->invoice,
                    $receipt,
                    $this->attachDanfse,
                    $this->attachXml,
                    $contactMail,
                ));

                $counter++;
            }

            return;
        }

        // No contact model — route via anonymous notifiable
        foreach ($toAddresses as $address) {
            $singleMail = array_merge($mailPayload, ['to' => $address]);

            \Illuminate\Support\Facades\Notification::route('mail', $address)
                ->notify(new NfseIssued(
                    $this->invoice,
                    $receipt,
                    $this->attachDanfse,
                    $this->attachXml,
                    $singleMail,
                ));
        }
    }
}

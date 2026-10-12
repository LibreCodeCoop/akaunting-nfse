<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document as Invoice;
use Illuminate\Support\Facades\Notification;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Notifications\NfseIssued;

class IssuedNfseEmailSender
{
    /**
     * @param array<string, mixed> $customMail
     */
    public function send(
        Invoice $invoice,
        NfseReceipt $receipt,
        bool $attachDanfse,
        bool $attachXml,
        array $customMail,
    ): void {
        $recipients = (new PostEmissionEmailRecipients())->normalize(
            $customMail['to'] ?? null,
            $customMail['cc'] ?? null,
            $customMail['bcc'] ?? null,
        );

        // Route a single native notification through one anonymous address.
        // The other visible recipients use MailMessage::cc; hidden recipients
        // use MailMessage::bcc. Never emit a separate message per contact.
        $primary = array_shift($recipients['to']);
        $recipients['cc'] = array_merge($recipients['to'], $recipients['cc']);
        $mail = array_merge($customMail, [
            'to' => $primary,
            'cc' => $recipients['cc'],
            'bcc' => $recipients['bcc'],
        ]);

        Notification::route('mail', $primary)
            ->notifyNow(new NfseIssued($invoice, $receipt, $attachDanfse, $attachXml, $mail));
    }
}

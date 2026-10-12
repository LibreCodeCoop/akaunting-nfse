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

        // Laravel's on-demand mail route accepts an array of To addresses.
        // Cc and Bcc are set by Akaunting's native MailMessage implementation.
        $mail = array_merge($customMail, [
            'to' => $recipients['to'],
            'cc' => $recipients['cc'],
            'bcc' => $recipients['bcc'],
        ]);

        Notification::route('mail', $recipients['to'])
            ->notifyNow(new NfseIssued($invoice, $receipt, $attachDanfse, $attachXml, $mail));
    }
}

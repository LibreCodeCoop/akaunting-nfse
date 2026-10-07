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
        $notifiable = $invoice->contact;

        if ($notifiable === null) {
            $recipient = trim((string) ($customMail['to'] ?? ''));

            if ($recipient === '') {
                return;
            }

            Notification::route('mail', $recipient)
                ->notify(new NfseIssued($invoice, $receipt, $attachDanfse, $attachXml, $customMail));

            return;
        }

        $notifiable->notify(new NfseIssued($invoice, $receipt, $attachDanfse, $attachXml, $customMail));
    }
}

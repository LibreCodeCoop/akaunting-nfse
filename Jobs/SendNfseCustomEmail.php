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

        try {
            (new \Modules\Nfse\Application\IssuedNfseEmailSender())->send(
                $this->invoice,
                $receipt,
                $this->attachDanfse,
                $this->attachXml,
                $this->customMail,
            );
        } catch (\Throwable) {
            // Errors may be serialized in queue logs; do not expose SMTP
            // failures containing private recipients or credentials.
            throw new \RuntimeException('NFS-e email delivery failed.');
        }
    }
}

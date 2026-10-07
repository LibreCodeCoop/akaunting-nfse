<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Support\Facades\Bus;
use Modules\Nfse\Jobs\SendIssuedNfseEmail;
use Modules\Nfse\Jobs\StoreIssuedNfseArtifacts;

final class PostEmissionDispatcher
{
    /**
     * Dispatch post-emission work through Laravel's configured queue.
     *
     * With QUEUE_CONNECTION=sync the chain runs in the current request, keeping
     * backward compatibility for installations without an asynchronous worker.
     * With redis/database/etc. it is consumed by the configured worker.
     *
     * @param array{
     *   attach_danfse:bool,
     *   attach_xml:bool,
     *   custom_mail:array<string, mixed>
     * }|null $email
     */
    public function dispatch(
        int $invoiceId,
        int $receiptId,
        string $authorizedXml,
        ?array $email = null,
    ): void {
        $jobs = [
            new StoreIssuedNfseArtifacts($invoiceId, $receiptId, $authorizedXml),
        ];

        if ($email !== null) {
            $jobs[] = new SendIssuedNfseEmail(
                invoiceId: $invoiceId,
                receiptId: $receiptId,
                attachDanfse: $email['attach_danfse'],
                attachXml: $email['attach_xml'],
                customMail: $email['custom_mail'],
            );
        }

        Bus::chain($jobs)->dispatch();
    }
}

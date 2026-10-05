<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NetworkException;

/**
 * Performs one mutable NFS-e issuance with deterministic DPS recovery.
 *
 * Business/protocol rejections propagate to the HTTP/queue adapter. Only an
 * ambiguous transport failure is handled here, because recovery is part of the
 * idempotency contract of the mutable POST itself.
 */
final class IssueInvoiceNfse
{
    public function __construct(
        private readonly RecoverInvoiceEmission $recovery = new RecoverInvoiceEmission(),
    ) {
    }

    public function issue(NfseClientInterface $client, DpsData $dps): ReceiptData
    {
        try {
            return $client->emit($dps);
        } catch (NetworkException $networkError) {
            $recovered = $this->recovery->recover($client, $dps);

            if ($recovered === null) {
                throw $networkError;
            }

            return $recovered;
        }
    }
}

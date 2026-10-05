<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;

/**
 * Executes an explicit fiscal cancellation and persists the authorized state.
 *
 * Protocol exceptions intentionally propagate to the HTTP adapter so it can
 * preserve the current user-facing error and idempotency policy.
 */
final class CancelInvoiceNfse
{
    public function cancel(
        NfseClientInterface $client,
        NfseReceipt $receipt,
        string $reason,
    ): void {
        $client->cancel($receipt->chave_acesso, $reason);
        $receipt->update(['status' => 'cancelled']);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Logical fiscal operation key, independent of transport or caller origin.
 *
 * The same persisted fiscal group requested via HTTP, a listener or a job
 * must have the same key. A new cancellation/replacement gets its own key.
 */
final class DpsOperationKey
{
    public static function forInvoice(
        int $companyId,
        int $invoiceId,
        int $environment,
        ?string $groupKey = null,
        ?int $cancelledReceiptId = null,
        ?int $substitutedReceiptId = null,
    ): string {
        if ($companyId <= 0 || $invoiceId <= 0) {
            throw new \InvalidArgumentException('Company and invoice IDs must be positive.');
        }

        if (!in_array($environment, [1, 2], true)) {
            throw new \InvalidArgumentException('Valid fiscal environment (1 or 2) is required.');
        }

        if ($cancelledReceiptId !== null && $substitutedReceiptId !== null) {
            throw new \InvalidArgumentException('Reissue and substitution are distinct fiscal operations.');
        }

        foreach ([$cancelledReceiptId, $substitutedReceiptId] as $receiptId) {
            if ($receiptId !== null && $receiptId <= 0) {
                throw new \InvalidArgumentException('Original fiscal receipt ID must be positive.');
            }
        }

        $groupKey = $groupKey === null ? null : trim($groupKey);

        if ($groupKey === '') {
            throw new \InvalidArgumentException('An explicitly supplied fiscal group must not be empty.');
        }

        return hash('sha256', json_encode([
            'v' => 1,
            'company' => $companyId,
            'invoice' => $invoiceId,
            'environment' => $environment,
            'group' => $groupKey,
            'cancelled_receipt' => $cancelledReceiptId,
            'substituted_receipt' => $substitutedReceiptId,
        ], JSON_THROW_ON_ERROR));
    }
}

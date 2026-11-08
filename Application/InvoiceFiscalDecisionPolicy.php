<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Pure fiscal-status separation. A receipt's status is never rewritten by
 * a financial-invoice operator classification.
 */
final class InvoiceFiscalDecisionPolicy
{
    public function mayClassify(string $decision, array $receiptStatuses): bool
    {
        if (!in_array($decision, ['do_not_issue', 'not_applicable'], true)) {
            return true;
        }

        foreach ($receiptStatuses as $status) {
            // Authorized NFS-e and unresolved DPS must be reviewed/recovered,
            // rather than hidden by a new invoice-level exclusion.
            if (in_array($status, ['emitted', 'pending', 'processing'], true)) {
                return false;
            }
        }

        return true;
    }

    public function effectiveDisplayStatus(?string $decision, ?string $latestReceiptStatus): string
    {
        if ($latestReceiptStatus !== null) {
            return $latestReceiptStatus;
        }

        return in_array($decision, ['do_not_issue', 'not_applicable'], true)
            ? $decision
            : 'pending';
    }
}

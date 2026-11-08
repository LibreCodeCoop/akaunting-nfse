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
            // Only conclusively cancelled or substituted local receipts can
            // coexist with a new operator decision. An unknown provider status
            // might still represent an authorized or unreconciled NFS-e.
            if (!in_array($status, ['cancelled', 'substituted'], true)) {
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

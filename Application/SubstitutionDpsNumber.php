<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Produces a stable DPS number for an NFS-e substitution attempt.
 *
 * The original receipt id is globally unique in the local database, so using it
 * as the stable input means retries of the same substitution address the same
 * DPS instead of creating a new remote emission identity.
 */
final class SubstitutionDpsNumber
{
    public function forOriginalReceipt(int $receiptId): string
    {
        if ($receiptId <= 0) {
            throw new \InvalidArgumentException('Original receipt id must be positive.');
        }

        $digits = (string) $receiptId;

        if (strlen($digits) > 14) {
            throw new \OverflowException('Original receipt id is too large for a stable 15-digit substitution DPS number.');
        }

        return '7' . str_pad($digits, 14, '0', STR_PAD_LEFT);
    }
}

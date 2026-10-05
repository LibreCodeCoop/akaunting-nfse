<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Models\NfseReceipt;

final class FiscalGroupReceiptState
{
    public function __construct(
        private readonly ReceiptPersistence $persistence = new ReceiptPersistence(),
    ) {
    }

    /**
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    public function annotate(int $invoiceId, array $groups): array
    {
        foreach ($groups as &$group) {
            $key = trim((string) ($group['key'] ?? ''));

            try {
                $receipt = $invoiceId > 0 && $key !== ''
                    ? $this->persistence->findGrouped($invoiceId, $key)
                    : null;
            } catch (\Throwable) {
                $receipt = null;
            }

            $group['issued'] = $receipt instanceof NfseReceipt;
            $group['receipt_id'] = $receipt?->id;
            $group['nfse_number'] = $receipt?->nfse_number;
        }
        unset($group);

        return $groups;
    }

    /**
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    public function remaining(int $invoiceId, array $groups): array
    {
        return array_values(array_filter(
            $this->annotate($invoiceId, $groups),
            static fn (array $group): bool => ($group['issued'] ?? false) !== true,
        ));
    }
}

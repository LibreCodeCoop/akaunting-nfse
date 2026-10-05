<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Models\NfseReceipt;

final class InvoiceFiscalReceiptResolver
{
    /**
     * @return array{primary:?NfseReceipt,receipts:list<NfseReceipt>}
     */
    public function resolve(int $invoiceId): array
    {
        if ($invoiceId <= 0) {
            return ['primary' => null, 'receipts' => []];
        }

        try {
            $collection = NfseReceipt::query()
                ->where('invoice_id', $invoiceId)
                ->latest('id')
                ->get();
        } catch (\Throwable) {
            return ['primary' => null, 'receipts' => []];
        }

        $receipts = [];

        foreach ($collection as $receipt) {
            if ($receipt instanceof NfseReceipt) {
                $receipts[] = $receipt;
            }
        }

        return [
            'primary' => $receipts[0] ?? null,
            'receipts' => $receipts,
        ];
    }
}

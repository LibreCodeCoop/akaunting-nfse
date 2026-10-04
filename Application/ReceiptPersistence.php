<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;

/**
 * Persists fiscal documents with explicit history semantics.
 */
final class ReceiptPersistence
{
    public function storeCurrent(
        int $invoiceId,
        ReceiptData $receipt,
        string $resolvedNumber,
        ?NfseReceipt $existingReceipt = null,
    ): NfseReceipt {
        $values = $this->receiptValues($receipt, $resolvedNumber);

        if ($existingReceipt instanceof NfseReceipt) {
            $existingReceipt->update($values);

            return $existingReceipt;
        }

        $current = NfseReceipt::query()
            ->where('invoice_id', $invoiceId)
            ->latest('id')
            ->first();

        if ($current instanceof NfseReceipt) {
            $current->update($values);

            return $current;
        }

        return NfseReceipt::query()->create(array_merge(
            ['invoice_id' => $invoiceId],
            $values,
        ));
    }

    public function createGrouped(
        int $invoiceId,
        ReceiptData $receipt,
        string $resolvedNumber,
        string $groupKey,
    ): NfseReceipt {
        return NfseReceipt::query()->create(array_merge(
            [
                'invoice_id' => $invoiceId,
                'emission_group_key' => $groupKey,
            ],
            $this->receiptValues($receipt, $resolvedNumber),
        ));
    }

    public function createReplacement(
        int $invoiceId,
        ReceiptData $receipt,
        string $resolvedNumber,
        NfseReceipt $original,
    ): NfseReceipt {
        return DB::transaction(function () use ($invoiceId, $receipt, $resolvedNumber, $original): NfseReceipt {
            $replacement = NfseReceipt::query()->create(array_merge(
                [
                    'invoice_id' => $invoiceId,
                    'replaces_receipt_id' => $original->id,
                ],
                $this->receiptValues($receipt, $resolvedNumber),
            ));

            $original->update(['status' => 'substituted']);

            return $replacement;
        });
    }

    /**
     * @return array{
     *   nfse_number:string,
     *   chave_acesso:string,
     *   data_emissao:string,
     *   codigo_verificacao:?string,
     *   status:string
     * }
     */
    private function receiptValues(ReceiptData $receipt, string $resolvedNumber): array
    {
        return [
            'nfse_number' => $resolvedNumber,
            'chave_acesso' => $receipt->chaveAcesso,
            'data_emissao' => $receipt->dataEmissao,
            'codigo_verificacao' => $receipt->codigoVerificacao,
            'status' => 'emitted',
        ];
    }
}

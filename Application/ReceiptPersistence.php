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
        }

        return NfseReceipt::updateOrCreate(
            ['invoice_id' => $invoiceId],
            $values,
        );
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
            $lockedOriginal = NfseReceipt::query()
                ->whereKey($original->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = NfseReceipt::query()
                ->where('replaces_receipt_id', $lockedOriginal->id)
                ->first();

            if ($existing instanceof NfseReceipt) {
                if ((string) $existing->chave_acesso !== $receipt->chaveAcesso) {
                    throw new \LogicException(
                        'Original NFS-e already has a different persisted replacement.',
                    );
                }

                if ($lockedOriginal->status !== 'substituted') {
                    $lockedOriginal->update(['status' => 'substituted']);
                }

                return $existing;
            }

            $replacement = NfseReceipt::query()->create(array_merge(
                [
                    'invoice_id' => $invoiceId,
                    'replaces_receipt_id' => $lockedOriginal->id,
                ],
                $this->receiptValues($receipt, $resolvedNumber),
            ));

            $lockedOriginal->update(['status' => 'substituted']);

            return $replacement;
        });
    }

    /**
     * @return array{
     *   nfse_number:string,
     *   chave_acesso:string,
     *   data_emissao:string,
     *   codigo_verificacao:?string,
     *   status:string,
     *   competence_date?:?string,
     *   authorized_fiscal_snapshot?:array<string, mixed>
     * }
     */
    private function receiptValues(ReceiptData $receipt, string $resolvedNumber): array
    {
        $values = [
            'nfse_number' => $resolvedNumber,
            'chave_acesso' => $receipt->chaveAcesso,
            'data_emissao' => $receipt->dataEmissao,
            'codigo_verificacao' => $receipt->codigoVerificacao,
            'status' => 'emitted',
        ];

        if (is_string($receipt->rawXml) && trim($receipt->rawXml) !== '') {
            $snapshot = (new AuthorizedFiscalSnapshotExtractor())->extract($receipt->rawXml);
            $values['competence_date'] = $snapshot['competence_date'];
            $values['authorized_fiscal_snapshot'] = $snapshot;
        }

        return $values;
    }
}

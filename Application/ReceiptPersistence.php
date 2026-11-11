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
        if (trim($receipt->chaveAcesso) === '' || trim($resolvedNumber) === '') {
            throw new \InvalidArgumentException('An authorized NFS-e requires an access key and number.');
        }

        return DB::transaction(function () use ($invoiceId, $receipt, $resolvedNumber, $existingReceipt): NfseReceipt {
            // Serialize changes even when no matching receipt exists yet.
            $invoice = DB::table('documents')->where('id', $invoiceId)->lockForUpdate()->first();
            if ($invoice === null || (string) $invoice->type !== 'invoice') {
                throw new \LogicException('Fiscal receipt must reference an existing invoice.');
            }

            $persisted = $existingReceipt instanceof NfseReceipt
                ? NfseReceipt::query()->whereKey($existingReceipt->id)->lockForUpdate()->firstOrFail()
                : NfseReceipt::query()
                    ->where('invoice_id', $invoiceId)
                    ->where('chave_acesso', $receipt->chaveAcesso)
                    ->lockForUpdate()
                    ->first();

            if ($persisted instanceof NfseReceipt) {
                if ((int) $persisted->invoice_id !== $invoiceId
                    || (string) $persisted->chave_acesso !== $receipt->chaveAcesso
                    || (string) $persisted->nfse_number !== $resolvedNumber) {
                    throw new \LogicException(
                        'An existing NFS-e has a different fiscal identity; reconcile without overwriting it.',
                    );
                }

                if ((string) $persisted->status !== 'emitted') {
                    throw new \LogicException('Cancelled or substituted NFS-e cannot be reopened by a retry.');
                }

                $persisted->update($this->receiptValues($receipt, $resolvedNumber));
            } else {
                // A new issuance cannot replace a live receipt. Reissuance
                // after cancellation creates a separate historical row.
                $conflict = NfseReceipt::query()
                    ->where('invoice_id', $invoiceId)
                    ->whereNotIn('status', ['cancelled', 'substituted'])
                    ->exists();

                if ($conflict) {
                    throw new \LogicException(
                        'Invoice already has a different fiscal receipt; reconcile before another issuance.',
                    );
                }

                $persisted = NfseReceipt::query()->create([
                    'invoice_id' => $invoiceId,
                    ...$this->receiptValues($receipt, $resolvedNumber),
                ]);
            }

            $this->persistAuthorizedXml($persisted, $receipt);

            return $persisted->fresh();
        }, 3);
    }

    public function createGrouped(
        int $invoiceId,
        ReceiptData $receipt,
        string $resolvedNumber,
        string $groupKey,
    ): NfseReceipt {
        return DB::transaction(function () use ($invoiceId, $receipt, $resolvedNumber, $groupKey): NfseReceipt {
            DB::table('documents')
                ->where('id', $invoiceId)
                ->lockForUpdate()
                ->first();

            $existing = $this->findGrouped($invoiceId, $groupKey);

            if ($existing instanceof NfseReceipt) {
                if ((string) $existing->chave_acesso !== $receipt->chaveAcesso) {
                    throw new \LogicException(
                        'Fiscal emission group already has a different persisted NFS-e.',
                    );
                }

                $existing->update($this->receiptValues($receipt, $resolvedNumber));
                $fresh = $existing->fresh();
                $this->persistAuthorizedXml($fresh, $receipt);

                return $fresh;
            }

            $created = NfseReceipt::query()->create(array_merge(
                [
                    'invoice_id' => $invoiceId,
                    'emission_group_key' => $groupKey,
                ],
                $this->receiptValues($receipt, $resolvedNumber),
            ));

            $this->persistAuthorizedXml($created, $receipt);

            return $created;
        });
    }

    public function findGrouped(int $invoiceId, string $groupKey): ?NfseReceipt
    {
        $receipt = NfseReceipt::query()
            ->where('invoice_id', $invoiceId)
            ->where('emission_group_key', $groupKey)
            ->first();

        if ($receipt instanceof NfseReceipt) {
            return $this->currentGroupReceipt($receipt);
        }

        // A fiscal item may acquire its NBS after an earlier grouped invoice
        // was authorized. Its new signature must not appear pending merely
        // because old receipt keys did not contain |nbs:<code>.
        $legacyKey = preg_replace('/\|nbs:\d{9}$/D', '', $groupKey) ?? $groupKey;
        if ($legacyKey === $groupKey) {
            return null;
        }

        $legacyReceipt = NfseReceipt::query()
            ->where('invoice_id', $invoiceId)
            ->where('emission_group_key', $legacyKey)
            ->first();

        return $legacyReceipt instanceof NfseReceipt ? $this->currentGroupReceipt($legacyReceipt) : null;
    }

    private function currentGroupReceipt(NfseReceipt $original): NfseReceipt
    {
        if ((string) $original->status !== 'substituted') {
            return $original;
        }

        $replacement = NfseReceipt::query()
            ->where('replaces_receipt_id', (int) $original->id)
            ->where('invoice_id', (int) $original->invoice_id)
            ->first();

        return $replacement instanceof NfseReceipt ? $replacement : $original;
    }

    /**
     * Reemission after cancellation is a NEW fiscal document, never a mutation
     * of the cancelled receipt. This preserves both the original fiscal ledger
     * and historical attempt -> receipt links.
     */
    public function createReemitted(
        int $invoiceId,
        ReceiptData $receipt,
        string $resolvedNumber,
    ): NfseReceipt {
        return DB::transaction(function () use ($invoiceId, $receipt, $resolvedNumber): NfseReceipt {
            DB::table('documents')->where('id', $invoiceId)->lockForUpdate()->first();

            $existing = NfseReceipt::query()
                ->where('invoice_id', $invoiceId)
                ->where('chave_acesso', $receipt->chaveAcesso)
                ->first();

            if ($existing instanceof NfseReceipt) {
                $existing->update($this->receiptValues($receipt, $resolvedNumber));
                $updated = $existing->fresh();
                $this->persistAuthorizedXml($updated, $receipt);

                return $updated;
            }

            $created = NfseReceipt::query()->create([
                'invoice_id' => $invoiceId,
                ...$this->receiptValues($receipt, $resolvedNumber),
            ]);

            $this->persistAuthorizedXml($created, $receipt);

            return $created;
        });
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

                $existing->update($this->receiptValues($receipt, $resolvedNumber));

                if ($lockedOriginal->status !== 'substituted') {
                    $lockedOriginal->update(['status' => 'substituted']);
                }

                $fresh = $existing->fresh();
                $this->persistAuthorizedXml($fresh, $receipt);

                return $fresh;
            }

            $replacement = NfseReceipt::query()->create(array_merge(
                [
                    'invoice_id' => $invoiceId,
                    'replaces_receipt_id' => $lockedOriginal->id,
                ],
                $this->receiptValues($receipt, $resolvedNumber),
            ));

            $lockedOriginal->update(['status' => 'substituted']);
            $this->persistAuthorizedXml($replacement, $receipt);

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

    private function persistAuthorizedXml(NfseReceipt $receipt, ReceiptData $remoteReceipt): void
    {
        $xml = is_string($remoteReceipt->rawXml) ? $remoteReceipt->rawXml : '';

        if (trim($xml) === '') {
            return;
        }

        $previous = (string) ($receipt->payload()->value('authorized_xml') ?? '');
        if ($previous !== '') {
            if (!hash_equals(hash('sha256', $previous), hash('sha256', $xml))) {
                throw new \LogicException('Authorized XML differs from the archived NFS-e; reconcile manually.');
            }

            // Idempotent recovery must not re-enable an email already sent.
            return;
        }

        $receipt->payload()->updateOrCreate(
            [],
            [
                'authorized_xml' => $xml,
                'post_emission_email_sent_at' => null,
            ],
        );
    }
}

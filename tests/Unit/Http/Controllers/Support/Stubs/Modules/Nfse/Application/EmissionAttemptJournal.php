<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application {
    // Unit controller isolation replaces the database-backed journal; real
    // journal persistence and idempotency have feature-level coverage.
    if (!class_exists(EmissionAttemptJournal::class, false)) {
        final class EmissionAttemptJournal
        {
            public function issue(
                \Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface $client,
                \Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData $dps,
                int $invoiceId,
                string $origin,
                callable $persist,
                ?string $groupKey = null,
                ?callable $transmit = null,
            ): array {
                $remote = $transmit !== null
                    ? $transmit()
                    : (new IssueInvoiceNfse())->issue($client, $dps);

                if ($origin === 'reemit') {
                    // The real reissue creates another row, not a mutation of
                    // the cancelled receipt; unit controller isolation has
                    // no Eloquent/database runtime.
                    $receipt = new \Modules\Nfse\Models\NfseReceipt();
                    $receipt->id = count(\Modules\Nfse\Models\NfseReceipt::$records) + 1;
                    $receipt->invoice_id = $invoiceId;
                    $receipt->nfse_number = $remote->nfseNumber;
                    $receipt->chave_acesso = $remote->chaveAcesso;
                    $receipt->data_emissao = $remote->dataEmissao;
                    $receipt->codigo_verificacao = $remote->codigoVerificacao;
                    $receipt->status = 'emitted';
                    \Modules\Nfse\Models\NfseReceipt::$records[] = $receipt;
                } else {
                    $receipt = $persist($remote);
                }

                return [
                    'receipt' => $receipt,
                    'remote_receipt' => $remote,
                    'reused' => false,
                ];
            }
        }
    }
}

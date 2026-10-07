<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;

final class RefreshInvoiceNfse
{
    public function __construct(
        private readonly ReceiptNumberResolver $numberResolver = new ReceiptNumberResolver(),
    ) {
    }

    public function refresh(NfseClientInterface $client, NfseReceipt $receipt): ReceiptData
    {
        $updated = $client->query($receipt->chave_acesso);

        $values = [
            'nfse_number' => $this->numberResolver->resolve($updated),
            'chave_acesso' => $updated->chaveAcesso,
            'data_emissao' => $updated->dataEmissao,
            'codigo_verificacao' => $updated->codigoVerificacao,
            'status' => 'emitted',
        ];

        DB::transaction(function () use ($receipt, $updated, $values): void {
            $receipt->update($values);

            if (is_string($updated->rawXml) && trim($updated->rawXml) !== '') {
                $receipt->payload()->updateOrCreate(
                    [],
                    ['authorized_xml' => trim($updated->rawXml)],
                );
            }
        });

        return $updated;
    }
}

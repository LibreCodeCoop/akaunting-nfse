<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

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

        $receipt->update([
            'nfse_number' => $this->numberResolver->resolve($updated),
            'chave_acesso' => $updated->chaveAcesso,
            'data_emissao' => $updated->dataEmissao,
            'codigo_verificacao' => $updated->codigoVerificacao,
            'status' => 'emitted',
        ]);

        return $updated;
    }
}

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
        $incomingXml = is_string($updated->rawXml) ? $updated->rawXml : '';
        $storedXml = '';
        $newSnapshot = null;

        if (trim($incomingXml) !== '') {
            $storedXml = (string) $receipt->payload()->value('authorized_xml');

            // A read-only refresh must not rewrite a previously authorized
            // fiscal document, even when the remote response differs.
            if ($storedXml !== '' && !hash_equals(hash('sha256', $storedXml), hash('sha256', $incomingXml))) {
                throw new \LogicException('Authorized NFS-e XML differs from the archived original; manual reconciliation required.');
            }

            if ($storedXml === '') {
                $newSnapshot = (new AuthorizedFiscalSnapshotExtractor())->extract($incomingXml);
            }
        }

        $values = [
            'nfse_number' => $this->numberResolver->resolve($updated),
            'chave_acesso' => $updated->chaveAcesso,
            'data_emissao' => $updated->dataEmissao,
            'codigo_verificacao' => $updated->codigoVerificacao,
            'status' => 'emitted',
        ];

        if ($newSnapshot !== null) {
            $values['authorized_fiscal_snapshot'] = $newSnapshot;
            $values['competence_date'] = $newSnapshot['competence_date'];
        }

        $receipt->update($values);

        if ($newSnapshot !== null) {
            $receipt->payload()->updateOrCreate([], ['authorized_xml' => $incomingXml]);
        }

        return $updated;
    }
}

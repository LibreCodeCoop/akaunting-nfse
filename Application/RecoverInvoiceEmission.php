<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;

/**
 * Recovers an ambiguous issuance result through the immutable DPS identifier.
 *
 * Error handling/logging belongs to the calling adapter. This use case lets
 * protocol/query failures propagate so they cannot be mistaken for "not found".
 */
final class RecoverInvoiceEmission
{
    public function recover(NfseClientInterface $client, DpsData $dps): ?ReceiptData
    {
        if (!is_callable([$client, 'queryDps'])) {
            return null;
        }

        $accessKey = call_user_func([$client, 'queryDps'], $this->dpsIdentifier($dps));

        if (!is_string($accessKey) || trim($accessKey) === '') {
            return null;
        }

        return $client->query(trim($accessKey));
    }

    public function dpsIdentifier(DpsData $dps): string
    {
        return $dps->municipioIbge
            . '2'
            . strtoupper($dps->cnpjPrestador)
            . str_pad($dps->serie, 5, '0', STR_PAD_LEFT)
            . str_pad($dps->numeroDps, 15, '0', STR_PAD_LEFT);
    }
}

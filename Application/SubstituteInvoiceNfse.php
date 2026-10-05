<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NetworkException;

/**
 * Issues an official replacement DPS while preserving ambiguous-POST recovery.
 */
final class SubstituteInvoiceNfse
{
    public function __construct(
        private readonly RecoverInvoiceEmission $recovery = new RecoverInvoiceEmission(),
    ) {
    }

    public function issue(
        NfseClientInterface $client,
        DpsData $replacementDps,
        string $originalAccessKey,
    ): ReceiptData {
        $substitution = $replacementDps->substituicao;

        if ($substitution === null) {
            throw new \InvalidArgumentException('Replacement DPS must contain official substitution data.');
        }

        $originalAccessKey = trim($originalAccessKey);

        if ($substitution->chaveNfseSubstituida !== $originalAccessKey) {
            throw new \InvalidArgumentException(
                'Replacement DPS substitution reference does not match the original NFS-e access key.',
            );
        }

        try {
            return $client->emit($replacementDps);
        } catch (NetworkException $networkError) {
            $recovered = $this->recovery->recover($client, $replacementDps);

            if ($recovered === null) {
                throw $networkError;
            }

            return $recovered;
        }
    }
}

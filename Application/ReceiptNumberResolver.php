<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;

final class ReceiptNumberResolver
{
    public function resolve(ReceiptData $receipt): string
    {
        $numberFromGateway = trim($receipt->nfseNumber);

        if ($numberFromGateway !== '') {
            return $numberFromGateway;
        }

        return $this->fromAuthorizedXml($receipt->rawXml) ?? '';
    }

    public function fromAuthorizedXml(?string $rawXml): ?string
    {
        if (!is_string($rawXml) || trim($rawXml) === '') {
            return null;
        }

        if (preg_match('/<(?:\\w+:)?nNFSe>\\s*([^<]+?)\\s*<\\/(?:\\w+:)?nNFSe>/u', $rawXml, $matches) !== 1) {
            return null;
        }

        $parsedNumber = trim((string) ($matches[1] ?? ''));

        return $parsedNumber !== '' ? $parsedNumber : null;
    }
}

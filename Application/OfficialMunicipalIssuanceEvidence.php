<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\IssuanceException;

/**
 * Issuance evidence can only be instantiated from the gateway adapter's
 * typed response, not from an arbitrary HTTP request or persisted JSON.
 *
 * Factories must be invoked after the caller binds the submitted DPS identity
 * to its immutable fiscal context. This class does not perform network I/O.
 */
final readonly class OfficialMunicipalIssuanceEvidence
{
    /** @param array<string,mixed> $attributes */
    private function __construct(private array $attributes)
    {
    }

    /**
     * @param array<string,mixed> $submittedContext Actual final DPS context.
     */
    public static function fromRejection(
        IssuanceException $exception,
        array $submittedContext,
        string $environment,
        \DateTimeImmutable $observedAt,
    ): ?self {
        $official = (new OfficialIssuanceRejection())->fromException($exception);
        if ($official === null) {
            return null;
        }

        return new self([
            'kind' => 'sefin_issuance_result',
            'source_url' => self::endpoint($environment),
            'consulted_at' => $observedAt->format(DATE_ATOM),
            'http_status' => $official['http_status'],
            'outcome' => 'rejected',
            'error_code' => $official['code'],
            'context' => $submittedContext,
            'verified_via' => 'issuer_exception',
        ]);
    }

    /**
     * A typed ReceiptData result is observed *after* issuance/reconciliation.
     * The SDK does not expose its success HTTP code, so it remains unknown.
     *
     * @param array<string,mixed> $submittedContext Actual final DPS context.
     */
    public static function fromReceipt(
        ReceiptData $receipt,
        array $submittedContext,
        string $environment,
        \DateTimeImmutable $observedAt,
    ): ?self {
        if (trim($receipt->chaveAcesso) === '' || trim($receipt->nfseNumber) === '') {
            return null;
        }

        return new self([
            'kind' => 'sefin_issuance_result',
            'source_url' => self::endpoint($environment),
            'consulted_at' => $observedAt->format(DATE_ATOM),
            'http_status' => null,
            'outcome' => 'authorized',
            'access_key' => $receipt->chaveAcesso,
            'context' => $submittedContext,
            'verified_via' => 'issuer_receipt',
        ]);
    }

    /** @return array<string,mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    private static function endpoint(string $environment): string
    {
        return match ($environment) {
            'production' => 'https://sefin.nfse.gov.br/SefinNacional/nfse',
            'sandbox' => 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse',
            default => '',
        };
    }
}

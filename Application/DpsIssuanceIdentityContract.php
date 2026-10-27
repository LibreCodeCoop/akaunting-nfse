<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;

/**
 * Stable consumer contract for individual, grouped/batch and API issuance.
 * There is one production policy; synthetic tests can exercise its boundary
 * without a real fiscal transport or provisioning a shared database.
 */
interface DpsIssuanceIdentityContract
{
    /** @return array{dps:DpsData,reservation_id:int,operation_key:string} */
    public function prepare(
        int $invoiceId,
        DpsData $draft,
        string $origin,
        ?string $groupKey = null,
        ?int $cancelledReceiptId = null,
        ?int $substitutedReceiptId = null,
    ): array;

    public function assertLocal(int $companyId, int $invoiceId, int $reservationId, DpsData $dps): object;

    public function claimFirstPost(
        int $companyId,
        int $invoiceId,
        int $reservationId,
        DpsData $dps,
        string $fingerprint,
    ): void;
}

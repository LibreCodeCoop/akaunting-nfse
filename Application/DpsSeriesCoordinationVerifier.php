<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Independent verification contract. Local status and reference are NOT proof.
 * Production providers must verify exclusive series ownership, freshness,
 * high-water mark and fencing of other installations including stale restores.
 * There is deliberately no permissive default implementation.
 */
interface DpsSeriesCoordinationVerifier
{
    public function assertCanReserve(
        DpsFiscalIssuer $issuer,
        int $environment,
        string $series,
        string $mode,
        string $reference,
        int $lastNumber,
    ): void;
}

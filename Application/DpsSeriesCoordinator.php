<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Atomically allocates an immutable DPS number in a durable store shared by
 * every participating installation. A read-only verifier is NOT sufficient.
 *
 * The implementation must fence revoked installation generations inside the
 * same transaction that advances the authoritative fiscal series.
 */
interface DpsSeriesCoordinator
{
    /**
     * @return array{number:int,fresh:bool} Fresh=false means the remote
     *         allocation already exists; a missing local journal then requires
     *         reconciliation rather than recreation or a first POST.
     */
    public function allocate(
        DpsFiscalIssuer $issuer,
        int $environment,
        string $series,
        string $reference,
        string $operationKey,
    ): array;

    /**
     * Irrevocably claim the ONLY first fiscal POST for a reserved operation.
     * An uncertain commit MUST be reconciled; calling again must not grant
     * another POST, even for an identical payload or a new process.
     */
    public function claimFirstTransmission(
        DpsFiscalIssuer $issuer,
        int $environment,
        string $series,
        string $reference,
        string $operationKey,
        int $number,
        string $payloadFingerprint,
    ): void;

    /** Read-only check for a locally persisted, immutable reservation. */
    public function assertExisting(
        DpsFiscalIssuer $issuer,
        int $environment,
        string $series,
        string $reference,
        string $operationKey,
        int $number,
    ): void;
}

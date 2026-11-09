<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Walk only previously confirmed E1235 rejections of the SAME operation.
 *
 * A rejected DPS has consumed its coordinated identity; a new user-initiated
 * substitution may use a fresh authority allocation after schema correction.
 * Pending/ambiguous/authorized/technical outcomes must retain their original
 * immutable identity for read-only recovery and idempotency.
 */
final class RejectedSubstitutionOperationKey
{
    private const MAX_REVISIONS = 32;

    /**
     * @param callable(string):(?array{id:int,status:string,official_code:?string,http_status:?int}) $lastAttempt
     * @param callable(int):string $keyAfterRejection
     */
    public function resolve(string $baseKey, callable $lastAttempt, callable $keyAfterRejection): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $baseKey) !== 1) {
            throw new \InvalidArgumentException('A valid original fiscal operation key is required.');
        }

        $current = $baseKey;
        $seen = [];
        for ($revision = 0; $revision < self::MAX_REVISIONS; $revision++) {
            if (isset($seen[$current])) {
                throw new \LogicException('Fiscal operation revision chain contains a cycle.');
            }
            $seen[$current] = true;

            $attempt = $lastAttempt($current);
            if (!$this->isCorrectableSchemaRejection($attempt)) {
                return $current;
            }

            $next = $keyAfterRejection((int) $attempt['id']);
            if (preg_match('/^[a-f0-9]{64}$/D', $next) !== 1 || hash_equals($current, $next)) {
                throw new \LogicException('A rejected DPS requires a distinct, valid fiscal operation key.');
            }
            $current = $next;
        }

        throw new \LogicException('Fiscal operation has exceeded the reviewed rejection revision limit.');
    }

    /** @param array<string,mixed>|null $attempt */
    private function isCorrectableSchemaRejection(?array $attempt): bool
    {
        return is_array($attempt)
            && is_numeric($attempt['id'] ?? null)
            && (int) $attempt['id'] > 0
            && ($attempt['status'] ?? null) === EmissionAttemptJournal::REJECTED
            && ($attempt['official_code'] ?? null) === 'E1235'
            && in_array((int) ($attempt['http_status'] ?? 0), [400, 422], true);
    }
}

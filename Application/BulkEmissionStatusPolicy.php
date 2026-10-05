<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Defines legal per-unit transitions and aggregate run state for bulk emission.
 */
final class BulkEmissionStatusPolicy
{
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const ISSUED = 'issued';
    public const BLOCKED = 'blocked';
    public const REJECTED = 'rejected';
    public const RETRYABLE_READ_ERROR = 'retryable_read_error';

    /**
     * @return list<string>
     */
    public function allowedTargets(string $current): array
    {
        return match ($current) {
            self::QUEUED => [
                self::PROCESSING,
                self::BLOCKED,
                self::ISSUED,
            ],
            self::PROCESSING => [
                self::ISSUED,
                self::BLOCKED,
                self::REJECTED,
                self::RETRYABLE_READ_ERROR,
            ],
            self::RETRYABLE_READ_ERROR => [
                self::PROCESSING,
                self::ISSUED,
                self::REJECTED,
            ],
            self::ISSUED, self::BLOCKED, self::REJECTED => [],
            default => [],
        };
    }

    public function canTransition(string $current, string $target): bool
    {
        return in_array($target, $this->allowedTargets($current), true);
    }

    /**
     * @param list<string> $unitStatuses
     */
    public function aggregate(array $unitStatuses): string
    {
        if ($unitStatuses === []) {
            return self::QUEUED;
        }

        if (in_array(self::PROCESSING, $unitStatuses, true)) {
            return self::PROCESSING;
        }

        if (in_array(self::QUEUED, $unitStatuses, true)) {
            return self::QUEUED;
        }

        if (count(array_unique($unitStatuses)) === 1) {
            return $unitStatuses[0];
        }

        if (in_array(self::RETRYABLE_READ_ERROR, $unitStatuses, true)) {
            return 'partial_retryable';
        }

        if (in_array(self::REJECTED, $unitStatuses, true)) {
            return 'partial_rejected';
        }

        if (in_array(self::BLOCKED, $unitStatuses, true)) {
            return 'partial_blocked';
        }

        return 'completed';
    }

    public function isTerminal(string $status): bool
    {
        return in_array($status, [
            self::ISSUED,
            self::BLOCKED,
            self::REJECTED,
        ], true);
    }
}

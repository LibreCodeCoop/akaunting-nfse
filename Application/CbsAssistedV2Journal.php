<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Pure incremental materialization policy. The caller owns append-only storage
 * of every received source file, per-company ACL, and durable cursor identity.
 *
 * Never infer a full-period balance by adding response rows. A later update
 * replaces the current observation of the same stable key only after explicit
 * provenance and timestamp checks. Missing keys and conflicting revisions
 * remain unresolved for review, not written as authoritative amounts.
 */
final class CbsAssistedV2Journal
{
    /**
     * @param array<string,array<string,mixed>> $known Current observations by identity_key.
     * @param array<string,mixed> $batch One response from CbsAssistedV2IncrementalReader.
     * @return array{
     *   observations:array<string,array<string,mixed>>,
     *   unresolved:list<array<string,mixed>>,
     *   conflicts:list<array<string,mixed>>,
     *   applied:int,unchanged:int,stale:int
     * }
     */
    public function materialize(array $known, array $batch): array
    {
        if (($batch['incremental'] ?? null) !== true
            || ($batch['complete_period'] ?? null) !== false
            || !is_array($batch['events'] ?? null)
            || !is_string($batch['source_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $batch['source_sha256']) !== 1) {
            throw new \DomainException('Invalid CBS incremental file contract.');
        }

        $provenance = $batch['source_provenance'] ?? 'unverified';
        if (!is_string($provenance)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $provenance) !== 1) {
            throw new \DomainException('Invalid CBS source provenance label.');
        }

        $unresolved = [];
        $conflicts = [];
        $applied = 0;
        $unchanged = 0;
        $stale = 0;

        foreach ($batch['events'] as $event) {
            if (!is_array($event)) {
                throw new \DomainException('Invalid CBS update.');
            }

            $id = $event['identity_key'] ?? null;
            $stamp = $event['updated_at'] ?? null;
            if ($id === null) {
                $unresolved[] = $event;
                continue;
            }
            if (!is_string($id) || preg_match('/^[0-9a-f]{64}$/D', $id) !== 1
                || !is_string($stamp)) {
                throw new \DomainException('CBS update lacks trustworthy identity metadata.');
            }

            // Check the first observation too: malformed calendar dates must
            // not enter a journal and break subsequent revision comparisons.
            $now = $this->timestamp($stamp);
            $candidate = $event;
            // An event must never override the enclosing source provenance.
            $candidate['source_sha256'] = $batch['source_sha256'];
            $candidate['source_provenance'] = $provenance;
            if (!isset($known[$id])) {
                $known[$id] = $candidate;
                ++$applied;
                continue;
            }

            $previous = $known[$id];
            $before = $this->timestamp($previous['updated_at'] ?? null);
            if ($now < $before) {
                ++$stale;
                continue;
            }
            if ($now == $before) {
                if ($this->comparable($previous) === $this->comparable($candidate)) {
                    ++$unchanged;
                } else {
                    $conflicts[] = [
                        'identity_key' => $id,
                        'previous_source_sha256' => $previous['source_sha256'] ?? '',
                        'incoming_source_sha256' => $batch['source_sha256'],
                        'reason' => 'same_timestamp_conflicting_values',
                    ];
                }
                continue;
            }

            $known[$id] = $candidate;
            ++$applied;
        }

        return [
            'observations' => $known,
            'unresolved' => $unresolved,
            'conflicts' => $conflicts,
            'applied' => $applied,
            'unchanged' => $unchanged,
            'stale' => $stale,
        ];
    }

    /** @param array<string,mixed> $observation */
    private function comparable(array $observation): array
    {
        unset($observation['source_sha256'], $observation['source_provenance']);

        ksort($observation);

        return $observation;
    }

    private function timestamp(mixed $value): \DateTimeImmutable
    {
        try {
            if (!is_string($value)
                || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
                throw new \DomainException('CBS update timestamp is invalid.');
            }
            $date = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new \DomainException('CBS update timestamp is invalid.');
            }

            return $date;
        } catch (\Exception) {
            throw new \DomainException('CBS update timestamp is invalid.');
        }
    }
}

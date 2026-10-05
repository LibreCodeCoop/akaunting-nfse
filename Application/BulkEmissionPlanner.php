<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Pure preflight planner for bulk emission.
 *
 * It never performs issuance. It only decides which already-resolved fiscal
 * units are safe to enqueue and which invoices still require operator action.
 */
final class BulkEmissionPlanner
{
    /**
     * @param list<array{
     *   invoice_id:int,
     *   ready:bool,
     *   blockers?:list<string>,
     *   groups:list<array<string,mixed>>
     * }> $candidates
     * @return array{
     *   queued:list<array{invoice_id:int,emission_group_key:string}>,
     *   blocked:list<array{invoice_id:int,reason:string,details:list<string>}>,
     *   already_issued:list<int>
     * }
     */
    public function plan(array $candidates): array
    {
        $queued = [];
        $blocked = [];
        $alreadyIssued = [];

        foreach ($candidates as $candidate) {
            $invoiceId = (int) ($candidate['invoice_id'] ?? 0);

            if ($invoiceId <= 0) {
                continue;
            }

            if (($candidate['ready'] ?? false) !== true) {
                $blocked[] = [
                    'invoice_id' => $invoiceId,
                    'reason' => 'readiness',
                    'details' => array_values(
                        is_array($candidate['blockers'] ?? null) ? $candidate['blockers'] : [],
                    ),
                ];
                continue;
            }

            $groups = is_array($candidate['groups'] ?? null) ? $candidate['groups'] : [];
            $pending = array_values(array_filter(
                $groups,
                static fn (array $group): bool => ($group['issued'] ?? false) !== true,
            ));

            if ($pending === []) {
                $alreadyIssued[] = $invoiceId;
                continue;
            }

            if (count($pending) !== 1) {
                $blocked[] = [
                    'invoice_id' => $invoiceId,
                    'reason' => 'requires_group_selection',
                    'details' => array_values(array_filter(array_map(
                        static fn (array $group): string => trim((string) ($group['key'] ?? '')),
                        $pending,
                    ))),
                ];
                continue;
            }

            $groupKey = trim((string) ($pending[0]['key'] ?? ''));

            if ($groupKey === '') {
                $blocked[] = [
                    'invoice_id' => $invoiceId,
                    'reason' => 'invalid_group',
                    'details' => [],
                ];
                continue;
            }

            $queued[] = [
                'invoice_id' => $invoiceId,
                'emission_group_key' => $groupKey,
            ];
        }

        return [
            'queued' => $queued,
            'blocked' => $blocked,
            'already_issued' => $alreadyIssued,
        ];
    }
}

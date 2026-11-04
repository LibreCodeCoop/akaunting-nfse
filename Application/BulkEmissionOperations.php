<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document as Invoice;

/**
 * Common fiscal bulk operation for session-backed Web actions and native API.
 * It never transmits a DPS in HTTP; the existing dispatcher queues work.
 */
final class BulkEmissionOperations
{
    public function __construct(
        private readonly AutomaticInvoiceEmissionPreflight $preflight,
        private readonly BulkEmissionPlanner $planner,
        private readonly BulkEmissionDispatcher $dispatcher,
    ) {
    }

    /**
     * @param list<int> $invoiceIds Already validated, unique invoice IDs.
     * @return array{
     *   queued:list<array<string,mixed>>,
     *   blocked:list<array<string,mixed>>,
     *   already_issued:list<int>,
     *   not_found:list<int>,
     *   units:list<array<string,mixed>>
     * }
     */
    public function plan(int $companyId, array $invoiceIds): array
    {
        if ($companyId <= 0) {
            throw new \InvalidArgumentException('A company is required.');
        }

        $candidates = [];
        $preflightByInvoice = [];
        $notFound = [];

        foreach ($invoiceIds as $invoiceId) {
            $invoice = Invoice::query()
                ->where('company_id', $companyId)
                ->where('type', Invoice::INVOICE_TYPE)
                ->whereKey($invoiceId)
                ->first();

            if (!$invoice instanceof Invoice) {
                $notFound[] = $invoiceId;
                continue;
            }

            $result = $this->preflight->evaluate($invoice);
            $status = (string) ($result['status'] ?? 'blocked');
            $reason = trim((string) ($result['reason'] ?? ''));
            $details = is_array($result['details'] ?? null)
                ? array_values(array_map('strval', $result['details']))
                : [];

            $preflightByInvoice[$invoiceId] = [
                'reason' => $reason !== '' ? $reason : 'readiness',
                'details' => $details,
            ];

            if ($status === 'ready' && is_array($result['group'] ?? null)) {
                $candidates[] = [
                    'invoice_id' => $invoiceId,
                    'ready' => true,
                    'groups' => [$result['group']],
                ];
            } elseif ($status === 'already_issued') {
                $candidates[] = [
                    'invoice_id' => $invoiceId,
                    'ready' => true,
                    'groups' => [],
                ];
            } else {
                $candidates[] = [
                    'invoice_id' => $invoiceId,
                    'ready' => false,
                    'blockers' => array_values(array_filter([$reason, ...$details])),
                    'groups' => [],
                ];
            }
        }

        $plan = $this->planner->plan($candidates);
        $units = array_map(
            static fn (array $unit): array => [
                ...$unit,
                'status' => BulkEmissionStatusPolicy::QUEUED,
                'error_type' => null,
                'error_message' => null,
            ],
            $plan['queued'],
        );

        foreach ($plan['blocked'] as $blocked) {
            $invoiceId = (int) ($blocked['invoice_id'] ?? 0);
            $preflight = $preflightByInvoice[$invoiceId] ?? [
                'reason' => (string) ($blocked['reason'] ?? 'readiness'),
                'details' => is_array($blocked['details'] ?? null) ? $blocked['details'] : [],
            ];
            $reason = trim((string) ($preflight['reason'] ?? 'readiness'));
            $details = is_array($preflight['details'] ?? null)
                ? array_values(array_map('strval', $preflight['details']))
                : [];
            $safeReason = preg_replace('/[^a-z0-9_-]+/i', '-', $reason) ?: 'readiness';

            $units[] = [
                'invoice_id' => $invoiceId,
                'emission_group_key' => 'preflight:' . substr($safeReason, 0, 200),
                'status' => BulkEmissionStatusPolicy::BLOCKED,
                'error_type' => $reason,
                'error_message' => $details !== [] ? implode('; ', $details) : null,
            ];
        }

        return [
            ...$plan,
            'not_found' => $notFound,
            'units' => $units,
        ];
    }

    /**
     * @param list<int> $invoiceIds
     * @return array{run_id:?int,reused:bool,dispatched:int,queued:int,blocked:int,already_issued:int}
     */
    public function dispatch(int $companyId, ?int $requestedBy, array $invoiceIds): array
    {
        $plan = $this->plan($companyId, $invoiceIds);
        $counts = [
            'run_id' => null,
            'reused' => false,
            'dispatched' => 0,
            'queued' => count($plan['queued']),
            'blocked' => count($plan['blocked']) + count($plan['not_found']),
            'already_issued' => count($plan['already_issued']),
        ];
        if ($plan['units'] === []) {
            return $counts;
        }

        $result = $this->dispatcher->dispatch(
            companyId: $companyId,
            requestedBy: $requestedBy,
            units: $plan['units'],
        );

        return [
            ...$counts,
            'run_id' => (int) $result['run']->id,
            'reused' => (bool) $result['reused'],
            'dispatched' => (int) $result['dispatched'],
        ];
    }
}

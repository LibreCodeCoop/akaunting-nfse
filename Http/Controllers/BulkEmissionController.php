<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Models\Document\Document as Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Nfse\Application\AutomaticInvoiceEmissionPreflight;
use Modules\Nfse\Application\BulkEmissionDispatcher;
use Modules\Nfse\Application\BulkEmissionPlanner;
use Modules\Nfse\Application\BulkEmissionStatusPolicy;
use Modules\Nfse\Models\BulkEmissionRun;
use Modules\Nfse\Models\BulkEmissionUnit;

final class BulkEmissionController extends Controller
{
    public function enqueue(
        Request $request,
        AutomaticInvoiceEmissionPreflight $preflight,
        BulkEmissionPlanner $planner,
        BulkEmissionDispatcher $dispatcher,
    ): RedirectResponse {
        $companyId = $this->companyId();
        $invoiceIds = $this->invoiceIds($request);

        if ($companyId <= 0 || $invoiceIds === []) {
            return redirect()
                ->route('invoices.index')
                ->with('error', trans('nfse::general.bulk.select_at_least_one'));
        }

        $candidates = [];
        $preflightByInvoice = [];
        $notFound = [];

        foreach ($invoiceIds as $invoiceId) {
            $invoice = Invoice::query()
                ->where('company_id', $companyId)
                ->where('type', 'invoice')
                ->where('id', $invoiceId)
                ->first();

            if (!$invoice instanceof Invoice) {
                $notFound[] = [
                    'invoice_id' => $invoiceId,
                    'reason' => 'not_found_or_forbidden',
                    'details' => [],
                ];
                continue;
            }

            $result = $preflight->evaluate($invoice);
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
                continue;
            }

            if ($status === 'already_issued') {
                $candidates[] = [
                    'invoice_id' => $invoiceId,
                    'ready' => true,
                    'groups' => [],
                ];
                continue;
            }

            $candidates[] = [
                'invoice_id' => $invoiceId,
                'ready' => false,
                'blockers' => array_values(array_filter([
                    $reason,
                    ...$details,
                ])),
                'groups' => [],
            ];
        }

        $plan = $planner->plan($candidates);
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

        if ($units === []) {
            return redirect()
                ->route('invoices.index')
                ->with('success', trans('nfse::general.bulk.nothing_to_dispatch'));
        }

        $result = $dispatcher->dispatch(
            companyId: $companyId,
            requestedBy: $this->userId(),
            units: $units,
        );

        return redirect()
            ->route('nfse.bulk.index')
            ->with('success', trans('nfse::general.bulk.dispatched', [
                'queued' => $result['dispatched'],
                'blocked' => count($plan['blocked']) + count($notFound),
                'already' => count($plan['already_issued']),
            ]));
    }

    /**
     * @return list<int>
     */
    private function invoiceIds(Request $request): array
    {
        $raw = $request->input('invoice_ids', []);

        if (!is_array($raw)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0,
            $raw,
        ), static fn (int $id): bool => $id > 0)));

        return array_slice($ids, 0, 100);
    }

    private function companyId(): int
    {
        if (!function_exists('company_id')) {
            return 0;
        }

        try {
            return (int) (company_id() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function userId(): ?int
    {
        if (!function_exists('auth')) {
            return null;
        }

        try {
            $user = auth()->user();
        } catch (\Throwable) {
            return null;
        }

        $id = is_object($user) && is_numeric($user->id ?? null) ? (int) $user->id : 0;

        return $id > 0 ? $id : null;
    }

    public function index(): View
    {
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        $runs = $companyId > 0
            ? BulkEmissionRun::query()
                ->where('company_id', $companyId)
                ->latest('id')
                ->get()
            : collect();

        $unitsByRun = [];

        foreach ($runs as $run) {
            $runId = (int) ($run->id ?? 0);

            if ($runId <= 0) {
                continue;
            }

            $unitsByRun[$runId] = BulkEmissionUnit::query()
                ->where('run_id', $runId)
                ->latest('id')
                ->get();
        }

        return view('nfse::bulk.index', [
            'runs' => $runs,
            'unitsByRun' => $unitsByRun,
        ]);
    }
}

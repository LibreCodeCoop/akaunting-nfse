<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Nfse\Application\BulkEmissionOperations;

final class BulkEmissionController extends Controller
{
    public function enqueue(Request $request, BulkEmissionOperations $operation): RedirectResponse
    {
        $companyId = $this->companyId();
        $invoiceIds = $this->invoiceIds($request);
        if ($companyId <= 0 || $invoiceIds === []) {
            return redirect()->route('invoices.index')
                ->with('error', trans('nfse::general.bulk.select_at_least_one'));
        }

        $result = $operation->dispatch($companyId, $this->userId(), $invoiceIds);
        if ($result['run_id'] === null) {
            return redirect()->route('invoices.index')
                ->with('success', trans('nfse::general.bulk.nothing_to_dispatch'));
        }

        return redirect()->route('nfse.bulk.index')
            ->with('success', trans('nfse::general.bulk.dispatched', [
                'queued' => $result['dispatched'],
                'blocked' => $result['blocked'],
                'already' => $result['already_issued'],
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
        // Presentation only: company-scoped data and state are read as JSON by
        // the browser from the same controller used by native API consumers.
        return view('nfse::bulk.index');
    }
}

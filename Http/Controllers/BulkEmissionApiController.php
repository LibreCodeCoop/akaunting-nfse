<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Nfse\Application\BulkEmissionOperations;
use Modules\Nfse\Models\BulkEmissionRun;
use Modules\Nfse\Models\BulkEmissionUnit;

/**
 * Native and session-backed JSON adapters over the same fiscal bulk operation.
 * Queued units are processed by workers, never during an HTTP request.
 */
final class BulkEmissionApiController extends Controller
{
    public function plan(Request $request, BulkEmissionOperations $operation): JsonResponse
    {
        $ids = $this->invoiceIds($request);

        $plan = $operation->plan($this->companyId(), $ids);

        return response()->json([
            'data' => [
                'queued' => $plan['queued'],
                'blocked' => $plan['blocked'],
                'already_issued' => $plan['already_issued'],
                'not_found_or_forbidden' => $plan['not_found'],
            ],
        ]);
    }

    public function dispatch(Request $request, BulkEmissionOperations $operation): JsonResponse
    {
        $ids = $this->invoiceIds($request);
        $result = $operation->dispatch($this->companyId(), $this->userId(), $ids);

        return response()->json(['data' => $result], $result['dispatched'] > 0 ? 202 : 200);
    }

    public function runs(): JsonResponse
    {
        $companyId = $this->companyId();
        $page = BulkEmissionRun::query()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->paginate(25);

        $ids = array_map(static fn (BulkEmissionRun $run): int => (int) $run->id, $page->items());
        $units = BulkEmissionUnit::query()
            ->whereIn('run_id', $ids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('run_id');

        $rows = array_map(
            fn (BulkEmissionRun $run): array => $this->serializeRun(
                $run,
                $units->get((int) $run->id, collect())->all(),
            ),
            $page->items(),
        );

        return response()->json(['data' => $rows, 'meta' => $this->pageMeta($page)]);
    }

    public function show(int $run): JsonResponse
    {
        $record = BulkEmissionRun::query()
            ->whereKey($run)
            ->where('company_id', $this->companyId())
            ->firstOrFail();

        $units = BulkEmissionUnit::query()
            ->where('run_id', $record->id)
            ->orderByDesc('id')
            ->get()
            ->all();

        return response()->json(['data' => $this->serializeRun($record, $units)]);
    }

    /**
     * @return list<int>
     */
    private function invoiceIds(Request $request): array
    {
        $data = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
            'invoice_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        return array_map('intval', array_values($data['invoice_ids']));
    }

    private function companyId(): int
    {
        $id = function_exists('company_id') ? (int) company_id() : 0;
        abort_if($id <= 0, 404);

        return $id;
    }

    private function userId(): ?int
    {
        $id = auth()->id();

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * @param array<int,BulkEmissionUnit> $units
     * @return array<string,mixed>
     */
    private function serializeRun(BulkEmissionRun $run, array $units): array
    {
        return [
            'id' => (int) $run->id,
            'status' => (string) $run->status,
            'requested_by' => $run->requested_by === null ? null : (int) $run->requested_by,
            'selection_hash' => (string) $run->selection_hash,
            'created_at' => $run->created_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
            'units' => array_map(static fn (BulkEmissionUnit $unit): array => [
                'id' => (int) $unit->id,
                'invoice_id' => (int) $unit->invoice_id,
                'emission_group_key' => (string) $unit->emission_group_key,
                'status' => (string) $unit->status,
                'receipt_id' => $unit->receipt_id === null ? null : (int) $unit->receipt_id,
                'error_type' => $unit->error_type,
                'error_message' => $unit->error_message,
            ], $units),
        ];
    }

    /**
     * @return array{current_page:int,last_page:int,per_page:int,total:int}
     */
    private function pageMeta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Company-scoped, append-only category defaults for *future* commercial
 * invoices. "Do not issue" is an operational choice, never a declaration that
 * a service is legally exempt. Operator overrides take precedence.
 *
 * Reading policies never writes a fiscal or financial document.
 */
final class CategoryIssuancePolicy
{
    public const MODES = ['issue', 'do_not_issue'];

    public function categories(int $companyId): array
    {
        if ($companyId <= 0) {
            throw new \InvalidArgumentException('Company is required.');
        }

        $categories = DB::table('categories')
            ->where('company_id', $companyId)
            ->whereIn('type', ['income', 'other'])
            ->orderBy('name')->get(['id', 'name', 'type', 'enabled']);

        return $categories->map(function ($category) use ($companyId): array {
            return [
                'id' => (int) $category->id,
                'company_id' => $companyId,
                'name' => (string) $category->name,
                'type' => (string) $category->type,
                'enabled' => (bool) $category->enabled,
                'policy' => $this->latest($companyId, (int) $category->id),
            ];
        })->all();
    }

    public function latest(int $companyId, int $categoryId): ?array
    {
        if ($companyId <= 0 || $categoryId <= 0) {
            return null;
        }

        $row = DB::table('nfse_category_issuance_policies')
            ->where('company_id', $companyId)->where('category_id', $categoryId)
            ->orderByDesc('id')->first();

        return $row === null ? null : (array) $row;
    }

    public function history(int $companyId, int $categoryId): array
    {
        return DB::table('nfse_category_issuance_policies')
            ->where('company_id', $companyId)->where('category_id', $categoryId)
            ->orderByDesc('id')->get()->map(static fn ($row): array => (array) $row)->all();
    }

    public function record(
        int $companyId,
        int $categoryId,
        int $actorId,
        string $mode,
        string $justification,
    ): array {
        $justification = trim($justification);
        if ($companyId <= 0 || $categoryId <= 0 || $actorId <= 0
            || !in_array($mode, self::MODES, true)
            || mb_strlen($justification) < 12 || mb_strlen($justification) > 4000) {
            throw ValidationException::withMessages(['mode' => 'Invalid category emission policy.']);
        }

        return DB::transaction(function () use ($companyId, $categoryId, $actorId, $mode, $justification): array {
            $category = DB::table('categories')->where('company_id', $companyId)
                ->where('id', $categoryId)->whereIn('type', ['income', 'other'])
                ->lockForUpdate()->first();
            if ($category === null) {
                throw ValidationException::withMessages(['category_id' => 'Category not available for this company.']);
            }

            $id = DB::table('nfse_category_issuance_policies')->insertGetId([
                'company_id' => $companyId,
                'category_id' => $categoryId,
                'mode' => $mode,
                'justification' => $justification,
                'actor_id' => $actorId,
                'recorded_at' => now(),
            ]);

            return (array) DB::table('nfse_category_issuance_policies')->where('id', $id)->first();
        });
    }

    /** Return only the policy already in force when a commercial invoice
     * was created, with no retroactive reclassification.
     *
     * @return array<string,mixed>|null
     */
    public function forInvoice(Document $invoice): ?array
    {
        $companyId = (int) $invoice->company_id;
        $categoryId = (int) ($invoice->category_id ?? 0);
        $createdAt = $invoice->created_at;
        $issuedAt = $invoice->issued_at;
        if ((string) $invoice->type !== 'invoice'
            || $companyId <= 0 || $categoryId <= 0
            || !$createdAt instanceof \DateTimeInterface
            || !$issuedAt instanceof \DateTimeInterface) {
            return null;
        }

        $policy = DB::table('nfse_category_issuance_policies')
            ->where('company_id', $companyId)
            ->where('category_id', $categoryId)
            ->where('recorded_at', '<=', $createdAt)
            ->whereDate('recorded_at', '<=', $issuedAt->format('Y-m-d'))
            ->orderByDesc('id')->first();

        return $policy === null ? null : (array) $policy;
    }
}

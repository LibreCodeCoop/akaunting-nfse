<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Support;

use App\Models\Document\Document;
use App\Models\Setting\Category;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Application\CategoryIssuancePolicy;

/**
 * Explicitly opt-in legacy fiscal feature suites to an approved synthetic
 * service category. Tests of unapproved/nonservice categories must not use
 * this fixture; it never runs in the application runtime or production.
 */
trait AuthorizesSyntheticInvoiceCategories
{
    protected function authorizeSyntheticInvoiceCategories(): void
    {
        if (!app()->environment('testing')) {
            throw new \LogicException('The synthetic category fixture is restricted to tests.');
        }

        Document::created(static function (Document $invoice): void {
            if ((string) ($invoice->type ?? '') !== 'invoice') {
                return;
            }

            $companyId = (int) ($invoice->company_id ?? 0);
            if ($companyId <= 0) {
                return;
            }

            $categoryId = (int) ($invoice->category_id ?? 0);
            $category = $categoryId > 0
                ? DB::table('categories')->where('company_id', $companyId)
                    ->where('id', $categoryId)->first(['id', 'type', 'enabled'])
                : null;

            // Do not overwrite an explicit expense/transfer/disabled category
            // from a test intentionally exercising non-service behavior.
            if ($category !== null
                && ((string) $category->type !== 'income' || !(bool) $category->enabled)) {
                return;
            }

            if ($category === null) {
                $category = Category::factory()->create([
                    'company_id' => $companyId,
                    'type' => 'income',
                    'enabled' => true,
                    'name' => 'Synthetic NFS-e service income',
                ]);
                $invoice->forceFill(['category_id' => $category->id])->saveQuietly();
                $categoryId = (int) $category->id;
            }

            $policies = new CategoryIssuancePolicy();
            if (($policies->latest($companyId, $categoryId)['mode'] ?? null) !== 'issue') {
                $actorId = (int) (DB::table('users')->orderBy('id')->value('id') ?? 1);
                $policies->record($companyId, $categoryId, $actorId, 'issue',
                    'Synthetic service category explicitly approved for fiscal integration tests.');
            }
        });
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Models\Setting\Category;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Nfse\Application\CategoryIssuancePolicy;

/**
 * Bridges the native category form to the audit history. Both the category
 * update and policy insert occur in the core Category job's DB transaction.
 */
final class PersistNativeCategoryIssuance
{
    private ?array $change = null;

    public function creating(object $event): void
    {
        $this->capture($event->request ?? null, null);
    }

    public function updating(object $event): void
    {
        $category = $event->category ?? null;
        $this->capture($event->request ?? null, $category instanceof Category ? $category : null);
    }

    public function saved(Category $category): void
    {
        $change = $this->change;
        if ($change === null) {
            return;
        }

        if ((int) $category->company_id !== $change['company_id']
            || ($change['category_id'] !== 0 && (int) $category->id !== $change['category_id'])
            || ($change['category_id'] === 0 && !$category->wasRecentlyCreated)
            || $category->getConnection()->transactionLevel() < 1) {
            $this->change = null;
            throw new \RuntimeException('Category fiscal policy must be saved in the native company transaction.');
        }

        $this->change = null;
        (new CategoryIssuancePolicy())->record(
            $change['company_id'],
            (int) $category->id,
            $change['actor_id'],
            $change['mode'],
            $change['justification'],
        );
    }

    public function created(object $event): void
    {
        // Native firstOrCreate may return an already existing category and
        // never fire Eloquent saved(). Never claim that a requested fiscal
        // change was applied in that situation.
        if ($this->change !== null) {
            $this->change = null;
            throw ValidationException::withMessages([
                'nfse_category_issuance_mode' => 'An existing category was returned; edit that category instead.',
            ]);
        }
    }

    private function capture(mixed $input, ?Category $category): void
    {
        $this->change = null;

        if (!$input instanceof Request || !$input->exists('nfse_category_issuance_mode')) {
            return;
        }

        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        $actorId = (int) auth()->id();
        if ($companyId <= 0 || $actorId <= 0
            || ($input->exists('company_id') && (int) $input->input('company_id') !== $companyId)
            || ($category !== null && (int) $category->company_id !== $companyId)) {
            throw new AuthorizationException('Fiscal category must belong to the active company.');
        }

        if (!auth()->user()?->can('update-nfse-settings')) {
            throw new AuthorizationException('Permission to update NFS-e settings is required.');
        }

        $mode = $input->input('nfse_category_issuance_mode');
        if (!is_string($mode) || ($mode !== '' && !in_array($mode, CategoryIssuancePolicy::MODES, true))) {
            throw ValidationException::withMessages([
                'nfse_category_issuance_mode' => 'Choose a valid fiscal issuance authorization.',
            ]);
        }

        $categoryId = $category === null ? 0 : (int) $category->id;
        $previous = $categoryId > 0
            ? (new CategoryIssuancePolicy())->latest($companyId, $categoryId) : null;

        if ($mode === '' && $previous === null) {
            return;
        }

        if ($mode === '' || (string) ($previous['mode'] ?? '') === $mode) {
            if ($mode === '') {
                throw ValidationException::withMessages([
                    'nfse_category_issuance_mode' => 'Choose an explicit authorization to change the current rule.',
                ]);
            }

            return;
        }

        if ((string) $input->input('type', $category?->type ?? '') !== Category::INCOME_TYPE
            || ($mode === 'issue' && $input->exists('enabled') && !(bool) $input->input('enabled'))) {
            throw ValidationException::withMessages([
                'nfse_category_issuance_mode' => 'Only enabled income categories may authorize NFS-e issuance.',
            ]);
        }

        $justification = $input->input('nfse_category_issuance_justification', '');
        if (!is_string($justification)
            || mb_strlen(trim($justification)) < 12
            || mb_strlen(trim($justification)) > 4000) {
            throw ValidationException::withMessages([
                'nfse_category_issuance_justification' => 'A justification of 12 to 4000 characters is required when changing fiscal authorization.',
            ]);
        }

        // The native CreateCategory job uses firstOrCreate by company and
        // code. Do not silently report success for a request that resolves
        // to an existing category without firing an Eloquent saved event.
        // Match the core CreateCategory job: firstOrCreate is only used
        // when a nonempty code was supplied. Income category forms normally
        // hide this field and must remain creatable with no code at all.
        if ($categoryId === 0 && !empty($input->input('code'))
            && DB::table('categories')->where('company_id', $companyId)
                ->where('code', (string) $input->input('code'))
                ->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages([
                'nfse_category_issuance_mode' => 'This category code already exists. Open its edit screen to change fiscal authorization.',
            ]);
        }

        $this->change = [
            'company_id' => $companyId,
            'category_id' => $categoryId,
            'actor_id' => $actorId,
            'mode' => $mode,
            'justification' => trim($justification),
        ];
    }
}

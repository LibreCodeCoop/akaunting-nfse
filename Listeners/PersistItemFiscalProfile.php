<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Models\Common\Item;
use Modules\Nfse\Http\Requests\ValidatedFiscalItem;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Support\ItemFiscalProfileInput;

/**
 * Request-scoped bridge from Akaunting's pre-transaction ItemCreating /
 * ItemUpdating events into its in-transaction Eloquent saving/saved events.
 */
final class PersistItemFiscalProfile
{
    private ?ValidatedFiscalItem $request = null;

    private ?string $operation = null;

    private ?int $expectedItemId = null;

    private int $companyId = 0;

    public function creating(object $event): void
    {
        $this->capture($event->request ?? null, 'create');
    }

    public function updating(object $event): void
    {
        $this->capture($event->request ?? null, 'update', $event->item ?? null);
    }

    /**
     * Guard before the commercial INSERT/UPDATE against an upstream core
     * change that removes the enclosing native transaction.
     */
    public function beforeSave(Item $item): void
    {
        if (!$this->matches($item)) {
            return;
        }

        $this->assertCompany($item);

        $connection = $item->getConnection();
        if ($connection->transactionLevel() < 1
            || $connection->getName() !== (new ItemFiscalProfile())->getConnection()->getName()) {
            $this->clear();
            throw new \RuntimeException(trans('nfse::general.items.fiscal_save_failed'));
        }
    }

    public function afterSave(Item $item): void
    {
        if (!$this->matches($item)) {
            return;
        }

        $this->assertCompany($item);
        $request = $this->request;
        $this->clear();

        try {
            $companyId = (int) $item->company_id;
            $itemId = (int) $item->id;

            $stored = ItemFiscalProfile::query()
                ->where('company_id', $companyId)
                ->where('item_id', $itemId)
                ->first();
            $existing = $stored instanceof ItemFiscalProfile ? [
                'item_lista_servico' => $stored->item_lista_servico,
                'codigo_tributacao_nacional' => $stored->codigo_tributacao_nacional,
                'codigo_tributacao_municipal' => $stored->codigo_tributacao_municipal,
                'codigo_nbs' => $stored->codigo_nbs,
                'rtc_supply_category' => $stored->rtc_supply_category,
            ] : null;
            $profile = ItemFiscalProfileInput::fromRequest($request, $existing);

            if ($profile === null) {
                return;
            }

            if ($profile['item_lista_servico'] === null
                && $profile['codigo_tributacao_nacional'] === null
                && $profile['codigo_tributacao_municipal'] === null
                && ($profile['codigo_nbs'] ?? null) === null
                && ($profile['rtc_supply_category'] ?? null) === null) {
                ItemFiscalProfile::query()
                    ->where('company_id', $companyId)
                    ->where('item_id', $itemId)
                    ->delete();

                return;
            }

            ItemFiscalProfile::updateOrCreate(
                ['company_id' => $companyId, 'item_id' => $itemId],
                $profile,
            );
        } catch (\Throwable $e) {
            // The exception must escape to Akaunting's DB::transaction and
            // ajaxDispatch, which returns success=false to AJAX/API clients.
            report($e);
            throw new \RuntimeException(trans('nfse::general.items.fiscal_save_failed'), 0, $e);
        }
    }

    private function capture(mixed $request, string $operation, mixed $item = null): void
    {
        $this->clear();

        // No request inference: exclude imports, unrelated background jobs and
        // ordinary commercial updates with no NFS-e fields.
        if (!$request instanceof ValidatedFiscalItem
            || ItemFiscalProfileInput::fromRequest($request) === null
            || !function_exists('company_id')) {
            return;
        }

        $companyId = (int) company_id();
        if ($companyId <= 0) {
            return;
        }

        if ($operation === 'update') {
            $itemId = is_object($item) && is_numeric($item->id ?? null) ? (int) $item->id : 0;
            if ($itemId <= 0) {
                return;
            }
            $this->expectedItemId = $itemId;
        }

        $this->request = $request;
        $this->operation = $operation;
        $this->companyId = $companyId;
    }

    private function matches(Item $item): bool
    {
        if ($this->request === null) {
            return false;
        }

        if ($this->operation === 'create') {
            return !$item->exists || $item->wasRecentlyCreated;
        }

        return $this->operation === 'update'
            && $item->exists
            && (int) $item->id === $this->expectedItemId;
    }

    private function assertCompany(Item $item): void
    {
        if ((int) $item->company_id === $this->companyId) {
            return;
        }

        $this->clear();
        throw new \RuntimeException(trans('nfse::general.items.fiscal_save_failed'));
    }

    private function clear(): void
    {
        $this->request = null;
        $this->operation = null;
        $this->expectedItemId = null;
        $this->companyId = 0;
    }
}

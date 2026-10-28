<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Events\Api\ResourceShowing;
use App\Models\Common\Contact;
use App\Models\Common\Item;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Support\ContactFiscalProfileStore;

/**
 * Extend Akaunting's native API resources without duplicating its item/contact CRUD.
 */
final class AppendFiscalProfileToApiResource
{
    public function showing(ResourceShowing $event): void
    {
        $model = $event->model;
        if (!$model instanceof Item && !$model instanceof Contact) {
            return;
        }

        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        if ($companyId <= 0 || (int) $model->company_id !== $companyId) {
            return;
        }

        $user = auth()->user();
        if ($user === null || !$user->can('read-nfse-settings')) {
            return;
        }

        if ($model instanceof Item) {
            if (!$user->can('read-items')) {
                return;
            }

            $profile = ItemFiscalProfile::query()
                ->where('company_id', $companyId)
                ->where('item_id', (int) $model->id)
                ->first();

            $event->addResource('nfse_fiscal_profile', $profile === null ? null : [
                'item_lista_servico' => $profile->item_lista_servico,
                'codigo_tributacao_nacional' => $profile->codigo_tributacao_nacional,
                'codigo_tributacao_municipal' => $profile->codigo_tributacao_municipal,
                'codigo_nbs' => $profile->codigo_nbs,
                'rtc_supply_category' => $profile->rtc_supply_category,
            ]);

            return;
        }

        if ($model->type !== Contact::CUSTOMER_TYPE || !$user->can('read-contacts')) {
            return;
        }

        $profile = (new ContactFiscalProfileStore())->forContact($model);
        $event->addResource('nfse_fiscal_profile',
            $profile['municipal_registration'] === '' && $profile['legal_name'] === ''
                ? null
                : $profile,
        );
    }
}

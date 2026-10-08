<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Support\ItemFiscalProfileInput;

final class PersistItemFiscalProfile
{
    public function handle(object $event): void
    {
        $item = $event->item ?? null;
        $request = $event->request ?? null;

        if (!is_object($item)) {
            return;
        }

        $companyId = is_numeric($item->company_id ?? null) ? (int) $item->company_id : 0;
        $itemId = is_numeric($item->id ?? null) ? (int) $item->id : 0;

        if ($companyId <= 0 || $itemId <= 0) {
            return;
        }

        try {
            $stored = ItemFiscalProfile::query()
                ->where('company_id', $companyId)
                ->where('item_id', $itemId)
                ->first();
            $existing = $stored instanceof ItemFiscalProfile ? [
                'item_lista_servico' => $stored->item_lista_servico,
                'codigo_tributacao_nacional' => $stored->codigo_tributacao_nacional,
                'codigo_tributacao_municipal' => $stored->codigo_tributacao_municipal,
                'rtc_supply_category' => $stored->rtc_supply_category,
            ] : null;
            $profile = ItemFiscalProfileInput::fromRequest($request, $existing);

            if ($profile === null) {
                return;
            }

            if ($profile['item_lista_servico'] === null && $profile['codigo_tributacao_nacional'] === null && $profile['codigo_tributacao_municipal'] === null && ($profile['rtc_supply_category'] ?? null) === null) {
                ItemFiscalProfile::query()
                    ->where('company_id', $companyId)
                    ->where('item_id', $itemId)
                    ->delete();

                return;
            }

            ItemFiscalProfile::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'item_id' => $itemId,
                ],
                $profile,
            );
        } catch (\Throwable $e) {
            // Preserve Akaunting item save semantics while making fiscal persistence
            // failures observable to the configured exception reporter.
            if (function_exists('report')) {
                report($e);
            }
        }
    }
}

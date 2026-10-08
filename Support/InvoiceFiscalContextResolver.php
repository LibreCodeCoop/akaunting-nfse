<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use App\Models\Common\Item as CommonItem;
use App\Models\Document\Document as Invoice;
use Modules\Nfse\Application\InvoiceFiscalGroupBuilder;
use Modules\Nfse\Application\InvoiceFiscalProfileSelector;
use Modules\Nfse\Models\ItemFiscalProfile;

/**
 * Resolves Akaunting invoice/item persistence into fiscal-profile/group inputs.
 *
 * This is an adapter: fiscal grouping/selection rules remain in the pure
 * application builders. HTTP controllers, queue workers and lifecycle
 * listeners can therefore share the same Akaunting lookup behavior.
 */
final class InvoiceFiscalContextResolver
{
    /**
     * @return array{
     *   item_lista_servico:string,
     *   codigo_tributacao_nacional:string,
     *   aliquota:string,
     *   line_items:list<string>,
     *   requires_split:bool
     * }
     */
    public function profile(Invoice $invoice): array
    {
        $items = $this->items($invoice);
        $itemIds = $this->itemIds($items);

        return (new InvoiceFiscalProfileSelector())->select(
            items: $items,
            profileMap: $this->profileMap($this->companyId($invoice), $itemIds),
            taxRateMap: $this->taxRateMap($itemIds),
            defaultServiceCode: Lc116Code::normalize(setting('nfse.item_lista_servico', '')),
            defaultNationalCode: $this->nationalTaxCode(),
            defaultRate: $this->defaultRate(),
            unnamedItemLabel: (string) trans('general.na'),
        );
    }

    /**
     * @return list<array{
     *   key:string,
     *   item_lista_servico:string,
     *   codigo_tributacao_nacional:string,
     *   aliquota:string,
     *   amount:string,
     *   items:list<array{document_item_id:int,item_id:int,name:string,amount:string}>
     * }>
     */
    public function groups(Invoice $invoice): array
    {
        $items = $this->items($invoice);

        foreach ($items as $item) {
            if (!is_scalar($item['total'] ?? null)) {
                return [];
            }
        }

        $itemIds = $this->itemIds($items);

        return (new InvoiceFiscalGroupBuilder())->build(
            items: $items,
            profileMap: $this->profileMap($this->companyId($invoice), $itemIds),
            taxRateMap: $this->taxRateMap($itemIds),
            defaultServiceCode: Lc116Code::normalize(setting('nfse.item_lista_servico', '')),
            defaultNationalCode: $this->nationalTaxCode(),
            defaultRate: $this->defaultRate(),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function items(Invoice $invoice): array
    {
        $items = $invoice->items;

        if (is_object($items) && method_exists($items, 'toArray')) {
            $arrayItems = $items->toArray();

            return is_array($arrayItems) ? array_values($arrayItems) : [];
        }

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<int>
     */
    private function itemIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            $id = is_numeric($item['item_id'] ?? null) ? (int) $item['item_id'] : 0;

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $itemIds
     * @return array<int,array{item_lista_servico:string,codigo_tributacao_nacional:string,codigo_tributacao_municipal:string}>
     */
    private function profileMap(int $companyId, array $itemIds): array
    {
        if ($companyId <= 0 || $itemIds === []) {
            return [];
        }

        try {
            return ItemFiscalProfile::query()
                ->where('company_id', $companyId)
                ->whereIn('item_id', $itemIds)
                ->get()
                ->mapWithKeys(static function (ItemFiscalProfile $profile): array {
                    $itemId = (int) ($profile->item_id ?? 0);

                    if ($itemId <= 0) {
                        return [];
                    }

                    return [
                        $itemId => [
                            'item_lista_servico' => Lc116Code::normalize($profile->item_lista_servico ?? ''),
                            'codigo_tributacao_nacional' => preg_replace(
                                '/\D+/',
                                '',
                                (string) ($profile->codigo_tributacao_nacional ?? ''),
                            ) ?: '',
                            'rtc_supply_category' => trim((string) ($profile->rtc_supply_category ?? '')),
                            'codigo_tributacao_municipal' => preg_replace(
                                '/\D+/',
                                '',
                                (string) ($profile->codigo_tributacao_municipal ?? ''),
                            ) ?: '',
                        ],
                    ];
                })
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<int> $itemIds
     * @return array<int,string>
     */
    private function taxRateMap(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        try {
            $items = CommonItem::query()
                ->whereIn('id', $itemIds)
                ->with(['taxes.tax'])
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $rates = [];

        foreach ($items as $item) {
            $itemId = (int) ($item->id ?? 0);

            if ($itemId <= 0) {
                continue;
            }

            $rate = 0.0;

            foreach ($item->taxes ?? [] as $itemTax) {
                $tax = $itemTax->tax ?? null;

                if (!is_object($tax) || !is_numeric($tax->rate ?? null)) {
                    continue;
                }

                if (in_array(strtolower((string) ($tax->type ?? 'normal')), ['fixed', 'withholding'], true)) {
                    continue;
                }

                $rate += (float) $tax->rate;
            }

            if ($rate > 0) {
                $rates[$itemId] = number_format($rate, 2, '.', '');
            }
        }

        return $rates;
    }

    private function companyId(Invoice $invoice): int
    {
        $companyId = is_numeric($invoice->company_id ?? null) ? (int) $invoice->company_id : 0;

        if ($companyId <= 0 && function_exists('company_id')) {
            $companyId = (int) company_id();
        }

        return $companyId;
    }

    private function nationalTaxCode(): string
    {
        $configured = preg_replace(
            '/\D+/',
            '',
            (string) setting('nfse.codigo_tributacao_nacional', ''),
        ) ?: '';

        return $configured !== ''
            ? str_pad(substr($configured, 0, 6), 6, '0', STR_PAD_LEFT)
            : '';
    }

    private function defaultRate(): string
    {
        $normalized = str_replace(',', '.', trim((string) setting('nfse.aliquota', '5.00')));

        return number_format((float) $normalized, 2, '.', '');
    }
}

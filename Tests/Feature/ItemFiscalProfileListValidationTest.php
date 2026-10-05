<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Common\ItemTax;
use App\Models\Setting\Tax;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\MunicipalParameterSnapshot;
use Tests\Feature\FeatureTestCase;

final class ItemFiscalProfileListValidationTest extends FeatureTestCase
{
    public function testItemListPublishesFiscalValidationMetadataForVisibleItems(): void
    {
        $item = Item::factory()->create();

        ItemFiscalProfile::query()->create([
            'company_id' => $item->company_id,
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
            'aliquota' => '2.00',
        ]);

        $response = $this->loginAs()
            ->get(route('items.index'))
            ->assertOk();

        $response->assertSee('data-nfse-fiscal-validation', false);
        $response->assertSee((string) $item->id, false);
        $response->assertSee(trans('nfse::general.items.validation.status_valid'), false);
    }

    public function testItemListKeepsNationalValidStatusWhenMunicipalCacheIsUnavailable(): void
    {
        $item = Item::factory()->create();

        ItemFiscalProfile::query()->create([
            'company_id' => $item->company_id,
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
        ]);

        setting([
            'nfse.municipio_ibge' => '3303302',
            'nfse.sandbox_mode' => true,
        ]);
        setting()->save();

        $this->loginAs()
            ->get(route('items.index'))
            ->assertOk()
            ->assertSee(trans('nfse::general.items.validation.status_valid'), false);
    }

    public function testItemEditSurfacesCachedMunicipalRateMismatchWithProvenance(): void
    {
        $item = Item::factory()->create();
        $tax = Tax::factory()->enabled()->create([
            'company_id' => $item->company_id,
            'rate' => 2.00,
            'type' => 'normal',
        ]);

        ItemTax::query()->create([
            'company_id' => $item->company_id,
            'item_id' => $item->id,
            'tax_id' => $tax->id,
        ]);

        ItemFiscalProfile::query()->create([
            'company_id' => $item->company_id,
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
        ]);

        setting([
            'nfse.municipio_ibge' => '3303302',
            'nfse.sandbox_mode' => true,
        ]);
        setting()->save();

        MunicipalParameterSnapshot::query()->create([
            'company_id' => $item->company_id,
            'environment' => 'sandbox',
            'municipio_ibge' => '3303302',
            'service_code' => '010701',
            'competence_date' => '2026-10-05',
            'payload' => [
                'aliquota' => [
                    'aliquotas' => [
                        ['Aliq' => 5.0],
                    ],
                ],
            ],
            'fetched_at' => '2026-10-05 10:00:00',
        ]);

        $response = $this->loginAs()
            ->get(route('items.edit', $item))
            ->assertOk();

        $response->assertSee('data-nfse-municipal-validation="warning"', false);
        $response->assertSee(
            trans('nfse::general.items.validation.municipal_rate_mismatch', ['rate' => '5.00']),
            false,
        );
        $response->assertSee('data-nfse-municipal-fetched-at=', false);
    }

    public function testItemListMarksMissingProfileAsUnverifiableWithoutChangingItem(): void
    {
        $item = Item::factory()->create();

        $response = $this->loginAs()
            ->get(route('items.index'))
            ->assertOk();

        $response->assertSee(
            trans('nfse::general.items.validation.status_unverifiable'),
            false,
        );

        self::assertNull(
            ItemFiscalProfile::query()
                ->where('company_id', $item->company_id)
                ->where('item_id', $item->id)
                ->first(),
        );
    }
}

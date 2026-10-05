<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use Modules\Nfse\Models\ItemFiscalProfile;
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

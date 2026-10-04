<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use Modules\Nfse\Models\ItemFiscalProfile;
use Tests\Feature\FeatureTestCase;

final class ItemFiscalProfileTest extends FeatureTestCase
{
    public function testNativeItemCreatePersistsFiscalProfileThroughAkauntingEvent(): void
    {
        $request = Item::factory()->enabled()->raw();
        $request['nfse_item_lista_servico'] = '1.07';
        $request['nfse_codigo_tributacao_nacional'] = '010701';

        $this->loginAs()
            ->post(route('items.store'), $request)
            ->assertStatus(200);

        $item = Item::query()
            ->where('company_id', company_id())
            ->where('name', $request['name'])
            ->firstOrFail();

        $this->assertDatabaseHas('nfse_item_fiscal_profiles', [
            'company_id' => company_id(),
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
        ]);
    }

    public function testNativeItemUpdateChangesFiscalProfileThroughAkauntingEvent(): void
    {
        $item = Item::factory()->enabled()->create();

        ItemFiscalProfile::query()->create([
            'company_id' => company_id(),
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
        ]);

        $request = Item::factory()->enabled()->raw([
            'name' => $item->name,
        ]);
        $request['nfse_item_lista_servico'] = '0101';
        $request['nfse_codigo_tributacao_nacional'] = '010101';

        $this->loginAs()
            ->patch(route('items.update', $item->id), $request)
            ->assertStatus(200);

        $this->assertDatabaseHas('nfse_item_fiscal_profiles', [
            'company_id' => company_id(),
            'item_id' => $item->id,
            'item_lista_servico' => '0101',
            'codigo_tributacao_nacional' => '010101',
        ]);
    }

    public function testNativeItemUpdateCanRemoveFiscalProfile(): void
    {
        $item = Item::factory()->enabled()->create();

        ItemFiscalProfile::query()->create([
            'company_id' => company_id(),
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
        ]);

        $request = Item::factory()->enabled()->raw([
            'name' => $item->name,
        ]);
        $request['nfse_item_lista_servico'] = '';
        $request['nfse_codigo_tributacao_nacional'] = '';

        $this->loginAs()
            ->patch(route('items.update', $item->id), $request)
            ->assertStatus(200);

        $this->assertDatabaseMissing('nfse_item_fiscal_profiles', [
            'company_id' => company_id(),
            'item_id' => $item->id,
        ]);
    }
}

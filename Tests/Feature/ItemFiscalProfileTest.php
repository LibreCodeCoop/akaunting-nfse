<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Tests\Support\FiscalScenarioBuilder;
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

        FiscalScenarioBuilder::itemProfile($item);

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

    public function testUnknownNationalCodeIsRejectedBeforeCreatingAnItem(): void
    {
        $request = Item::factory()->enabled()->raw();
        $request['nfse_item_lista_servico'] = '1.07';
        $request['nfse_codigo_tributacao_nacional'] = '999999';

        $this->withExceptionHandling();
        $this->loginAs()
            ->postJson(route('items.store'), $request)
            ->assertStatus(422)
            ->assertJsonValidationErrors('nfse_codigo_tributacao_nacional');

        $this->assertDatabaseMissing('items', [
            'company_id' => company_id(),
            'name' => $request['name'],
        ]);
    }

    public function testMalformedNationalCodeIsRejectedWithoutSilentTruncation(): void
    {
        $request = Item::factory()->enabled()->raw();
        $request['nfse_codigo_tributacao_nacional'] = '0107019';

        $this->withExceptionHandling();
        $this->loginAs()
            ->postJson(route('items.store'), $request)
            ->assertStatus(422)
            ->assertJsonValidationErrors('nfse_codigo_tributacao_nacional');
    }

    public function testInvalidNationalCodeOnUpdatePreservesExistingFiscalProfile(): void
    {
        $item = Item::factory()->enabled()->create();
        ItemFiscalProfile::query()->create([
            'company_id' => company_id(),
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
        ]);

        $request = Item::factory()->enabled()->raw(['name' => $item->name]);
        $request['nfse_item_lista_servico'] = '0101';
        $request['nfse_codigo_tributacao_nacional'] = '999999';

        $this->withExceptionHandling();
        $this->loginAs()
            ->patchJson(route('items.update', $item->id), $request)
            ->assertStatus(422)
            ->assertJsonValidationErrors('nfse_codigo_tributacao_nacional');

        $this->assertDatabaseHas('nfse_item_fiscal_profiles', [
            'company_id' => company_id(),
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
        ]);
    }

    public function testEditingItemWithoutFiscalFieldsPreservesHistoricProfile(): void
    {
        $item = Item::factory()->enabled()->create();
        ItemFiscalProfile::query()->create([
            'company_id' => company_id(),
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '999999',
        ]);

        $request = Item::factory()->enabled()->raw(['name' => $item->name]);

        $this->loginAs()
            ->patch(route('items.update', $item->id), $request)
            ->assertOk();

        $this->assertDatabaseHas('nfse_item_fiscal_profiles', [
            'company_id' => company_id(),
            'item_id' => $item->id,
            'codigo_tributacao_nacional' => '999999',
        ]);
    }

    public function testItemProfilePersistsExplicitRtcCategoryOnNativeUpdate(): void
    {
        $item = Item::factory()->enabled()->create();
        $request = Item::factory()->enabled()->raw(['name' => $item->name]);
        $request['nfse_item_lista_servico'] = '0107';
        $request['nfse_codigo_tributacao_nacional'] = '010701';
        $request['nfse_rtc_supply_category'] = 'digital_platform';

        $this->loginAs()->patch(route('items.update', $item->id), $request)->assertOk();

        $this->assertDatabaseHas('nfse_item_fiscal_profiles', [
            'company_id' => company_id(),
            'item_id' => $item->id,
            'rtc_supply_category' => 'digital_platform',
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

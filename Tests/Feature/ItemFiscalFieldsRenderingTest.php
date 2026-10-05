<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use Tests\Feature\FeatureTestCase;

final class ItemFiscalFieldsRenderingTest extends FeatureTestCase
{
    public function testCreateItemPageRendersNfseFiscalFields(): void
    {
        $this->loginAs()
            ->get(route('items.create'))
            ->assertOk()
            ->assertSee('name="nfse_item_lista_servico"', false)
            ->assertSee('name="nfse_codigo_tributacao_nacional"', false);
    }

    public function testEditItemPageRendersNfseFiscalFields(): void
    {
        $item = Item::factory()->create();

        $this->loginAs()
            ->get(route('items.edit', $item))
            ->assertOk()
            ->assertSee('name="nfse_item_lista_servico"', false)
            ->assertSee('name="nfse_codigo_tributacao_nacional"', false);
    }
}

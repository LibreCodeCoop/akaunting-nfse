<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Models\ItemFiscalProfile;
use Tests\Feature\FeatureTestCase;

final class FiscalGroupPreviewTest extends FeatureTestCase
{
    public function testServicePreviewReturnsExplicitGroupsForMixedFiscalInvoice(): void
    {
        $this->loginAs();

        $firstItem = Item::factory()->enabled()->create();
        $secondItem = Item::factory()->enabled()->create();

        $invoice = Document::factory()->invoice()->create([
            'items' => [
                [
                    'item_id' => $firstItem->id,
                    'name' => 'Consultoria',
                    'quantity' => '1',
                    'price' => '40.00',
                    'total' => '40.00',
                ],
                [
                    'item_id' => $secondItem->id,
                    'name' => 'Suporte',
                    'quantity' => '1',
                    'price' => '60.00',
                    'total' => '60.00',
                ],
            ],
        ]);

        ItemFiscalProfile::query()->create([
            'company_id' => company_id(),
            'item_id' => $firstItem->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
        ]);
        ItemFiscalProfile::query()->create([
            'company_id' => company_id(),
            'item_id' => $secondItem->id,
            'item_lista_servico' => '0101',
            'codigo_tributacao_nacional' => '010101',
        ]);

        $response = $this->get(route('nfse.invoices.service-preview', $invoice))
            ->assertOk()
            ->json();

        self::assertTrue((bool) ($response['requires_split'] ?? false));
        self::assertCount(2, $response['fiscal_groups'] ?? []);

        $amounts = array_column($response['fiscal_groups'], 'amount');
        sort($amounts);
        self::assertSame(['40.00', '60.00'], $amounts);
    }
}

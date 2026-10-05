<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Support\InvoiceFiscalContextResolver;
use Tests\Feature\FeatureTestCase;

final class InvoiceFiscalContextResolverTest extends FeatureTestCase
{
    public function testResolvesPersistedItemProfileAndFiscalGroupOutsideHttpController(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $item = Item::factory()->create(['company_id' => $invoice->company_id]);

        $documentItem = $invoice->items()->create([
            'company_id' => $invoice->company_id,
            'type' => 'item',
            'item_id' => $item->id,
            'name' => 'Consultoria',
            'quantity' => 1,
            'price' => 100,
            'total' => 100,
        ]);

        ItemFiscalProfile::query()->create([
            'company_id' => $invoice->company_id,
            'item_id' => $item->id,
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
        ]);

        $invoice->load('items');

        $resolver = new InvoiceFiscalContextResolver();
        $profile = $resolver->profile($invoice);
        $groups = $resolver->groups($invoice);

        self::assertSame('0107', $profile['item_lista_servico']);
        self::assertSame('010701', $profile['codigo_tributacao_nacional']);
        self::assertFalse($profile['requires_split']);

        self::assertCount(1, $groups);
        self::assertSame('service:0107|tax:010701|rate:5.00', $groups[0]['key']);
        self::assertSame('100.00', $groups[0]['amount']);
        self::assertSame((int) $documentItem->id, $groups[0]['items'][0]['document_item_id']);
    }

    public function testMultiplePersistedProfilesProduceDistinctFiscalGroups(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $first = Item::factory()->create(['company_id' => $invoice->company_id]);
        $second = Item::factory()->create(['company_id' => $invoice->company_id]);

        foreach ([
            [$first, '0107', '010701', '60'],
            [$second, '0101', '010101', '40'],
        ] as [$item, $service, $national, $total]) {
            $invoice->items()->create([
                'company_id' => $invoice->company_id,
                'type' => 'item',
                'item_id' => $item->id,
                'name' => $service,
                'quantity' => 1,
                'price' => $total,
                'total' => $total,
            ]);
            ItemFiscalProfile::query()->create([
                'company_id' => $invoice->company_id,
                'item_id' => $item->id,
                'item_lista_servico' => $service,
                'codigo_tributacao_nacional' => $national,
            ]);
        }

        $invoice->load('items');

        $resolver = new InvoiceFiscalContextResolver();

        self::assertTrue($resolver->profile($invoice)['requires_split']);
        self::assertCount(2, $resolver->groups($invoice));
    }
}

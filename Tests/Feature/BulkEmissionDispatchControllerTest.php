<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Application\BulkEmissionDispatcher;
use Modules\Nfse\Models\ItemFiscalProfile;
use Tests\Feature\FeatureTestCase;

final class BulkEmissionDispatchControllerTest extends FeatureTestCase
{
    public function testSelectedInvoicesArePreflightedPersistedAndOnlyEligibleUnitIsDispatched(): void
    {
        $ready = $this->invoiceWithProfile('0107', '010701', '100');
        $blocked = $this->invoiceWithProfile('0107', '010701', '100');
        $blocked->contact_country_code = 'GB';
        $blocked->save();

        $dispatched = [];
        $this->app->instance(
            BulkEmissionDispatcher::class,
            new BulkEmissionDispatcher(
                dispatchUnit: static function (int $unitId) use (&$dispatched): void {
                    $dispatched[] = $unitId;
                },
            ),
        );

        $this->loginAs()
            ->post(route('nfse.bulk.dispatch'), [
                'invoice_ids' => [$ready->id, $blocked->id],
            ])
            ->assertRedirect(route('nfse.bulk.index'))
            ->assertSessionHas('success');

        self::assertCount(1, $dispatched);
        $this->assertDatabaseCount('nfse_bulk_emission_runs', 1);
        $this->assertDatabaseCount('nfse_bulk_emission_units', 2);
        $this->assertDatabaseHas('nfse_bulk_emission_units', [
            'invoice_id' => $ready->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('nfse_bulk_emission_units', [
            'invoice_id' => $blocked->id,
            'status' => 'blocked',
            'error_type' => 'foreign_taker_requires_review',
        ]);
    }

    public function testEmptySelectionDoesNotCreateRun(): void
    {
        $this->loginAs()
            ->post(route('nfse.bulk.dispatch'), ['invoice_ids' => []])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('nfse_bulk_emission_runs', 0);
    }

    private function invoiceWithProfile(string $service, string $national, string $total): Document
    {
        $invoice = Document::factory()->invoice()->create();
        $invoice->items()->delete();
        $invoice->unsetRelation('items');

        $item = Item::factory()->create(['company_id' => $invoice->company_id]);
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

        $invoice->load('items');

        return $invoice;
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Contact;
use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Application\AutomaticInvoiceEmissionPreflight;
use Modules\Nfse\Application\BulkEmissionDispatcher;
use Modules\Nfse\Models\ItemFiscalProfile;
use Tests\Feature\FeatureTestCase;

final class BulkEmissionDispatchControllerTest extends FeatureTestCase
{
    public function testSelectedInvoicesArePreflightedPersistedAndOnlyEligibleUnitIsDispatched(): void
    {
        $this->loginAs();

        $ready = $this->invoiceWithProfile('0107', '010701', '100');
        $blocked = $this->invoiceWithProfile('0107', '010701', '100');
        $blocked->contact->forceFill(['country' => 'GB'])->saveQuietly();
        $blocked->unsetRelation('contact');

        $preflight = new AutomaticInvoiceEmissionPreflight();
        $readyResult = $preflight->evaluate($ready->fresh(['items', 'contact']));
        $blockedResult = $preflight->evaluate($blocked->fresh(['items', 'contact']));

        self::assertSame(
            'ready',
            $readyResult['status'],
            json_encode($readyResult, JSON_THROW_ON_ERROR),
        );
        self::assertSame('blocked', $blockedResult['status']);
        self::assertSame('foreign_taker_requires_review', $blockedResult['reason']);

        $dispatched = [];
        $this->app->instance(
            BulkEmissionDispatcher::class,
            new BulkEmissionDispatcher(
                dispatchUnit: static function (int $unitId) use (&$dispatched): void {
                    $dispatched[] = $unitId;
                },
            ),
        );

        $this->post(route('nfse.bulk.dispatch'), [
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

    public function testNativeAkauntingBulkActionDispatchesSelectedInvoices(): void
    {
        $this->loginAs();

        $ready = $this->invoiceWithProfile('0107', '010701', '100');
        $blocked = $this->invoiceWithProfile('0107', '010701', '100');
        $blocked->contact->forceFill(['country' => 'GB'])->saveQuietly();
        $blocked->unsetRelation('contact');

        $dispatched = [];
        $this->app->instance(
            BulkEmissionDispatcher::class,
            new BulkEmissionDispatcher(
                dispatchUnit: static function (int $unitId) use (&$dispatched): void {
                    $dispatched[] = $unitId;
                },
            ),
        );

        $this->post(route('bulk-actions.action', [
            'group' => 'nfse',
            'type' => 'invoices',
        ]), [
            'handle' => 'nfse',
            'selected' => [$ready->id, $blocked->id],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('redirect', route('nfse.bulk.index'));

        self::assertCount(1, $dispatched);
        $this->assertDatabaseCount('nfse_bulk_emission_runs', 1);
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
        $invoice = Document::factory()->invoice()->create([
            'company_id' => (int) $this->company->id,
            'issued_at' => '2026-09-30 12:00:00',
        ]);
        $contact = Contact::factory()->customer()->enabled()->create([
            'company_id' => (int) $this->company->id,
            'country' => 'BR',
        ]);
        $invoice->forceFill([
            'contact_id' => $contact->id,
            'contact_name' => $contact->name,
            'contact_email' => $contact->email,
            'contact_tax_number' => $contact->tax_number,
            'contact_phone' => $contact->phone,
            'contact_address' => $contact->address,
        ])->saveQuietly();
        $invoice->unsetRelation('contact');
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

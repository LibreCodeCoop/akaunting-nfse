<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Application\AutomaticInvoiceEmissionPreflight;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class AutomaticInvoiceEmissionPreflightTest extends FeatureTestCase
{
    public function testReturnsOneReadyUnitForUnambiguousPendingInvoice(): void
    {
        $invoice = $this->invoiceWithProfile('0107', '010701', '100');

        $result = (new AutomaticInvoiceEmissionPreflight())->evaluate($invoice);

        self::assertSame('ready', $result['status']);
        self::assertNull($result['reason']);
        self::assertSame('service:0107|tax:010701|mun:|rate:5.00', $result['group']['key'] ?? null);
    }

    public function testBlocksBeforeGatewayWhenNationalTaxCodeIsMissing(): void
    {
        setting(['nfse.codigo_tributacao_nacional' => '']);
        setting()->save();

        $invoice = $this->invoiceWithProfile('0107', '', '100');

        $result = (new AutomaticInvoiceEmissionPreflight())->evaluate($invoice);

        self::assertSame('blocked', $result['status']);
        self::assertSame('invalid_profile', $result['reason']);
        self::assertContains('missing_national_code', $result['details']);
        self::assertNull($result['group']);
    }

    public function testBlocksWhenMultipleFiscalGroupsStillNeedOperatorSelection(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $invoice->items()->delete();
        $invoice->unsetRelation('items');
        $this->addProfiledItem($invoice, '0107', '010701', '60');
        $this->addProfiledItem($invoice, '0101', '010101', '40');
        $invoice->load('items');

        $result = (new AutomaticInvoiceEmissionPreflight())->evaluate($invoice);

        self::assertSame('blocked', $result['status']);
        self::assertSame('requires_group_selection', $result['reason']);
        self::assertCount(2, $result['details']);
    }

    public function testBlocksForeignTakerThatNeedsInteractiveNifAndAddressReview(): void
    {
        $invoice = $this->invoiceWithProfile('0107', '010701', '100');
        $invoice->contact_country_code = 'GB';

        $result = (new AutomaticInvoiceEmissionPreflight())->evaluate($invoice);

        self::assertSame('blocked', $result['status']);
        self::assertSame('foreign_taker_requires_review', $result['reason']);
        self::assertSame(['GB'], $result['details']);
    }

    public function testAlreadyIssuedUnitIsIdempotent(): void
    {
        $invoice = $this->invoiceWithProfile('0107', '010701', '100');
        $group = (new \Modules\Nfse\Support\InvoiceFiscalContextResolver())->groups($invoice)[0];

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '1001',
            'chave_acesso' => str_repeat('1', 50),
            'status' => 'emitted',
            'emission_group_key' => $group['key'],
        ]);

        $result = (new AutomaticInvoiceEmissionPreflight())->evaluate($invoice);

        self::assertSame('already_issued', $result['status']);
        self::assertNull($result['group']);
    }

    private function invoiceWithProfile(string $service, string $national, string $total): Document
    {
        $invoice = Document::factory()->invoice()->create();
        $invoice->items()->delete();
        $invoice->unsetRelation('items');
        $this->addProfiledItem($invoice, $service, $national, $total);
        $invoice->load('items');

        return $invoice;
    }

    private function addProfiledItem(
        Document $invoice,
        string $service,
        string $national,
        string $total,
    ): void {
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
    }
}

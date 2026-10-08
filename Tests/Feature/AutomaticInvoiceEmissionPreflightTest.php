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
        // A ready baseline must not change when RTC obligations become effective.
        $invoice->forceFill(['issued_at' => '2026-09-30 12:00:00'])->save();

        $result = (new AutomaticInvoiceEmissionPreflight())->evaluate($invoice->fresh(['items', 'contact']));

        self::assertSame('ready', $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
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

    public function testNonLc116CategoryBlocksAutomaticEmissionEvenBeforeEffectiveDate(): void
    {
        $invoice = $this->invoiceWithProfile('0107', '010701', '100');
        \Modules\Nfse\Models\ItemFiscalProfile::query()
            ->where('company_id', $invoice->company_id)
            ->update(['rtc_supply_category' => 'lease']);
        $invoice->forceFill(['issued_at' => '2026-11-30 12:00:00'])->save();
        $result = (new AutomaticInvoiceEmissionPreflight())->evaluate($invoice->fresh(['items', 'contact']), [
            'opcao_simples_nacional' => 1,
            'ibs_cbs_enabled' => false,
        ]);
        self::assertSame('blocked', $result['status']);
        self::assertSame('rtc_supply_category_unsupported', $result['reason']);
        self::assertContains('lease', $result['details']);
    }

    public function testBlocksRequiredIbsCbsBeforeAutomaticEmission(): void
    {
        $invoice = $this->invoiceWithProfile('0107', '010701', '100');
        $invoice->forceFill(['issued_at' => '2026-10-08 12:00:00'])->save();

        $result = (new AutomaticInvoiceEmissionPreflight())->evaluate($invoice->fresh(['items', 'contact']), [
            'opcao_simples_nacional' => 1,
            'ibs_cbs_enabled' => false,
        ]);

        self::assertSame('blocked', $result['status']);
        self::assertSame('ibs_cbs_required', $result['reason']);
        self::assertContains('ibs_cbs_enabled', $result['details']);
    }

    public function testSimplesDoesNotRequireIbsCbsBefore2027(): void
    {
        $invoice = $this->invoiceWithProfile('0107', '010701', '100');
        $invoice->forceFill(['issued_at' => '2026-10-08 12:00:00'])->save();

        $result = (new AutomaticInvoiceEmissionPreflight())->evaluate($invoice->fresh(['items', 'contact']), [
            'opcao_simples_nacional' => 3,
            'ibs_cbs_enabled' => false,
        ]);

        self::assertSame('ready', $result['status']);
    }

    public function testAutomaticPreflightAppliesFederalTaxReadiness(): void
    {
        $invoice = $this->invoiceWithProfile('0107', '010701', '100');
        $invoice->forceFill(['issued_at' => '2026-09-30 12:00:00'])->save();

        $result = (new AutomaticInvoiceEmissionPreflight())->evaluate($invoice->fresh(['items', 'contact']), [
            'opcao_simples_nacional' => 1,
            'enforce_item_federal_taxes' => true,
            'federal_piscofins_situacao_tributaria' => '1',
            'federal_piscofins_tipo_retencao' => '3',
        ]);

        self::assertSame('blocked', $result['status']);
        self::assertSame('missing_federal_taxes', $result['reason']);
        self::assertContains('pis', $result['details']);
        self::assertContains('cofins', $result['details']);
        self::assertContains('csll', $result['details']);
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

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Application\CommercialFiscalReviewReport;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\InvoiceFiscalContextResolver;
use Tests\Feature\FeatureTestCase;

final class CommercialFiscalReviewReportTest extends FeatureTestCase
{
    public function testOnlyOwnCompanyInvoicesFromCommercialMonthAreReviewedWithoutFiscalMutation(): void
    {
        $this->loginAs();
        $own = $this->invoice('2026-10-05');
        $foreign = $this->invoice('2026-10-05');
        $foreign->forceFill(['company_id' => (int) company_id() + 1000])->saveQuietly();
        $outside = $this->invoice('2026-09-30');

        $rows = (new CommercialFiscalReviewReport())->forInvoiceMonth((int) company_id(), '2026-10')['rows'];
        $ids = array_column($rows, 'invoice_id');
        self::assertContains($own->id, $ids);
        self::assertNotContains($foreign->id, $ids);
        self::assertNotContains($outside->id, $ids);
        self::assertSame('not_linked', collect($rows)->firstWhere('invoice_id', $own->id)['status']);
        self::assertSame(0, NfseReceipt::query()->count());
    }

    public function testPendingAndCancelledReceiptRemainUnresolvedRatherThanAuthorized(): void
    {
        $this->loginAs();
        $pending = $this->invoice('2026-10-04');
        $cancelled = $this->invoice('2026-10-05');
        $this->receipt($pending, 'processing');
        $this->receipt($cancelled, 'cancelled');
        $rows = collect((new CommercialFiscalReviewReport())->forInvoiceMonth((int) company_id(), '2026-10')['rows']);
        self::assertSame('reconciliation_required', $rows->firstWhere('invoice_id', $pending->id)['status']);
        self::assertSame('review_required', $rows->firstWhere('invoice_id', $cancelled->id)['status']);
    }

    public function testOneAuthorizedReceiptCannotCompleteTwoFiscalGroups(): void
    {
        $this->loginAs();
        $invoice = $this->invoice('2026-10-07');
        $invoice->items()->delete();
        foreach (['0107' => '010701', '0101' => '010101'] as $service => $national) {
            $item = Item::factory()->enabled()->create(['company_id' => (int) company_id()]);
            $invoice->items()->create([
                'company_id' => (int) company_id(), 'type' => 'invoice', 'item_id' => $item->id,
                'name' => 'Synthetic service', 'quantity' => '1', 'price' => '50.00',
                'total' => '50.00', 'tax' => '0.00', 'discount_rate' => '0.00',
                'discount_type' => 'normal',
            ]);
            ItemFiscalProfile::query()->create([
                'company_id' => (int) company_id(), 'item_id' => $item->id,
                'item_lista_servico' => $service, 'codigo_tributacao_nacional' => $national,
            ]);
        }
        $invoice->unsetRelation('items');
        $groups = (new InvoiceFiscalContextResolver())->groups($invoice);
        self::assertCount(2, $groups);
        $this->receipt($invoice, 'emitted', $groups[0]['key']);

        $rows = collect((new CommercialFiscalReviewReport())->forInvoiceMonth((int) company_id(), '2026-10')['rows']);
        self::assertSame('partially_linked', $rows->firstWhere('invoice_id', $invoice->id)['status']);
    }

    public function testNoCompanyIdFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CommercialFiscalReviewReport())->forInvoiceMonth(0, '2026-10');
    }

    private function invoice(string $date): Document
    {
        return Document::factory()->invoice()->create([
            'company_id' => (int) company_id(), 'issued_at' => $date . ' 12:00:00',
        ]);
    }

    private function receipt(Document $invoice, string $status, ?string $groupKey = null): void
    {
        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id, 'status' => $status,
            'nfse_number' => 'SYNTH-' . $invoice->id,
            'chave_acesso' => str_repeat('9', 50),
            'competence_date' => '2026-10-07', 'emission_group_key' => $groupKey,
        ]);
    }
}

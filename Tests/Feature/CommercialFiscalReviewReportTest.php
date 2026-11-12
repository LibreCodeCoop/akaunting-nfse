<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Item;
use App\Models\Document\Document;
use Modules\Nfse\Application\CommercialFiscalReviewReport;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseEmissionAttempt;
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
        self::assertSame('outside_issuance_scope', collect($rows)->firstWhere('invoice_id', $own->id)['status']);
        self::assertSame(0, NfseReceipt::query()->count());

        $this->get(route('nfse.closing.index', ['competence' => '2026-10']))
            ->assertOk()->assertSee('fiscal-closing-browser.js');
        $this->get(route('nfse.closing.data', ['competence' => '2026-10']))
            ->assertOk()
            ->assertJsonPath('data.summary.documents.emitted', 0)
            ->assertJsonPath('data.commercial_review.rows.0.status', 'outside_issuance_scope');
    }

    public function testCommercialReportShowsDecisionWithoutAddingAuthorizedFiscalTotals(): void
    {
        $this->loginAs();
        $invoice = $this->invoice('2026-10-11');
        (new \Modules\Nfse\Application\InvoiceFiscalDecision())->record(
            $invoice, (int) company_id(), (int) auth()->id(),
            'not_applicable', 'tax_recovery', 'Synthetic tax recovery unrelated to service.',
            'TEST-TAX-RECOVERY-2026',
        );

        $row = collect((new CommercialFiscalReviewReport())->forInvoiceMonth(
            (int) company_id(), '2026-10',
        )['rows'])->firstWhere('invoice_id', $invoice->id);
        self::assertSame('not_applicable', $row['status']);
        self::assertSame('not_applicable', $row['fiscal_decision']);
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

    public function testUnresolvedDpsAttemptsWithoutReceiptsRequireFiscalReconciliation(): void
    {
        $this->loginAs();
        $ambiguous = $this->invoice('2026-10-08');
        $pending = $this->invoice('2026-10-09');
        $resolved = $this->invoice('2026-10-10');
        $foreign = $this->invoice('2026-10-11');
        $foreign->forceFill(['company_id' => (int) company_id() + 1000])->saveQuietly();

        $this->attempt($ambiguous, 'ambiguous');
        $this->attempt($pending, 'pending');
        $this->attempt($resolved, 'technical_failure');
        $this->attempt($foreign, 'ambiguous');

        $rows = collect((new CommercialFiscalReviewReport())->forInvoiceMonth((int) company_id(), '2026-10')['rows']);
        self::assertSame('reconciliation_required', $rows->firstWhere('invoice_id', $ambiguous->id)['status']);
        self::assertSame('reconciliation_required', $rows->firstWhere('invoice_id', $pending->id)['status']);
        self::assertSame('outside_issuance_scope', $rows->firstWhere('invoice_id', $resolved->id)['status']);
        self::assertNull($rows->firstWhere('invoice_id', $foreign->id));

        $response = $this->get(route('nfse.closing.data', ['competence' => '2026-10']))->assertOk();
        $apiRows = collect($response->json('data.commercial_review.rows'));
        self::assertSame('reconciliation_required', $apiRows->firstWhere('invoice_id', $ambiguous->id)['status']);
        self::assertSame('reconciliation_required', $apiRows->firstWhere('invoice_id', $pending->id)['status']);
        self::assertSame(0, NfseReceipt::query()->count());
        self::assertSame(4, NfseEmissionAttempt::query()->count());
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

    private function attempt(Document $invoice, string $status): void
    {
        NfseEmissionAttempt::query()->create([
            'company_id' => (int) $invoice->company_id,
            'invoice_id' => (int) $invoice->id,
            'dps_identifier' => 'SYNTH-REVIEW-' . $invoice->id,
            'environment' => 2,
            'attempt_number' => 1,
            'payload_fingerprint' => str_repeat('a', 64),
            'municipio_ibge' => '3304557',
            'codigo_tributacao_nacional' => '010701',
            'codigo_tributacao_municipal' => '000',
            'codigo_servico' => '010701000',
            'competence_date' => '2026-10-08',
            'origin' => 'manual',
            'status' => $status,
            'started_at' => now(),
        ]);
    }
}

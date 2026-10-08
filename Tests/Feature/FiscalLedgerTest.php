<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Models\NfseReceiptPayload;
use Tests\Feature\FeatureTestCase;

final class FiscalLedgerTest extends FeatureTestCase
{
    public function testLedgerOnlyShowsReceiptsFromCurrentCompanyIncludingMultipleForOneInvoice(): void
    {
        $this->loginAs();

        $currentCompanyId = (int) company_id();
        $invoice = Document::factory()->invoice()->create(['company_id' => $currentCompanyId]);
        $otherInvoice = Document::factory()->invoice()->create(['company_id' => $currentCompanyId]);
        $otherInvoice->forceFill(['company_id' => $currentCompanyId + 1000])->saveQuietly();

        $original = $this->receipt($invoice, '81001', 'cancelled');
        $replacement = $this->receipt($invoice, '81002', 'emitted');
        $foreign = $this->receipt($otherInvoice, '81003', 'emitted');

        $response = $this->get(route('nfse.ledger.index'));

        $response->assertOk();
        $response->assertViewHas('receipts', static function ($receipts) use ($original, $replacement, $foreign): bool {
            $ids = $receipts->getCollection()->pluck('id')->all();

            return in_array($original->id, $ids, true)
                && in_array($replacement->id, $ids, true)
                && !in_array($foreign->id, $ids, true);
        });
    }

    public function testLedgerFiltersFiscalStatusBeforePagination(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $cancelled = $this->receipt($invoice, '82001', 'cancelled');
        $emitted = $this->receipt($invoice, '82002', 'emitted');

        $response = $this->get(route('nfse.ledger.index', ['status' => 'cancelled']));

        $response->assertOk();
        $response->assertViewHas('receipts', static function ($receipts) use ($cancelled, $emitted): bool {
            $ids = $receipts->getCollection()->pluck('id')->all();

            return in_array($cancelled->id, $ids, true) && !in_array($emitted->id, $ids, true);
        });
    }

    public function testLedgerCombinesFiscalStatusAndReceiptNumberSearch(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $match = $this->receipt($invoice, '85001', 'emitted');
        $this->receipt($invoice, '85002', 'emitted');
        $this->receipt($invoice, '85001-OLD', 'cancelled');

        $response = $this->get(route('nfse.ledger.index', [
            'status' => 'emitted',
            'search' => '85001',
        ]));

        $response->assertOk();
        $response->assertViewHas('receipts', static function ($receipts) use ($match): bool {
            $ids = $receipts->getCollection()->pluck('id')->all();

            return $ids === [$match->id];
        });
    }

    public function testLedgerDownloadsXmlFromSelectedReceiptInsteadOfLatestInvoiceReceipt(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $original = $this->receipt($invoice, '83001', 'emitted');
        $this->receipt($invoice, '83002', 'emitted');

        NfseReceiptPayload::query()->create([
            'receipt_id' => $original->id,
            'authorized_xml' => '<nfse>original-receipt</nfse>',
        ]);

        $response = $this->get(route('nfse.ledger.artifacts.download', [
            'receipt' => $original->id,
            'artifact' => 'xml',
        ]));

        $response->assertOk();
        self::assertSame('<nfse>original-receipt</nfse>', $response->getContent());
    }

    public function testLedgerRejectsForeignCompanyReceiptArtifact(): void
    {
        $this->loginAs();

        $otherInvoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $otherInvoice->forceFill(['company_id' => (int) company_id() + 1000])->saveQuietly();
        $receipt = $this->receipt($otherInvoice, '84001', 'emitted');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->get(route('nfse.ledger.artifacts.download', [
            'receipt' => $receipt->id,
            'artifact' => 'xml',
        ]));
    }

    public function testLedgerFiltersByIssueDateRange(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $inside = $this->receipt($invoice, '86001', 'emitted');
        $outside = $this->receipt($invoice, '86002', 'emitted');
        $inside->forceFill(['data_emissao' => '2026-06-15 12:00:00'])->save();
        $outside->forceFill(['data_emissao' => '2026-07-15 12:00:00'])->save();

        $response = $this->get(route('nfse.ledger.index', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
        ]));

        $response->assertOk();
        $response->assertViewHas('receipts', static function ($receipts) use ($inside, $outside): bool {
            $ids = $receipts->getCollection()->pluck('id')->all();

            return in_array($inside->id, $ids, true) && !in_array($outside->id, $ids, true);
        });
    }

    private function receipt(Document $invoice, string $number, string $status): NfseReceipt
    {
        return NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => $number,
            'chave_acesso' => str_repeat(substr($number, -1), 50),
            'status' => $status,
        ]);
    }
}

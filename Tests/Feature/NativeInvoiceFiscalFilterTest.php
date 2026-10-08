<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class NativeInvoiceFiscalFilterTest extends FeatureTestCase
{
    public function testNativeInvoiceFilterUsesLatestReceipt(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $excluded = Document::factory()->invoice()->create(['company_id' => company_id()]);

        $this->receipt($invoice, 'cancelled');
        $this->receipt($invoice, 'emitted');
        $this->receipt($excluded, 'cancelled');

        $response = $this->get(route('invoices.index', ['nfse_status' => 'emitted']));

        $response->assertOk();
        $response->assertViewHas('invoices', static function ($invoices) use ($invoice, $excluded): bool {
            $ids = $invoices->getCollection()->pluck('id')->all();

            return in_array($invoice->id, $ids, true)
                && !in_array($excluded->id, $ids, true);
        });
    }

    public function testNativeInvoiceFilterForAbsentReceiptDoesNotIncludeIssuedInvoice(): void
    {
        $this->loginAs();

        $withoutReceipt = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $withReceipt = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $this->receipt($withReceipt, 'emitted');

        $response = $this->get(route('invoices.index', ['nfse_status' => 'absent']));

        $response->assertOk();
        $response->assertViewHas('invoices', static function ($invoices) use ($withoutReceipt, $withReceipt): bool {
            $ids = $invoices->getCollection()->pluck('id')->all();

            return in_array($withoutReceipt->id, $ids, true)
                && !in_array($withReceipt->id, $ids, true);
        });
    }

    public function testNativeFiscalFilterRespectsNativePagination(): void
    {
        $this->loginAs();

        $first = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $second = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $excluded = Document::factory()->invoice()->create(['company_id' => company_id()]);

        $this->receipt($first, 'emitted');
        $this->receipt($second, 'emitted');
        $this->receipt($excluded, 'cancelled');

        $response = $this->get(route('invoices.index', [
            'nfse_status' => 'emitted',
            'limit' => 1,
        ]));

        $response->assertOk();
        $response->assertViewHas('invoices', static function ($invoices) use ($first, $second): bool {
            $ids = $invoices->getCollection()->pluck('id')->all();
            $unexpected = array_diff($ids, [$first->id, $second->id]);

            return $unexpected === []
                && count($ids) <= $invoices->perPage()
                && $invoices->total() === 2;
        });
    }

    public function testNativeUnknownFiscalStatusDoesNotIncludeAbsentReceipts(): void
    {
        $this->loginAs();

        $unknown = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $without = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $known = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $this->receipt($unknown, 'unexpected-provider-state');
        $this->receipt($known, 'emitted');

        $response = $this->get(route('invoices.index', ['nfse_status' => 'unknown']));
        $response->assertOk();
        $response->assertViewHas('invoices', static function ($invoices) use ($unknown, $without, $known): bool {
            $ids = $invoices->getCollection()->pluck('id')->all();

            return in_array($unknown->id, $ids, true)
                && !in_array($without->id, $ids, true)
                && !in_array($known->id, $ids, true);
        });
    }

    private function receipt(Document $invoice, string $status): void
    {
        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => (string) $invoice->id . '-' . $status,
            'chave_acesso' => str_repeat('1', 50),
            'status' => $status,
        ]);
    }
}

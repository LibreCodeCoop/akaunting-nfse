<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class NativeInvoiceFiscalPanelTest extends FeatureTestCase
{
    public function testNativeInvoiceShowContainsPendingFiscalPanel(): void
    {
        $invoice = Document::factory()->invoice()->create();

        $this->loginAs()
            ->get(route('invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('data-nfse-native-panel="true"', false)
            ->assertSee(route('nfse.modals.invoices.emails.create', $invoice->id), false);
    }

    public function testNativeInvoiceShowContainsLinkedReceiptAndFiscalDetails(): void
    {
        $invoice = Document::factory()->invoice()->create();

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '901',
            'chave_acesso' => str_repeat('7', 50),
            'status' => 'emitted',
        ]);

        $this->loginAs()
            ->get(route('invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('>901</dd>', false)
            ->assertSee(str_repeat('7', 50))
            ->assertSee(route('nfse.invoices.show', $invoice->id), false);
    }

    public function testNativePanelUsesLatestFiscalReceipt(): void
    {
        $invoice = Document::factory()->invoice()->create();

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '901',
            'chave_acesso' => str_repeat('7', 50),
            'status' => 'cancelled',
        ]);

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '902',
            'chave_acesso' => str_repeat('8', 50),
            'status' => 'emitted',
        ]);

        $this->loginAs()
            ->get(route('invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('>902</dd>', false)
            ->assertDontSee('>901</dd>', false);
    }
}

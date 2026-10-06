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

        $response = $this->loginAs()
            ->get(route('invoices.show', $invoice->id));

        $response
            ->assertOk()
            ->assertSee('data-nfse-native-panel="true"', false)
            ->assertSee(route('nfse.modals.invoices.emails.create', $invoice->id), false);

        $content = $response->getContent();

        $this->assertSame(
            1,
            substr_count($content, 'data-nfse-native-panel="true"'),
            'The native NFS-e panel must only be injected once per invoice response.',
        );
        $this->assertSame(
            1,
            substr_count($content, 'data-nfse-native-emit="true"'),
            'A pending invoice must expose exactly one visible NFS-e emission action.',
        );
        $this->assertSame(
            1,
            substr_count($content, 'data-nfse-native-modal-trigger="true"'),
            'The invoice page must contain exactly one compiled bridge to the NFS-e modal.',
        );
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
            ->assertSee('901')
            ->assertSee(str_repeat('7', 50))
            ->assertSee('data-nfse-receipt-id=', false)
            ->assertSee(route('nfse.invoices.artifacts.download', [$invoice->id, 'danfse']), false)
            ->assertSee(route('nfse.invoices.artifacts.download', [$invoice->id, 'xml']), false)
            ->assertSee(route('nfse.invoices.refresh', $invoice->id), false)
            ->assertSee(route('nfse.invoices.cancel', $invoice->id), false)
            ->assertSee('name="redirect_after_cancel" value="invoice_show"', false)
            ->assertSee('name="cancel_reason"', false)
            ->assertSee('name="cancel_justification"', false);
    }

    public function testNativePanelKeepsAllReceiptsVisibleWhileLatestDrivesPrimaryActions(): void
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
            ->assertSee('902')
            ->assertSee('901')
            ->assertSee(str_repeat('8', 50))
            ->assertSee(str_repeat('7', 50))
            ->assertSee(route('nfse.invoices.substitute', $invoice->id), false);
    }
}

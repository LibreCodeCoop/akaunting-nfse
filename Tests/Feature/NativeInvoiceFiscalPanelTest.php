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
        $invoice = Document::factory()->invoice()->create(['status' => 'draft']);

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

    public function testNativeInvoiceShowSurfacesFiscalActionFeedback(): void
    {
        $invoice = Document::factory()->invoice()->create();

        $response = $this->loginAs()
            ->withSession([
                'error' => 'NFSE-FEEDBACK-ERROR',
                'nfse_gateway_error_detail' => 'NFSE-GATEWAY-DETAIL',
            ])
            ->get(route('invoices.show', $invoice->id));

        $response
            ->assertOk()
            ->assertSee('data-nfse-feedback="error"', false)
            ->assertSee('NFSE-FEEDBACK-ERROR')
            ->assertSee('NFSE-GATEWAY-DETAIL');
    }

    public function testNativeInvoiceShowContainsLinkedReceiptAndFiscalDetails(): void
    {
        $invoice = Document::factory()->invoice()->create();

        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '901',
            'chave_acesso' => str_repeat('7', 50),
            'status' => 'emitted',
            'xml_webdav_path' => 'nfse/901.xml',
            'danfse_webdav_path' => 'nfse/901.pdf',
        ]);

        $this->loginAs()
            ->get(route('invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('901')
            ->assertSee(str_repeat('7', 50))
            ->assertSee('data-nfse-receipt-id=', false)
            ->assertSee(route('nfse.ledger.artifacts.download', ['receipt' => $receipt->id, 'artifact' => 'danfse']), false)
            ->assertSee(route('nfse.ledger.artifacts.download', ['receipt' => $receipt->id, 'artifact' => 'xml']), false)
            ->assertSee(route('nfse.invoices.refresh', $invoice->id), false)
            ->assertSee(route('nfse.invoices.cancel', $invoice->id), false)
            ->assertSee('name="redirect_after_cancel" value="invoice_show"', false)
            ->assertSee('name="cancel_reason"', false)
            ->assertSee('name="cancel_justification"', false);
    }

    public function testNativePanelRendersIndependentCopyActionsForEachReceipt(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $previous = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '951',
            'chave_acesso' => str_repeat('1', 50),
            'status' => 'cancelled',
        ]);
        $latest = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '952',
            'chave_acesso' => str_repeat('2', 50),
            'status' => 'emitted',
        ]);

        $content = $this->loginAs()->get(route('invoices.show', $invoice->id))
            ->assertOk()
            ->getContent();

        self::assertSame(2, substr_count($content, 'data-nfse-copy-access-key="true"'));
        self::assertSame(2, substr_count($content, 'data-nfse-access-key'));
        self::assertSame(1, substr_count($content, 'data-nfse-receipt-actions-module="true"'));
        self::assertStringContainsString('data-nfse-receipt-id="' . $previous->id . '"', $content);
        self::assertStringContainsString('data-nfse-receipt-id="' . $latest->id . '"', $content);
        self::assertStringContainsString(str_repeat('1', 50), $content);
        self::assertStringContainsString(str_repeat('2', 50), $content);
    }

    public function testNativePanelDoesNotOfferCopyActionForMissingAccessKey(): void
    {
        $invoice = Document::factory()->invoice()->create();
        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '953',
            'chave_acesso' => null,
            'status' => 'emitted',
        ]);

        $content = $this->loginAs()->get(route('invoices.show', $invoice->id))
            ->assertOk()
            ->getContent();

        self::assertStringNotContainsString('data-nfse-copy-access-key="true"', $content);
        self::assertStringContainsString('data-nfse-access-key', $content);
    }

    public function testNativePanelHidesArtifactActionsWhenNoArtifactExistsAndNothingIsProcessing(): void
    {
        $invoice = Document::factory()->invoice()->draft()->create();

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '903',
            'chave_acesso' => str_repeat('9', 50),
            'status' => 'emitted',
            'xml_webdav_path' => null,
            'danfse_webdav_path' => null,
        ]);

        $response = $this->loginAs()->get(route('invoices.show', $invoice->id));

        $response
            ->assertOk()
            ->assertDontSee('data-nfse-artifact="xml"', false)
            ->assertDontSee('data-nfse-artifact="danfse"', false);
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

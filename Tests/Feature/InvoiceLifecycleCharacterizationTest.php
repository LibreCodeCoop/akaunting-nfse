<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class InvoiceLifecycleCharacterizationTest extends FeatureTestCase
{
    public function testNativeInvoiceShowKeepsAkauntingPageAndOverridesEmailRouteForFiscalFlow(): void
    {
        $invoice = Document::factory()->invoice()->items()->create();

        $this->loginAs()
            ->get(route('invoices.show', $invoice))
            ->assertOk();

        self::assertSame(
            'nfse.modals.invoices.emails.create',
            config('type.document.invoice.route.emails.create'),
        );
    }

    public function testPendingInvoiceUsesIssueModalThroughNativeEmailAction(): void
    {
        $invoice = Document::factory()->invoice()->items()->create();

        $response = $this->loginAs()
            ->get(route('nfse.modals.invoices.emails.create', $invoice))
            ->assertOk()
            ->json();

        self::assertTrue((bool) ($response['success'] ?? false));
        self::assertStringContainsString(
            (string) trans('nfse::general.invoices.emit_modal_title'),
            (string) ($response['data']['title'] ?? ''),
        );
        self::assertStringContainsString(
            'nfse_discriminacao_custom',
            (string) ($response['html'] ?? ''),
        );
    }

    public function testEmittedInvoiceUsesCancelModalThroughNativeEmailAction(): void
    {
        $invoice = Document::factory()->invoice()->items()->create();

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '1001',
            'chave_acesso' => str_repeat('1', 50),
            'status' => 'emitted',
        ]);

        $response = $this->loginAs()
            ->get(route('nfse.modals.invoices.emails.create', $invoice))
            ->assertOk()
            ->json();

        self::assertTrue((bool) ($response['success'] ?? false));
        self::assertStringContainsString(
            (string) trans('nfse::general.invoices.cancel_modal_title'),
            (string) ($response['data']['title'] ?? ''),
        );
        self::assertStringContainsString(
            (string) trans('nfse::general.invoices.cancel_modal_submit'),
            (string) ($response['data']['buttons']['confirm']['text'] ?? ''),
        );
    }

    public function testInvoiceWithoutItemsDoesNotTakeOverNativeSendFlow(): void
    {
        $invoice = Document::factory()->invoice()->create();

        $originalRoute = config('type.document.invoice.route.emails.create');

        $this->loginAs()
            ->get(route('invoices.show', $invoice))
            ->assertOk();

        self::assertSame(
            $originalRoute,
            config('type.document.invoice.route.emails.create'),
        );
    }
}

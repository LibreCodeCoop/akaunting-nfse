<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Tests\Support\FiscalScenarioBuilder;
use Tests\Feature\FeatureTestCase;

final class InvoiceLifecycleCharacterizationTest extends FeatureTestCase
{
    public function testNativeInvoiceShowKeepsAkauntingPageAndOverridesEmailRouteForFiscalFlow(): void
    {
        $invoice = FiscalScenarioBuilder::invoice();

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
        $invoice = FiscalScenarioBuilder::invoice();

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
        $invoice = FiscalScenarioBuilder::invoice();

        FiscalScenarioBuilder::receipt(
            invoice: $invoice,
            status: 'emitted',
            number: '1001',
            accessKey: str_repeat('1', 50),
        );

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

    public function testCancelledInvoiceUsesReemitActionThroughNativeEmailFlow(): void
    {
        $invoice = FiscalScenarioBuilder::invoice();

        FiscalScenarioBuilder::receipt(
            invoice: $invoice,
            status: 'cancelled',
            number: '1002',
            accessKey: str_repeat('2', 50),
        );

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
            (string) trans('nfse::general.invoices.reemit'),
            (string) ($response['data']['buttons']['confirm']['text'] ?? ''),
        );
    }

    public function testFiscalInvoiceShowRendersPersistedReceiptIdentity(): void
    {
        $invoice = FiscalScenarioBuilder::invoice();

        FiscalScenarioBuilder::receipt(
            invoice: $invoice,
            status: 'emitted',
            number: '2026',
            accessKey: str_repeat('3', 50),
        );

        $this->loginAs()
            ->get(route('nfse.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('2026')
            ->assertSee(str_repeat('3', 50));
    }

    public function testInvalidArtifactTypeRedirectsWithoutAttemptingRemoteRead(): void
    {
        $invoice = FiscalScenarioBuilder::invoice();

        FiscalScenarioBuilder::receipt(
            invoice: $invoice,
            status: 'emitted',
            number: '1003',
            accessKey: str_repeat('4', 50),
        );

        $this->loginAs()
            ->get(route('nfse.invoices.artifacts.download', [
                'invoice' => $invoice,
                'artifact' => 'unknown',
            ]))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('warning', trans('nfse::general.invoices.artifact_invalid_type'));
    }

    public function testMissingArtifactRedirectsWithActionableWarning(): void
    {
        $invoice = FiscalScenarioBuilder::invoice();

        FiscalScenarioBuilder::receipt(
            invoice: $invoice,
            status: 'emitted',
            number: '1004',
            accessKey: str_repeat('5', 50),
        );

        $this->loginAs()
            ->get(route('nfse.invoices.artifacts.download', [
                'invoice' => $invoice,
                'artifact' => 'xml',
            ]))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('warning', trans('nfse::general.invoices.artifact_not_found'));
    }

    public function testInvoiceWithoutItemsDoesNotTakeOverNativeSendFlow(): void
    {
        $invoice = FiscalScenarioBuilder::invoice();
        $invoice->items()->delete();
        $invoice->unsetRelation('items');

        $originalRoute = config('type.document.invoice.route.emails.create');

        $this->loginAs()
            ->get(route('invoices.show', $invoice))
            ->assertOk();

        self::assertSame(
            $originalRoute,
            config('type.document.invoice.route.emails.create'),
        );
    }

    public function testEmitWithoutItemsIsRejectedBeforeFiscalTransport(): void
    {
        $invoice = FiscalScenarioBuilder::invoice();
        $invoice->items()->delete();
        $invoice->unsetRelation('items');

        $this->loginAs()
            ->post(route('nfse.invoices.emit', $invoice))
            ->assertRedirect(route('nfse.invoices.index', ['status' => 'pending']))
            ->assertSessionHas('error', trans('nfse::general.invoices.emit_blocked_no_items'));

        self::assertFalse(
            NfseReceipt::query()
                ->where('invoice_id', $invoice->id)
                ->exists(),
            'Emission preflight must not create a fiscal receipt when the invoice has no items.',
        );
    }
}

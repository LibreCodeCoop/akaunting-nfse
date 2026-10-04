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
        $invoice = Document::factory()->invoice()->create();

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
        $invoice = Document::factory()->invoice()->create();

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
        $invoice = Document::factory()->invoice()->create();

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

    public function testCancelledInvoiceUsesReemitActionThroughNativeEmailFlow(): void
    {
        $invoice = Document::factory()->invoice()->create();

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '1002',
            'chave_acesso' => str_repeat('2', 50),
            'status' => 'cancelled',
        ]);

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
        $invoice = Document::factory()->invoice()->create();

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '2026',
            'chave_acesso' => str_repeat('3', 50),
            'status' => 'emitted',
        ]);

        $this->loginAs()
            ->get(route('nfse.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('2026')
            ->assertSee(str_repeat('3', 50));
    }

    public function testInvalidArtifactTypeRedirectsWithoutAttemptingRemoteRead(): void
    {
        $invoice = Document::factory()->invoice()->create();

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '1003',
            'chave_acesso' => str_repeat('4', 50),
            'status' => 'emitted',
        ]);

        $this->loginAs()
            ->get(route('nfse.invoices.artifacts.download', [
                'invoice' => $invoice,
                'artifact' => 'unknown',
            ]))
            ->assertRedirect(route('nfse.invoices.show', $invoice))
            ->assertSessionHas('warning', trans('nfse::general.invoices.artifact_invalid_type'));
    }

    public function testMissingArtifactRedirectsWithActionableWarning(): void
    {
        $invoice = Document::factory()->invoice()->create();

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '1004',
            'chave_acesso' => str_repeat('5', 50),
            'status' => 'emitted',
            'xml_path' => '',
            'danfse_path' => '',
        ]);

        $this->loginAs()
            ->get(route('nfse.invoices.artifacts.download', [
                'invoice' => $invoice,
                'artifact' => 'xml',
            ]))
            ->assertRedirect(route('nfse.invoices.show', $invoice))
            ->assertSessionHas('warning', trans('nfse::general.invoices.artifact_not_found'));
    }

    public function testInvoiceWithoutItemsDoesNotTakeOverNativeSendFlow(): void
    {
        $invoice = Document::factory()->invoice()->create();
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
        $invoice = Document::factory()->invoice()->create();
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

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document as Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class FiscalInvoiceMutationApiTest extends FeatureTestCase
{
    public function testFiscalMutationsShareNativeAndBrowserHandlersWithWriteAcl(): void
    {
        foreach (['cancel', 'reemit', 'substitute', 'refresh'] as $action) {
            $native = Route::getRoutes()->getByName('api.nfse.invoices.' . $action);
            $browser = Route::getRoutes()->getByName('nfse.invoices.' . $action);
            self::assertNotNull($native, $action);
            self::assertNotNull($browser, $action);
            self::assertContains('api', $native->gatherMiddleware());
            self::assertContains('permission:update-sales-invoices', $native->gatherMiddleware());
            self::assertSame($browser->getActionName(), $native->getActionName());
        }
    }

    public function testNativeCommandsRejectMissingAndStaleReceiptBeforeGatewayAccess(): void
    {
        $this->loginAs();
        $invoice = Invoice::factory()->invoice()->create(['company_id' => (int) company_id()]);
        $old = $this->receipt($invoice, 'emitted');
        $current = $this->receipt($invoice, 'emitted');

        foreach ([
            ['cancel', 'DELETE', ['nfse_receipt_id' => $old->id]],
            ['cancel', 'DELETE', []],
            ['refresh', 'POST', ['nfse_receipt_id' => $old->id]],
            ['refresh', 'POST', []],
            ['reemit', 'POST', ['nfse_receipt_id' => $old->id]],
            ['reemit', 'POST', []],
            ['substitute', 'POST', ['nfse_substitution_receipt_id' => $old->id]],
            ['substitute', 'POST', []],
        ] as [$action, $method, $fields]) {
            $request = $this->nativeRequest($action, $invoice, $method, $fields);
            $original = app('request');
            app()->instance('request', $request);
            try {
                $response = (new InvoiceController())->{$action}($invoice, $request);
            } finally {
                app()->instance('request', $original);
            }
            self::assertSame(409, $response->status(), $action);
            self::assertSame('not_confirmed', $response->getData(true)['status'], $action);
        }

        self::assertSame('emitted', $current->fresh()->status);
        self::assertSame('emitted', $old->fresh()->status);
        $this->assertDatabaseCount('nfse_emission_attempts', 0);
    }

    public function testNativeReemitCannotIssueAnAlreadyAuthorizedReceipt(): void
    {
        $this->loginAs();
        $invoice = Invoice::factory()->invoice()->create(['company_id' => (int) company_id()]);
        $receipt = $this->receipt($invoice, 'emitted');
        $request = $this->nativeRequest('reemit', $invoice, 'POST', ['nfse_receipt_id' => $receipt->id]);
        $original = app('request');
        app()->instance('request', $request);
        try {
            $result = (new InvoiceController())->reemit($invoice, $request);
        } finally {
            app()->instance('request', $original);
        }

        self::assertSame(409, $result->status());
        $this->assertDatabaseCount('nfse_emission_attempts', 0);
    }

    public function testNativeMutationsCannotTouchForeignOrNonSalesDocuments(): void
    {
        $this->withExceptionHandling()->loginAs();
        $id = (int) company_id();
        $foreign = Invoice::factory()->invoice()->create(['company_id' => $id]);
        $foreign->forceFill(['company_id' => $id + 1000])->saveQuietly();
        $bill = Invoice::factory()->bill()->create(['company_id' => $id]);

        foreach (['cancel', 'refresh', 'reemit', 'substitute'] as $action) {
            foreach ([$foreign->id, $bill->id] as $documentId) {
                $url = route('nfse.invoices.' . $action, $documentId);
                $result = $action === 'cancel'
                    ? $this->deleteJson($url)
                    : $this->postJson($url);
                $result->assertNotFound();
            }
        }
        $this->assertDatabaseCount('nfse_emission_attempts', 0);
    }

    private function receipt(Invoice $invoice, string $state): NfseReceipt
    {
        return NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => 'SYNTHETIC-TRANSITION-' . bin2hex(random_bytes(3)),
            'chave_acesso' => str_repeat('3', 50),
            'status' => $state,
        ]);
    }

    /** @param array<string,mixed> $fields */
    private function nativeRequest(string $action, Invoice $invoice, string $method, array $fields): Request
    {
        $name = 'api.nfse.invoices.' . $action;
        $url = route($name, [
            'company_id' => $invoice->company_id,
            'invoice' => $invoice->id,
        ]);
        $request = Request::create($url, $method, $fields);
        $route = clone Route::getRoutes()->getByName($name);
        $route->bind($request);
        $route->setParameter('invoice', $invoice);
        $request->setRouteResolver(static fn () => $route);
        return $request;
    }
}

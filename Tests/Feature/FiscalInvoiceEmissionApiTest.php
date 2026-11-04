<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document as Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Tests\Feature\FeatureTestCase;

final class FiscalInvoiceEmissionApiTest extends FeatureTestCase
{
    public function testNativeEmissionUsesExistingManualHandlerAndCoreWritePermission(): void
    {
        $api = Route::getRoutes()->getByName('api.nfse.invoices.emit');
        $web = Route::getRoutes()->getByName('nfse.invoices.emit');

        self::assertNotNull($api);
        self::assertNotNull($web);
        self::assertContains('api', $api->gatherMiddleware());
        self::assertContains('permission:update-sales-invoices', $api->gatherMiddleware());
        self::assertSame($web->getActionName(), $api->getActionName());
    }

    public function testForeignCompanyOrFinancialBillIsRejectedBeforeFiscalSideEffects(): void
    {
        $this->loginAs();
        $id = (int) company_id();
        $foreign = Invoice::factory()->invoice()->create(['company_id' => $id]);
        $foreign->forceFill(['company_id' => $id + 1000])->saveQuietly();
        $bill = Invoice::factory()->bill()->create(['company_id' => $id]);

        $this->withExceptionHandling();
        $this->postJson(route('nfse.invoices.emit', $foreign->id), [])->assertNotFound();
        $this->postJson(route('nfse.invoices.emit', $bill->id), [])->assertNotFound();
        $this->assertDatabaseCount('nfse_emission_attempts', 0);
    }

    public function testNativePreflightFailureDoesNotReturnHttpSuccessOrTransmit(): void
    {
        $this->loginAs();
        $invoice = Invoice::factory()->invoice()->create(['company_id' => (int) company_id()]);

        // The real HTTP controller is reused by both transports. Force an
        // issuer readiness failure before any client/SEFIN communication.
        $controller = new class extends InvoiceController {
            protected function bindIssuerFiscalParameters(Invoice $invoice): array
            {
                throw new \DomainException('Synthetic issuer is not ready.');
            }
        };

        $realRequest = app('request');
        $nativeRequest = Request::create(route('api.nfse.invoices.emit', [
            'company_id' => $invoice->company_id, 'invoice' => $invoice->id,
        ]), 'POST');
        $nativeRoute = clone Route::getRoutes()->getByName('api.nfse.invoices.emit');
        $nativeRoute->setParameter('invoice', $invoice);
        $nativeRequest->setRouteResolver(static fn () => $nativeRoute);
        app()->instance('request', $nativeRequest);
        try {
            $response = $controller->emit($invoice, $nativeRequest);
        } finally {
            app()->instance('request', $realRequest);
        }

        self::assertSame(409, $response->getStatusCode());
        $payload = $response->getData(true);
        self::assertFalse($payload['success']);
        self::assertSame('not_confirmed', $payload['status']);
        self::assertStringContainsString('/api/nfse/invoices/', $payload['links']['status']);
        self::assertStringContainsString('/emission-attempts', $payload['links']['attempts']);
        $this->assertDatabaseCount('nfse_receipts', 0);
        $this->assertDatabaseCount('nfse_emission_attempts', 0);
    }
}

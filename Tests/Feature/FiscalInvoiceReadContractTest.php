<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Jobs\Auth\CreateUser;
use App\Models\Document\Document as Invoice;
use App\Traits\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class FiscalInvoiceReadContractTest extends FeatureTestCase
{
    use Permissions;

    public function testNativeAndBrowserRoutesHaveIdenticalInvoiceReadPolicy(): void
    {
        foreach ([
            'api.nfse.invoices.service-preview',
            'api.nfse.invoices.post-emission-status',
            'nfse.invoices.service-preview',
            'nfse.invoices.post-emission-status',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertContains('permission:read-sales-invoices', $route->gatherMiddleware(), $name);
        }
        foreach ([
            'api.nfse.invoices.service-preview',
            'api.nfse.invoices.post-emission-status',
        ] as $name) {
            self::assertContains('api', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function testAdminCanPreviewAndObserveUnissuedInvoiceWithoutMutation(): void
    {
        $this->loginAs();
        $invoice = Invoice::factory()->invoice()->create(['company_id' => (int) company_id()]);

        $this->getJson(route('nfse.invoices.service-preview', $invoice->id))
            ->assertOk()
            ->assertJsonStructure(['fiscal_groups', 'fiscal_profile_validation', 'email_defaults']);
        $this->getJson(route('nfse.invoices.post-emission-status', $invoice->id))
            ->assertOk()
            ->assertJsonPath('data.status', 'idle')
            ->assertJsonPath('data.receipt_id', null)
            ->assertJsonPath('data.poll', false);

        $this->assertDatabaseCount('nfse_receipts', 0);
    }

    public function testBothReadPathsRejectForeignInvoicesAndFinancialBills(): void
    {
        $this->loginAs();
        $companyId = (int) company_id();
        $foreign = Invoice::factory()->invoice()->create(['company_id' => $companyId]);
        $foreign->forceFill(['company_id' => $companyId + 1000])->saveQuietly();
        $bill = Invoice::factory()->bill()->create(['company_id' => $companyId]);

        $this->withExceptionHandling();
        foreach ([$foreign->id, $bill->id] as $id) {
            $this->getJson(route('nfse.invoices.service-preview', $id))->assertNotFound();
            $this->getJson(route('nfse.invoices.post-emission-status', $id))->assertNotFound();
        }
    }

    public function testReadPermissionIsNotImpliedByAdminShellAccess(): void
    {
        $this->loginAs();
        $invoice = Invoice::factory()->invoice()->create(['company_id' => (int) company_id()]);
        $role = $this->createRole('nfse-preview-no-invoice-read');
        $this->attachPermission($role, 'read-admin-panel');
        $user = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$this->company->id],
            'roles' => [$role->id],
        ])));

        $this->withExceptionHandling()->loginAs($user);
        $this->getJson(route('nfse.invoices.service-preview', $invoice->id))->assertForbidden();
        $this->getJson(route('nfse.invoices.post-emission-status', $invoice->id))->assertForbidden();
    }

    public function testNativeStatusReferencesReceiptScopedApiArtifactInsteadOfBrowserSessionUrl(): void
    {
        $this->loginAs();
        $invoice = Invoice::factory()->invoice()->create(['company_id' => (int) company_id()]);
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => 'SYNTHETIC-NFSE-504',
            'status' => 'emitted',
            'xml_webdav_path' => 'synthetic/nfse/504.xml',
            'danfse_webdav_path' => 'synthetic/nfse/504.pdf',
        ]);

        $original = app('request');
        $native = Request::create(route('api.nfse.invoices.post-emission-status', [
            'company_id' => $invoice->company_id, 'invoice' => $invoice->id,
        ]));
        $nativeRoute = Route::getRoutes()->getByName('api.nfse.invoices.post-emission-status');
        $native->setRouteResolver(static fn () => $nativeRoute);
        app()->instance('request', $native);
        try {
            $payload = (new InvoiceController())->postEmissionStatus($invoice)->getData(true);
        } finally {
            app()->instance('request', $original);
        }
        self::assertSame((int) $receipt->id, $payload['data']['receipt_id']);
        foreach (['xml', 'danfse'] as $kind) {
            self::assertTrue($payload['data']['artifacts'][$kind]['ready']);
            self::assertSame(
                route('api.nfse.receipts.artifacts.download', [
                    'company_id' => $invoice->company_id,
                    'receipt' => $receipt->id,
                    'artifact' => $kind,
                ]),
                $payload['data']['artifacts'][$kind]['download_url'],
            );
        }
    }
}

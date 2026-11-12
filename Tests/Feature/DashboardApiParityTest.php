<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document as Invoice;
use Illuminate\Support\Facades\Route;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class DashboardApiParityTest extends FeatureTestCase
{
    public function testDashboardShellDoesNotEmbedReceiptDataAndJsonPreservesTenantScope(): void
    {
        $this->loginAs();
        $companyId = (int) company_id();
        $invoice = Invoice::factory()->invoice()->create(['company_id' => $companyId]);
        NfseReceipt::query()->create([
            'invoice_id' => (int) $invoice->id,
            'nfse_number' => 'SYNTHETIC-DASHBOARD-2026',
            'status' => 'emitted',
        ]);

        $this->get(route('nfse.dashboard.index'))
            ->assertOk()
            ->assertSee('id="nfse-dashboard-receipts"', false)
            ->assertDontSee('SYNTHETIC-DASHBOARD-2026');

        $this->getJson(route('nfse.dashboard.data'))
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'stats' => ['total', 'emitted', 'cancelled', 'sandbox_mode'],
                'recent_receipts' => [['invoice_id', 'nfse_number', 'status', 'invoice_url']],
            ]])
            ->assertJsonPath('data.recent_receipts.0.nfse_number', 'SYNTHETIC-DASHBOARD-2026');

        $route = Route::getRoutes()->getByName('api.nfse.dashboard.index');
        self::assertNotNull($route);
        self::assertContains('api', $route->gatherMiddleware());
        self::assertContains('permission:read-sales-invoices', $route->gatherMiddleware());
        self::assertSame(
            Route::getRoutes()->getByName('nfse.dashboard.data')->getActionName(),
            $route->getActionName(),
        );
    }
}

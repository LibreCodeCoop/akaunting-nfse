<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Illuminate\Support\Facades\Route;
use Modules\Nfse\Http\Controllers\FiscalLedgerApiController;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class FiscalLedgerApiTest extends FeatureTestCase
{
    public function testLedgerBrowserShellContainsNoFiscalRowsAndJsonFiltersAreValidated(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create(['company_id' => (int) company_id()]);
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => (int) $invoice->id,
            'nfse_number' => 'SYNTHETIC-LEDGER-2938',
            'status' => 'emitted',
        ]);
        $this->get(route('nfse.ledger.index'))
            ->assertOk()->assertSee('id="nfse-ledger-rows"', false)
            ->assertDontSee('SYNTHETIC-LEDGER-2938');
        $this->get(route('nfse.ledger.data', ['status' => 'emitted']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $receipt->id)
            ->assertJsonPath('data.0.nfse_number', 'SYNTHETIC-LEDGER-2938')
            ->assertJsonPath('data.0.can_download', true)
            ->assertJsonStructure(['data', 'meta' => [
                'current_page', 'last_page', 'per_page', 'total',
            ]]);
        // FeatureTestCase disables exception handling by default. Invalid
        // JSON requests must yield HTTP 422 rather than redirecting to HTML.
        $this->withExceptionHandling();
        foreach ([
            ['status' => 'invalid'], ['limit' => 101], ['from' => '2026-99-01'],
            ['from' => ['2026-01-01']], ['page' => 0],
        ] as $filter) {
            $this->getJson(route('nfse.ledger.data', $filter))->assertUnprocessable();
        }
    }

    public function testNativeLedgerAndBinaryArtifactsRequireSalesInvoiceReadPermission(): void
    {
        foreach (['api.nfse.ledger.index', 'api.nfse.receipts.artifacts.download'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route);
            self::assertContains('permission:read-sales-invoices', $route->gatherMiddleware());
        }
        $this->loginAs();
        $this->get(route('nfse.ledger.data'))->assertOk();
    }

    public function testUnsupportedArtifactKindIsRejectedBeforeAccessingStorage(): void
    {
        $this->loginAs();
        $response = (new FiscalLedgerApiController())->artifact(987654321, 'private-key', new InvoiceController());
        self::assertSame(422, $response->status());
    }
}

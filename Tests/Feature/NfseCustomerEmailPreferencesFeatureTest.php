<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document as Invoice;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

final class NfseCustomerEmailPreferencesFeatureTest extends FeatureTestCase
{
    public function testCustomerEmailSaveIsIndependentOfFiscalIssuance(): void
    {
        $this->loginAs();
        $invoice = Invoice::factory()->invoice()->draft()->create(['company_id' => (int) company_id()]);
        $other = Invoice::factory()->invoice()->draft()->create(['company_id' => (int) company_id()]);

        $route = Route::getRoutes()->getByName('nfse.invoices.email-preferences.store');
        self::assertNotNull($route);
        self::assertContains('permission:update-sales-invoices', $route->gatherMiddleware());

        $this->postJson(route('nfse.invoices.email-preferences.store', $invoice), [
            'subject' => 'Nota {nfse_number} para o cliente',
            'body' => '<p>Valor {invoice_amount_due} e vencimento {invoice_due_date}</p>',
        ])->assertOk()->assertJsonPath('scope', 'customer');

        $this->getJson(route('nfse.invoices.service-preview', $invoice))
            ->assertOk()
            ->assertJsonPath('email_defaults.subject', 'Nota {nfse_number} para o cliente')
            ->assertJsonPath('email_defaults.template_scope', 'customer');

        if ((int) $other->contact_id !== (int) $invoice->contact_id) {
            $this->getJson(route('nfse.invoices.service-preview', $other))
                ->assertOk()
                ->assertJsonPath('email_defaults.template_scope', 'general');
        }

        $this->assertDatabaseCount('nfse_receipts', 0);
        $this->assertDatabaseCount('nfse_dps_reservations', 0);
        $this->assertDatabaseCount('nfse_emission_attempts', 0);
    }

    public function testCrossCompanyOrFinancialBillCannotSaveCustomerPreference(): void
    {
        $this->loginAs();
        $companyId = (int) company_id();
        $invoice = Invoice::factory()->invoice()->draft()->create(['company_id' => $companyId]);
        $bill = Invoice::factory()->bill()->create(['company_id' => $companyId]);

        $invoice->forceFill(['company_id' => $companyId + 1000])->saveQuietly();

        $payload = ['subject' => 'Custom', 'body' => '<p>Value</p>'];
        $this->withExceptionHandling()
            ->postJson(route('nfse.invoices.email-preferences.store', $invoice), $payload)
            ->assertNotFound();
        $this->postJson(route('nfse.invoices.email-preferences.store', $bill), $payload)
            ->assertNotFound();
    }

    public function testInvalidEmailContentDoesNotCreatePreference(): void
    {
        $this->loginAs();
        $invoice = Invoice::factory()->invoice()->draft()->create(['company_id' => (int) company_id()]);

        $this->postJson(route('nfse.invoices.email-preferences.store', $invoice), [
            'subject' => '',
            'body' => '',
        ])->assertUnprocessable();

        $this->getJson(route('nfse.invoices.service-preview', $invoice))
            ->assertOk()
            ->assertJsonPath('email_defaults.template_scope', 'general');
    }
}

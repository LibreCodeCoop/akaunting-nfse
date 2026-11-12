<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document as Invoice;
use Tests\Feature\FeatureTestCase;

final class NfseEmailDeliveryApiTest extends FeatureTestCase
{
    public function testEmailSendRejectsMismatchedInvoiceBeforeSending(): void
    {
        $this->withExceptionHandling()->loginAs();
        $invoice = Invoice::factory()->invoice()->draft()->create(['company_id' => (int) company_id()]);

        $this->postJson(route('nfse.modals.invoices.emails.store', $invoice), [
            'document_id' => $invoice->id + 12345,
            'to' => ['synthetic@example.test'],
            'subject' => 'Synthetic mail',
            'body' => '<p>Synthetic body</p>',
        ])->assertNotFound();

        $this->assertDatabaseCount('nfse_receipts', 0);
        $this->assertDatabaseCount('nfse_emission_attempts', 0);
    }

    public function testEmailSendRejectsForeignInvoicesAndBills(): void
    {
        $this->withExceptionHandling()->loginAs();
        $companyId = (int) company_id();
        $foreign = Invoice::factory()->invoice()->draft()->create(['company_id' => $companyId]);
        $foreign->forceFill(['company_id' => $companyId + 1000])->saveQuietly();
        $bill = Invoice::factory()->bill()->create(['company_id' => $companyId]);

        foreach ([$foreign, $bill] as $document) {
            $this->postJson(route('nfse.modals.invoices.emails.store', $document), [
                'to' => ['synthetic@example.test'],
                'subject' => 'Synthetic mail',
                'body' => '<p>Synthetic body</p>',
            ])->assertNotFound();
        }

        $this->assertDatabaseCount('nfse_receipts', 0);
    }

    public function testEmailSendWithoutDocumentIdStillRejectsEmptyRecipientsBeforeAnyEvent(): void
    {
        $this->withExceptionHandling()->loginAs();
        $invoice = Invoice::factory()->invoice()->draft()->create(['company_id' => (int) company_id()]);

        $this->postJson(route('nfse.modals.invoices.emails.store', $invoice), [
            'to' => [],
            'subject' => 'Synthetic mail',
            'body' => '<p>Synthetic body</p>',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'nfse_email_invalid');

        $this->assertDatabaseCount('nfse_receipts', 0);
        $this->assertDatabaseCount('nfse_emission_attempts', 0);
    }
}

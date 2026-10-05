<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class InvoiceSubstitutionFlowTest extends FeatureTestCase
{
    public function testSubstitutionRouteRejectsReceiptFromAnotherInvoice(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $otherInvoice = Document::factory()->invoice()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $otherInvoice->id,
            'nfse_number' => '100',
            'chave_acesso' => str_repeat('1', 50),
            'status' => 'emitted',
        ]);

        $this->loginAs()
            ->post(route('nfse.invoices.substitute', $invoice), [
                'nfse_substitution_receipt_id' => $receipt->id,
                'nfse_substitution_reason' => '01',
                'nfse_substitution_description' => 'Correcao de dados fiscais da nota original.',
            ])
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('error');
    }

    public function testSubstitutionRouteRejectsCancelledReceipt(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '101',
            'chave_acesso' => str_repeat('2', 50),
            'status' => 'cancelled',
        ]);

        $this->loginAs()
            ->post(route('nfse.invoices.substitute', $invoice), [
                'nfse_substitution_receipt_id' => $receipt->id,
                'nfse_substitution_reason' => '01',
                'nfse_substitution_description' => 'Correcao de dados fiscais da nota original.',
            ])
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('error');
    }

    public function testNativeInvoiceShowsSubstitutionActionOnlyForEmittedReceipt(): void
    {
        $invoice = Document::factory()->invoice()->create();
        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '102',
            'chave_acesso' => str_repeat('3', 50),
            'status' => 'emitted',
        ]);

        $this->loginAs()
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee(trans('nfse::general.invoices.substitute'))
            ->assertSee(route('nfse.invoices.substitute', $invoice));
    }
}

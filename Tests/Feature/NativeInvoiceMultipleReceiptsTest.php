<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class NativeInvoiceMultipleReceiptsTest extends FeatureTestCase
{
    public function testNativeInvoiceShowsAllLinkedFiscalReceipts(): void
    {
        $invoice = Document::factory()->invoice()->create();

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '5101',
            'chave_acesso' => str_repeat('5', 50),
            'status' => 'emitted',
            'emission_group_key' => 'service:0107|tax:010701|rate:2.00',
        ]);

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '5102',
            'chave_acesso' => str_repeat('6', 50),
            'status' => 'emitted',
            'emission_group_key' => 'service:0101|tax:010101|rate:3.00',
        ]);

        $this->loginAs()
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('5101')
            ->assertSee('5102')
            ->assertSee(str_repeat('5', 50))
            ->assertSee(str_repeat('6', 50))
            ->assertSee('service:0107|tax:010701|rate:2.00')
            ->assertSee('service:0101|tax:010101|rate:3.00');
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class NativeInvoiceFiscalListStatusTest extends FeatureTestCase
{
    public function testNativeInvoiceListReceivesFiscalStatusMapForVisibleInvoices(): void
    {
        $invoice = Document::factory()->invoice()->create();

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '701',
            'chave_acesso' => str_repeat('7', 50),
            'status' => 'emitted',
        ]);

        $response = $this->loginAs()
            ->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('data-nfse-native-list-map="true"', false);

        $html = $response->getContent();

        self::assertIsString($html);
        self::assertStringContainsString('"status":"emitted"', $html);
        self::assertStringContainsString(
            route('invoices.show', $invoice->id),
            $html,
        );
    }

    public function testNativeInvoiceListUsesPendingFiscalStatusWithoutReceipt(): void
    {
        $invoice = Document::factory()->invoice()->create();

        $response = $this->loginAs()
            ->get(route('invoices.index'))
            ->assertOk();

        $html = $response->getContent();

        self::assertIsString($html);
        self::assertStringContainsString('"status":"pending"', $html);
        self::assertStringContainsString(
            route('invoices.show', $invoice->id),
            $html,
        );
    }
}

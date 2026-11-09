<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use App\Models\Setting\Category;
use Modules\Nfse\Application\CategoryIssuancePolicy;
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
            ->get(route('invoices.index', ['list_records' => 'all']))
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

    public function testNativeInvoiceListUsesPendingFiscalStatusOnlyForAuthorizedCategory(): void
    {
        $this->loginAs();
        $category = Category::factory()->create(['company_id' => company_id(), 'type' => 'income', 'enabled' => true]);
        (new CategoryIssuancePolicy())->record((int) company_id(), (int) $category->id, (int) auth()->id(),
            'issue', 'Synthetic invoice category authorized for the fiscal list.');
        $invoice = Document::factory()->invoice()->create(['company_id' => company_id(), 'category_id' => $category->id]);

        $response = $this->loginAs()
            ->get(route('invoices.index', ['list_records' => 'all']))
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

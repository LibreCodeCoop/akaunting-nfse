<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class MultipleReceiptPersistenceTest extends FeatureTestCase
{
    public function testInvoiceCanPersistMultipleFiscalReceipts(): void
    {
        $invoice = Document::factory()->invoice()->create();

        $first = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '1001',
            'chave_acesso' => str_repeat('1', 50),
            'status' => 'emitted',
            'emission_group_key' => 'service:0107|tax:010701|rate:2.00',
        ]);

        $second = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '1002',
            'chave_acesso' => str_repeat('2', 50),
            'status' => 'emitted',
            'emission_group_key' => 'service:0101|tax:010101|rate:5.00',
        ]);

        self::assertNotSame($first->id, $second->id);
        self::assertSame(
            2,
            NfseReceipt::query()->where('invoice_id', $invoice->id)->count(),
        );
    }

    public function testReplacementRelationshipPreservesOriginalReceipt(): void
    {
        $invoice = Document::factory()->invoice()->create();

        $original = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '2001',
            'chave_acesso' => str_repeat('3', 50),
            'status' => 'substituted',
        ]);

        $replacement = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '2002',
            'chave_acesso' => str_repeat('4', 50),
            'status' => 'emitted',
            'replaces_receipt_id' => $original->id,
        ]);

        self::assertSame($original->id, $replacement->replaces?->id);
        self::assertSame($replacement->id, $original->replacement?->id);
        self::assertDatabaseHas('nfse_receipts', [
            'id' => $original->id,
            'status' => 'substituted',
            'chave_acesso' => str_repeat('3', 50),
        ]);
    }

    public function testLatestReceiptRepresentsCurrentSingleDocumentState(): void
    {
        $invoice = Document::factory()->invoice()->create();

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '3001',
            'chave_acesso' => str_repeat('5', 50),
            'status' => 'cancelled',
        ]);

        $latest = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '3002',
            'chave_acesso' => str_repeat('6', 50),
            'status' => 'emitted',
        ]);

        self::assertSame(
            $latest->id,
            NfseReceipt::query()
                ->where('invoice_id', $invoice->id)
                ->latest('id')
                ->firstOrFail()
                ->id,
        );
    }
}

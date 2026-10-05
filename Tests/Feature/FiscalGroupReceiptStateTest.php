<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Application\FiscalGroupReceiptState;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class FiscalGroupReceiptStateTest extends FeatureTestCase
{
    public function testAnnotatesIssuedAndPendingGroupsFromPersistedReceipts(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $issuedKey = 'service:0107|tax:010701|rate:2.00';

        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '9001',
            'chave_acesso' => str_repeat('9', 50),
            'status' => 'emitted',
            'emission_group_key' => $issuedKey,
        ]);

        $groups = [
            ['key' => $issuedKey],
            ['key' => 'service:0101|tax:010101|rate:3.00'],
        ];

        $annotated = (new FiscalGroupReceiptState())->annotate((int) $invoice->id, $groups);

        self::assertTrue($annotated[0]['issued']);
        self::assertSame($receipt->id, $annotated[0]['receipt_id']);
        self::assertSame('9001', $annotated[0]['nfse_number']);
        self::assertFalse($annotated[1]['issued']);
        self::assertNull($annotated[1]['receipt_id']);
    }

    public function testRemainingReturnsOnlyUnitsWithoutReceipt(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $issuedKey = 'service:0107|tax:010701|rate:2.00';

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '9002',
            'chave_acesso' => str_repeat('8', 50),
            'status' => 'emitted',
            'emission_group_key' => $issuedKey,
        ]);

        $remaining = (new FiscalGroupReceiptState())->remaining((int) $invoice->id, [
            ['key' => $issuedKey],
            ['key' => 'service:0101|tax:010101|rate:3.00'],
        ]);

        self::assertCount(1, $remaining);
        self::assertSame('service:0101|tax:010101|rate:3.00', $remaining[0]['key']);
        self::assertFalse($remaining[0]['issued']);
    }
}

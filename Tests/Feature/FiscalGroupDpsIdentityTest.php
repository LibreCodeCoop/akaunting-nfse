<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Application\FiscalGroupDpsIdentity;
use Modules\Nfse\Models\FiscalGroupIdentity;
use Tests\Feature\FeatureTestCase;

final class FiscalGroupDpsIdentityTest extends FeatureTestCase
{
    public function testSameInvoiceAndGroupAlwaysReuseTheSameDpsIdentity(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $allocator = new FiscalGroupDpsIdentity();
        $groupKey = 'service:0107|tax:010701|rate:2.00';

        $first = $allocator->forGroup($invoice->id, $groupKey);
        $retry = $allocator->forGroup($invoice->id, $groupKey);

        self::assertSame('00002', $first['series']);
        self::assertSame($first, $retry);
        self::assertSame(
            1,
            FiscalGroupIdentity::query()
                ->where('invoice_id', $invoice->id)
                ->where('group_key', $groupKey)
                ->count(),
        );
    }

    public function testDifferentGroupsReceiveDifferentNumbersInTheSameDedicatedSeries(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $allocator = new FiscalGroupDpsIdentity();

        $first = $allocator->forGroup(
            $invoice->id,
            'service:0107|tax:010701|rate:2.00',
        );
        $second = $allocator->forGroup(
            $invoice->id,
            'service:0101|tax:010101|rate:5.00',
        );

        self::assertSame('00002', $first['series']);
        self::assertSame('00002', $second['series']);
        self::assertNotSame($first['number'], $second['number']);
    }

    public function testSameSignatureOnDifferentInvoicesDoesNotShareDpsIdentity(): void
    {
        $firstInvoice = Document::factory()->invoice()->create();
        $secondInvoice = Document::factory()->invoice()->create();
        $allocator = new FiscalGroupDpsIdentity();
        $groupKey = 'service:0107|tax:010701|rate:2.00';

        $first = $allocator->forGroup($firstInvoice->id, $groupKey);
        $second = $allocator->forGroup($secondInvoice->id, $groupKey);

        self::assertNotSame($first['number'], $second['number']);
    }
}

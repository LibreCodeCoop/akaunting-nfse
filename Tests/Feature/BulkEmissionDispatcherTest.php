<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Application\BulkEmissionDispatcher;
use Tests\Feature\FeatureTestCase;

final class BulkEmissionDispatcherTest extends FeatureTestCase
{
    public function testDispatchesEachNewPersistedUnitExactlyOnce(): void
    {
        $first = Document::factory()->invoice()->create();
        $second = Document::factory()->invoice()->create();
        $dispatched = [];

        $dispatcher = new BulkEmissionDispatcher(
            dispatchUnit: static function (int $unitId) use (&$dispatched): void {
                $dispatched[] = $unitId;
            },
        );

        $result = $dispatcher->dispatch(
            companyId: (int) $first->company_id,
            requestedBy: null,
            units: [
                [
                    'invoice_id' => (int) $first->id,
                    'emission_group_key' => 'service:0107|tax:010701|rate:2.00',
                ],
                [
                    'invoice_id' => (int) $second->id,
                    'emission_group_key' => 'service:0101|tax:010101|rate:3.00',
                ],
            ],
        );

        self::assertFalse($result['reused']);
        self::assertSame(2, $result['dispatched']);
        self::assertCount(2, $dispatched);
        self::assertSame(
            array_map(static fn ($unit): int => (int) $unit->id, $result['units']),
            $dispatched,
        );
    }

    public function testRepeatedActiveSelectionDoesNotRedispatchJobs(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $dispatched = [];

        $dispatcher = new BulkEmissionDispatcher(
            dispatchUnit: static function (int $unitId) use (&$dispatched): void {
                $dispatched[] = $unitId;
            },
        );
        $units = [[
            'invoice_id' => (int) $invoice->id,
            'emission_group_key' => 'service:0107|tax:010701|rate:2.00',
        ]];

        $first = $dispatcher->dispatch(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: $units,
        );
        $second = $dispatcher->dispatch(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: $units,
        );

        self::assertFalse($first['reused']);
        self::assertSame(1, $first['dispatched']);
        self::assertTrue($second['reused']);
        self::assertSame(0, $second['dispatched']);
        self::assertCount(1, $dispatched);
        self::assertSame($first['run']->id, $second['run']->id);
    }
}

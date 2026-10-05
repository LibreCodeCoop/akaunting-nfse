<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Application\BulkEmissionRunRecorder;
use Modules\Nfse\Application\BulkEmissionStatusPolicy;
use Tests\Feature\FeatureTestCase;

final class BulkEmissionRunRecorderTest extends FeatureTestCase
{
    public function testCreatesAuditableRunWithDeduplicatedFiscalUnits(): void
    {
        $first = Document::factory()->invoice()->create();
        $second = Document::factory()->invoice()->create();

        $result = (new BulkEmissionRunRecorder())->create(
            companyId: (int) $first->company_id,
            requestedBy: null,
            units: [
                [
                    'invoice_id' => (int) $first->id,
                    'emission_group_key' => 'service:0107|tax:010701|rate:2.00',
                ],
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

        self::assertSame('queued', $result['run']->status);
        self::assertCount(2, $result['units']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['run']->selection_hash);

        $this->assertDatabaseCount('nfse_bulk_emission_runs', 1);
        $this->assertDatabaseCount('nfse_bulk_emission_units', 2);
        $this->assertDatabaseHas('nfse_bulk_emission_units', [
            'run_id' => $result['run']->id,
            'invoice_id' => $first->id,
            'status' => 'queued',
        ]);
    }

    public function testRepeatedActiveSelectionReusesSameRunAndUnits(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $units = [[
            'invoice_id' => (int) $invoice->id,
            'emission_group_key' => 'service:0107|tax:010701|rate:2.00',
        ]];

        $recorder = new BulkEmissionRunRecorder();
        $first = $recorder->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: $units,
        );
        $second = $recorder->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: $units,
        );

        self::assertSame($first['run']->id, $second['run']->id);
        self::assertCount(1, $second['units']);
        self::assertSame($first['units'][0]->id, $second['units'][0]->id);
        $this->assertDatabaseCount('nfse_bulk_emission_runs', 1);
        $this->assertDatabaseCount('nfse_bulk_emission_units', 1);
    }

    public function testTerminalRunAllowsNewAttemptForSameSelection(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $units = [[
            'invoice_id' => (int) $invoice->id,
            'emission_group_key' => 'service:0107|tax:010701|rate:2.00',
        ]];

        $recorder = new BulkEmissionRunRecorder();
        $first = $recorder->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: $units,
        );

        $first['run']->update(['status' => BulkEmissionStatusPolicy::ISSUED]);

        $second = $recorder->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: $units,
        );

        self::assertNotSame($first['run']->id, $second['run']->id);
        $this->assertDatabaseCount('nfse_bulk_emission_runs', 2);
    }

    public function testRejectsEmptyOrInvalidSelection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new BulkEmissionRunRecorder())->create(
            companyId: 1,
            requestedBy: null,
            units: [
                ['invoice_id' => 0, 'emission_group_key' => ''],
            ],
        );
    }
}

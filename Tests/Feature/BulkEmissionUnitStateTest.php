<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Application\BulkEmissionRunRecorder;
use Modules\Nfse\Application\BulkEmissionStatusPolicy;
use Modules\Nfse\Application\BulkEmissionUnitState;
use Modules\Nfse\Models\BulkEmissionRun;
use Modules\Nfse\Models\BulkEmissionUnit;
use Tests\Feature\FeatureTestCase;

final class BulkEmissionUnitStateTest extends FeatureTestCase
{
    public function testTransitionsUnitAndRefreshesAggregateRunStatus(): void
    {
        $first = Document::factory()->invoice()->create();
        $second = Document::factory()->invoice()->create();

        $recorded = (new BulkEmissionRunRecorder())->create(
            companyId: (int) $first->company_id,
            requestedBy: null,
            units: [
                [
                    'invoice_id' => (int) $first->id,
                    'emission_group_key' => 'group-a',
                ],
                [
                    'invoice_id' => (int) $second->id,
                    'emission_group_key' => 'group-b',
                ],
            ],
        );

        $state = new BulkEmissionUnitState();
        $firstUnit = $recorded['units'][0];
        $secondUnit = $recorded['units'][1];

        $state->transition((int) $firstUnit->id, BulkEmissionStatusPolicy::PROCESSING);

        self::assertSame(
            BulkEmissionStatusPolicy::PROCESSING,
            BulkEmissionRun::query()->whereKey($recorded['run']->id)->first()?->status,
        );

        $state->transition(
            (int) $firstUnit->id,
            BulkEmissionStatusPolicy::ISSUED,
            receiptId: null,
        );

        self::assertSame(
            BulkEmissionStatusPolicy::QUEUED,
            BulkEmissionRun::query()->whereKey($recorded['run']->id)->first()?->status,
        );

        $state->transition(
            (int) $secondUnit->id,
            BulkEmissionStatusPolicy::BLOCKED,
            errorType: 'readiness',
            errorMessage: 'Certificate missing',
        );

        self::assertSame(
            'partial_blocked',
            BulkEmissionRun::query()->whereKey($recorded['run']->id)->first()?->status,
        );

        $blocked = BulkEmissionUnit::query()->whereKey($secondUnit->id)->first();

        self::assertSame('readiness', $blocked?->error_type);
        self::assertSame('Certificate missing', $blocked?->error_message);
    }

    public function testRejectsIllegalTerminalTransition(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $recorded = (new BulkEmissionRunRecorder())->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: [[
                'invoice_id' => (int) $invoice->id,
                'emission_group_key' => 'group-a',
            ]],
        );

        $state = new BulkEmissionUnitState();
        $unit = $recorded['units'][0];

        $state->transition((int) $unit->id, BulkEmissionStatusPolicy::BLOCKED);

        $this->expectException(\LogicException::class);
        $state->transition((int) $unit->id, BulkEmissionStatusPolicy::PROCESSING);
    }

    public function testRetryableReadErrorCanResume(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $recorded = (new BulkEmissionRunRecorder())->create(
            companyId: (int) $invoice->company_id,
            requestedBy: null,
            units: [[
                'invoice_id' => (int) $invoice->id,
                'emission_group_key' => 'group-a',
            ]],
        );

        $state = new BulkEmissionUnitState();
        $unit = $recorded['units'][0];

        $state->transition((int) $unit->id, BulkEmissionStatusPolicy::PROCESSING);
        $state->transition(
            (int) $unit->id,
            BulkEmissionStatusPolicy::RETRYABLE_READ_ERROR,
            errorType: 'network',
            errorMessage: 'Ambiguous response',
        );

        $resumed = $state->transition(
            (int) $unit->id,
            BulkEmissionStatusPolicy::PROCESSING,
        );

        self::assertSame(BulkEmissionStatusPolicy::PROCESSING, $resumed->status);
        self::assertNull($resumed->error_type);
        self::assertNull($resumed->error_message);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Models\BulkEmissionRun;
use Modules\Nfse\Models\BulkEmissionUnit;
use Tests\Feature\FeatureTestCase;

final class BulkEmissionProgressTest extends FeatureTestCase
{
    public function testProgressPageShowsPersistedUnitStatesAndErrors(): void
    {
        $invoice = Document::factory()->invoice()->create();
        $run = BulkEmissionRun::query()->create([
            'company_id' => $invoice->company_id,
            'requested_by' => null,
            'status' => 'partial',
            'selection_hash' => str_repeat('a', 64),
        ]);

        BulkEmissionUnit::query()->create([
            'run_id' => $run->id,
            'invoice_id' => $invoice->id,
            'emission_group_key' => 'service:0107|tax:010701|rate:2.00',
            'status' => 'rejected',
            'error_type' => 'gateway',
            'error_message' => 'Business rejection from SEFIN',
        ]);

        $this->loginAs()->get(route('nfse.bulk.index'))
            ->assertOk()
            ->assertSee('id="nfse-bulk-runs"', false)
            ->assertSee('data-nfse-bulk-progress-browser', false)
            ->assertDontSee('Business rejection from SEFIN');

        $this->getJson(route('nfse.bulk.runs'))
            ->assertOk()
            ->assertJsonPath('data.0.id', (int) $run->id)
            ->assertJsonPath('data.0.status', 'partial')
            ->assertJsonPath('data.0.units.0.emission_group_key', 'service:0107|tax:010701|rate:2.00')
            ->assertJsonPath('data.0.units.0.status', 'rejected')
            ->assertJsonPath('data.0.units.0.error_type', 'gateway')
            ->assertJsonPath('data.0.units.0.error_message', 'Business rejection from SEFIN');

        $this->getJson(route('nfse.bulk.runs.show', $run->id))
            ->assertOk()->assertJsonPath('data.units.0.invoice_id', (int) $invoice->id);
    }

    public function testProgressPageIsCompanyIsolated(): void
    {
        $visibleInvoice = Document::factory()->invoice()->create();
        $otherInvoice = Document::factory()->invoice()->create([
            'company_id' => ((int) $visibleInvoice->company_id) + 999,
        ]);

        $visibleRun = BulkEmissionRun::query()->create([
            'company_id' => $visibleInvoice->company_id,
            'status' => 'queued',
            'selection_hash' => str_repeat('b', 64),
        ]);
        $hiddenRun = BulkEmissionRun::query()->create([
            'company_id' => $otherInvoice->company_id,
            'status' => 'queued',
            'selection_hash' => str_repeat('c', 64),
        ]);

        BulkEmissionUnit::query()->create([
            'run_id' => $visibleRun->id,
            'invoice_id' => $visibleInvoice->id,
            'emission_group_key' => 'visible-group',
            'status' => 'queued',
        ]);
        BulkEmissionUnit::query()->create([
            'run_id' => $hiddenRun->id,
            'invoice_id' => $otherInvoice->id,
            'emission_group_key' => 'hidden-group',
            'status' => 'queued',
        ]);

        $this->loginAs()->get(route('nfse.bulk.index'))
            ->assertOk()->assertDontSee('visible-group')->assertDontSee('hidden-group');
        $this->getJson(route('nfse.bulk.runs'))
            ->assertOk()
            ->assertJsonPath('data.0.id', (int) $visibleRun->id)
            ->assertJsonPath('data.0.units.0.emission_group_key', 'visible-group')
            ->assertJsonMissing(['emission_group_key' => 'hidden-group']);
        $this->withExceptionHandling()->getJson(route('nfse.bulk.runs.show', $hiddenRun->id))
            ->assertNotFound();
    }
}

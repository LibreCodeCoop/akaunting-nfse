<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Jobs\Auth\CreateUser;
use App\Models\Document\Document;
use App\Traits\Permissions;
use Illuminate\Support\Facades\Route;
use Modules\Nfse\Models\BulkEmissionRun;
use Modules\Nfse\Models\BulkEmissionUnit;
use Tests\Feature\FeatureTestCase;

final class BulkEmissionApiTest extends FeatureTestCase
{
    use Permissions;

    public function testNativeAndBrowserRoutesKeepDistinctReadAndWritePermissions(): void
    {
        foreach ([
            'nfse.bulk.runs', 'nfse.bulk.runs.show', 'nfse.bulk.plan',
            'api.nfse.bulk.runs', 'api.nfse.bulk.runs.show', 'api.nfse.bulk.plan',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertContains('permission:read-sales-invoices', $route->gatherMiddleware(), $name);
        }
        foreach (['nfse.bulk.dispatch', 'nfse.bulk.dispatch-json', 'api.nfse.bulk.dispatch'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertContains('permission:update-sales-invoices', $route->gatherMiddleware(), $name);
        }
    }

    public function testPlanIsReadOnlyAndRejectsMalformedSelections(): void
    {
        $this->withExceptionHandling()->loginAs();
        $own = Document::factory()->invoice()->create(['company_id' => $this->company->id]);
        $foreign = Document::factory()->invoice()->create([
            'company_id' => ((int) $own->company_id) + 999,
        ]);

        $this->postJson(route('nfse.bulk.plan'), [
            'invoice_ids' => [$own->id, $foreign->id],
        ])->assertOk()
            ->assertJsonPath('data.not_found_or_forbidden.0', (int) $foreign->id)
            ->assertJsonStructure(['data' => [
                'queued', 'blocked', 'already_issued', 'not_found_or_forbidden',
            ]]);

        foreach ([
            ['invoice_ids' => []],
            ['invoice_ids' => [0]],
            ['invoice_ids' => ['not-a-number']],
            ['invoice_ids' => [1, 1]],
            ['invoice_ids' => range(1, 101)],
        ] as $invalid) {
            $this->postJson(route('nfse.bulk.plan'), $invalid)->assertUnprocessable();
        }
        $this->assertDatabaseCount('nfse_bulk_emission_runs', 0);
        $this->assertDatabaseCount('nfse_bulk_emission_units', 0);
    }

    public function testReadOnlyOperatorMayInspectButNeverDispatch(): void
    {
        $this->loginAs();
        $role = $this->createRole('nfse-bulk-readonly');
        $this->attachPermission($role, 'read-admin-panel');
        $this->attachPermission($role, 'read-sales-invoices');
        $reader = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$this->company->id],
            'roles' => [$role->id],
        ])));

        $this->withExceptionHandling()->loginAs($reader);
        $this->getJson(route('nfse.bulk.runs'))->assertOk();
        $this->postJson(route('nfse.bulk.dispatch-json'), ['invoice_ids' => [1]])->assertForbidden();
        $this->postJson(route('nfse.bulk.dispatch'), ['invoice_ids' => [1]])->assertForbidden();
        $this->assertDatabaseCount('nfse_bulk_emission_runs', 0);
    }

    public function testRunDetailNeverExposesAnotherCompanyOrItsUnit(): void
    {
        $this->withExceptionHandling()->loginAs();
        $ownInvoice = Document::factory()->invoice()->create(['company_id' => $this->company->id]);
        $foreignInvoice = Document::factory()->invoice()->create([
            'company_id' => ((int) $ownInvoice->company_id) + 999,
        ]);
        $ownRun = BulkEmissionRun::query()->create([
            'company_id' => $ownInvoice->company_id, 'status' => 'queued',
            'selection_hash' => str_repeat('d', 64),
        ]);
        $foreignRun = BulkEmissionRun::query()->create([
            'company_id' => $foreignInvoice->company_id, 'status' => 'rejected',
            'selection_hash' => str_repeat('e', 64),
        ]);
        foreach ([[$ownRun, $ownInvoice], [$foreignRun, $foreignInvoice]] as [$run, $invoice]) {
            BulkEmissionUnit::query()->create([
                'run_id' => (int) $run->id,
                'invoice_id' => (int) $invoice->id,
                'emission_group_key' => 'synthetic-company-' . $run->company_id,
                'status' => 'queued',
            ]);
        }

        $this->getJson(route('nfse.bulk.runs'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (int) $ownRun->id)
            ->assertJsonMissing(['id' => (int) $foreignRun->id]);
        $this->getJson(route('nfse.bulk.runs.show', $ownRun->id))
            ->assertOk()->assertJsonPath('data.units.0.invoice_id', (int) $ownInvoice->id);
        $this->getJson(route('nfse.bulk.runs.show', $foreignRun->id))
            ->assertNotFound();
    }
}

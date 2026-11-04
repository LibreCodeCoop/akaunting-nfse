<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Jobs\Auth\CreateUser;
use App\Traits\Permissions;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

final class FiscalClosingApiTest extends FeatureTestCase
{
    use Permissions;

    public function testClosingJsonHasStableFiscalAndCommercialPaginationContract(): void
    {
        $this->loginAs();
        $this->get(route('nfse.closing.data', [
            'competence' => '2026-10', 'report_page' => 1,
        ]))->assertOk()
            ->assertJsonPath('data.competence', '2026-10')
            ->assertJsonStructure([
                'data' => [
                    'summary', 'reconciliation', 'rows',
                    'ibs_cbs_evidence' => ['summary' => [
                        'active_documents', 'excluded_documents', 'missing_snapshots',
                        'duplicate_documents',
                        'ibs' => ['reported_total', 'reported_count', 'not_reported_count', 'invalid_count'],
                        'cbs' => ['reported_total', 'reported_count', 'not_reported_count', 'invalid_count'],
                    ]],
                    'commercial_review' => ['rows', 'meta' => [
                        'current_page', 'last_page', 'per_page', 'total',
                    ]],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        // FeatureTestCase disables exception handling by default. Negative
        // HTTP contract checks must assert the actual 422 transport response.
        $this->withExceptionHandling();
        $this->get(route('nfse.closing.data', ['competence' => '2026-13']))
            ->assertStatus(422);
        $this->get(route('nfse.closing.data', [
            'competence' => '2026-10', 'report_page' => ['1'],
        ]))->assertUnprocessable();
        $this->get(route('nfse.closing.data', [
            'competence' => '2026-10', 'report_page' => '100001',
        ]))->assertUnprocessable();
    }

    public function testClosingEvidenceRequiresSalesReadPermissionInBrowserSession(): void
    {
        $this->loginAs();
        $role = $this->createRole('nfse-ibs-cbs-read-denied');
        $this->attachPermission($role, 'read-admin-panel');

        $operator = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$this->company->id],
            'roles' => [$role->id],
        ])));

        $this->withExceptionHandling()->loginAs($operator);
        foreach (['nfse.closing.data', 'nfse.closing.ibs-cbs-evidence'] as $name) {
            $this->get(route($name, ['competence' => '2026-10']))->assertForbidden();
        }
    }

    public function testNativeClosingRouteRequiresSalesInvoiceReadPermission(): void
    {
        foreach (['api.nfse.closing.index', 'api.nfse.closing.export',
            'api.nfse.closing.ibs-cbs-evidence'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route);
            self::assertContains('permission:read-sales-invoices', $route->gatherMiddleware());
            self::assertContains('GET', $route->methods());
        }
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use App\Models\Setting\Category;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Nfse\Application\AutomaticInvoiceEmissionPreflight;
use Modules\Nfse\Application\CategoryIssuancePolicy;
use Modules\Nfse\Application\InvoiceFiscalDecision;
use Tests\Feature\FeatureTestCase;

final class CategoryIssuancePolicyTest extends FeatureTestCase
{
    public function testCategoryDefaultIsCompanyScopedAndAppliesOnlyToNewCommercialInvoices(): void
    {
        $this->loginAs();
        $companyId = (int) company_id();
        $category = Category::factory()->create(['company_id' => $companyId, 'type' => 'income', 'enabled' => true]);
        $before = Document::factory()->invoice()->create([
            'company_id' => $companyId, 'category_id' => $category->id,
            'issued_at' => '2026-11-08 09:00:00',
        ]);
        try {
            Carbon::setTestNow('2026-11-09 12:00:00');
            $response = $this->postJson(route('nfse.settings.category-issuance.store'), [
                'category_id' => $category->id,
                'mode' => 'do_not_issue',
                'justification' => 'Synthetic administrative category classification.',
            ]);
            $response->assertCreated()->assertJsonPath('data.mode', 'do_not_issue');
            Carbon::setTestNow('2026-11-10 12:00:00');
            $after = Document::factory()->invoice()->create([
                'company_id' => $companyId, 'category_id' => $category->id,
                'issued_at' => '2026-11-10 12:00:00',
            ]);
        } finally {
            Carbon::setTestNow();
        }

        $policies = new CategoryIssuancePolicy();
        self::assertNull($policies->forInvoice($before));
        self::assertNull((new InvoiceFiscalDecision())->effective($before));
        $effective = (new InvoiceFiscalDecision())->effective($after);
        self::assertSame('do_not_issue', $effective['decision']);
        self::assertSame('category', $effective['source']);
        self::assertSame($category->id, $effective['category_id']);
        self::assertCount(1, $policies->history($companyId, (int) $category->id));
        self::assertSame(0, DB::table('nfse_invoice_decisions')->count());
        $this->getJson(route('nfse.settings.category-issuance.index'))
            ->assertOk()->assertSee($category->name);
        self::assertSame('blocked', (new AutomaticInvoiceEmissionPreflight())->evaluate($after)['status']);
        self::assertSame('category_not_authorized',
            (new AutomaticInvoiceEmissionPreflight())->evaluate($after)['reason']);

        $response = $this->get(route('invoices.index', ['nfse_status' => 'do_not_issue']));
        $response->assertOk();
        $ids = $response->viewData('invoices')->getCollection()->pluck('id')->all();
        self::assertNotContains($after->id, $ids);
        self::assertNotContains($before->id, $ids);
        self::assertFalse($policies->canIssue($before));
        self::assertFalse($policies->canIssue($after));
    }

    public function testAManualIssuanceExceptionOverridesCategoryWithoutChangingFinanceOrPolicy(): void
    {
        $this->loginAs();
        $companyId = (int) company_id();
        $category = Category::factory()->create(['company_id' => $companyId, 'type' => 'income', 'enabled' => true]);

        try {
            Carbon::setTestNow('2026-11-09 08:00:00');
            (new CategoryIssuancePolicy())->record($companyId, (int) $category->id,
                (int) auth()->id(), 'do_not_issue', 'Synthetic non-service category default.');
            Carbon::setTestNow('2026-11-10 08:00:00');
            $invoice = Document::factory()->invoice()->create([
                'company_id' => $companyId, 'category_id' => $category->id,
                'issued_at' => '2026-11-10 08:00:00',
            ]);
        } finally {
            Carbon::setTestNow();
        }
        $original = $invoice->fresh();
        self::assertSame('do_not_issue', (new InvoiceFiscalDecision())->effective($invoice)['decision']);

        $this->withExceptionHandling()->postJson(route('nfse.invoices.fiscal-decision.store', $invoice->id), [
            'decision' => 'issue',
            'reason' => 'operational_review',
            'justification' => 'Synthetic invoice is an exception to the category default.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['decision']);

        self::assertFalse((new CategoryIssuancePolicy())->canIssue($invoice));
        try {
            Carbon::setTestNow('2026-11-11 08:00:00');
            (new CategoryIssuancePolicy())->record($companyId, (int) $category->id,
                (int) auth()->id(), 'issue', 'Synthetic category explicitly authorized after review.');
        } finally {
            Carbon::setTestNow();
        }

        $this->postJson(route('nfse.invoices.fiscal-decision.store', $invoice->id), [
            'decision' => 'issue',
            'reason' => 'operational_review',
            'justification' => 'Synthetic invoice is now within the category allowlist.',
        ])->assertCreated()->assertJsonPath('data.decision', 'issue');

        self::assertTrue((new CategoryIssuancePolicy())->canIssue($invoice));
        self::assertSame('issue', (new InvoiceFiscalDecision())->effective($invoice)['decision']);
        $this->postJson(route('nfse.invoices.fiscal-decision.store', $invoice->id), [
            'decision' => 'reversed',
            'reason' => 'reconsidered',
            'justification' => 'Restore the recorded category default after review.',
        ])->assertCreated();
        self::assertSame('do_not_issue', (new InvoiceFiscalDecision())->effective($invoice)['decision']);
        self::assertSame('category', (new InvoiceFiscalDecision())->effective($invoice)['source']);
        self::assertSame($original->amount, $invoice->fresh()->amount);
        self::assertSame($original->status, $invoice->fresh()->status);
        self::assertDatabaseCount('nfse_receipts', 0);
    }

    public function testChangingPolicyAffectsFutureInvoicesButPreservesOlderSnapshots(): void
    {
        $this->loginAs();
        $companyId = (int) company_id();
        $category = Category::factory()->create(['company_id' => $companyId, 'type' => 'income', 'enabled' => true]);
        $policy = new CategoryIssuancePolicy();

        try {
            Carbon::setTestNow('2026-11-09 08:00:00');
            $policy->record($companyId, (int) $category->id, (int) auth()->id(),
                'do_not_issue', 'Synthetic default for future recovery invoices.');
            Carbon::setTestNow('2026-11-10 08:00:00');
            $earlier = Document::factory()->invoice()->create([
                'company_id' => $companyId, 'category_id' => $category->id,
                'issued_at' => '2026-11-10 08:00:00',
            ]);
            Carbon::setTestNow('2026-11-11 08:00:00');
            $policy->record($companyId, (int) $category->id, (int) auth()->id(),
                'issue', 'Synthetic updated rule for newly created invoices.');
            Carbon::setTestNow('2026-11-12 08:00:00');
            $later = Document::factory()->invoice()->create([
                'company_id' => $companyId, 'category_id' => $category->id,
                'issued_at' => '2026-11-12 08:00:00',
            ]);
        } finally {
            Carbon::setTestNow();
        }

        self::assertSame('do_not_issue', (new InvoiceFiscalDecision())->effective($earlier)['decision']);
        self::assertNull((new InvoiceFiscalDecision())->effective($later));
        self::assertCount(2, $policy->history($companyId, (int) $category->id));
        self::assertSame('issue', $policy->latest($companyId, (int) $category->id)['mode']);
        self::assertSame(0, DB::table('nfse_invoice_decisions')->count());
    }

    public function testCategoryPolicyCannotCrossCompanyAndCannotClassifyExpenses(): void
    {
        $this->withExceptionHandling()->loginAs();
        $companyId = (int) company_id();
        $foreign = Category::factory()->create(['company_id' => $companyId + 1000, 'type' => 'income', 'enabled' => true]);
        $expense = Category::factory()->create(['company_id' => $companyId, 'type' => 'expense']);
        foreach ([$foreign->id, $expense->id] as $categoryId) {
            $this->postJson(route('nfse.settings.category-issuance.store'), [
                'category_id' => $categoryId, 'mode' => 'do_not_issue',
                'justification' => 'Synthetic invalid category policy attempted.',
            ])->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
        }
        self::assertSame(0, DB::table('nfse_category_issuance_policies')->count());
    }

    public function testSettingRoutesRequireNativeReadAndWritePermissions(): void
    {
        foreach ([
            'nfse.settings.category-issuance.index' => 'read-nfse-settings',
            'nfse.settings.category-issuance.store' => 'update-nfse-settings',
            'api.nfse.settings.category-issuance.index' => 'read-nfse-settings',
            'api.nfse.settings.category-issuance.store' => 'update-nfse-settings',
        ] as $routeName => $permission) {
            $route = Route::getRoutes()->getByName($routeName);
            self::assertNotNull($route, $routeName);
            self::assertContains('permission:' . $permission, $route->gatherMiddleware());
            if (str_starts_with($routeName, 'api.')) {
                self::assertContains('api', $route->gatherMiddleware());
            }
        }
    }

    public function testNativeCategoryScreenAndInvoiceExposeFiscalActions(): void
    {
        $this->loginAs();
        $category = Category::factory()->create(['company_id' => company_id(), 'type' => 'income', 'enabled' => true]);
        (new CategoryIssuancePolicy())->record((int) company_id(), (int) $category->id,
            (int) auth()->id(), 'issue', 'Synthetic category authorized for the invoice workflow.');
        $invoice = Document::factory()->invoice()->create([
            'company_id' => company_id(), 'category_id' => $category->id,
        ]);

        $this->get(route('nfse.settings.category-issuance.index'))
            ->assertRedirect(route('categories.index'));
        $this->get(route('categories.edit', $category->id))
            ->assertOk()->assertSee('data-nfse-native-category-policy="true"', false);
        $this->get(route('invoices.show', $invoice->id))
            ->assertOk()->assertSee('data-nfse-exclusive-fiscal-choices="true"', false)
            ->assertSee('data-nfse-choice="issue"', false)
            ->assertSee('data-nfse-choice="do_not_issue"', false);
    }
}

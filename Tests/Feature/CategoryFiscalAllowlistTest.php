<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use App\Models\Setting\Category;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Application\AutomaticInvoiceEmissionPreflight;
use Modules\Nfse\Application\CategoryIssuancePolicy;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class CategoryFiscalAllowlistTest extends FeatureTestCase
{
    public function testAnOldInternalFinancialInvoiceNeverBecomesFiscalPendingWithoutAuthorization(): void
    {
        $this->loginAs();
        $companyId = (int) company_id();
        $category = Category::factory()->create([
            'company_id' => $companyId, 'type' => 'income', 'enabled' => true,
            'name' => 'Synthetic internal bank account earnings',
        ]);
        $invoice = Document::factory()->invoice()->create([
            'company_id' => $companyId, 'category_id' => $category->id,
            'status' => 'paid', 'issued_at' => '2023-12-01 12:00:00',
        ]);
        $original = $invoice->fresh();

        $policy = new CategoryIssuancePolicy();
        self::assertFalse($policy->canIssue($invoice));
        self::assertSame('category_not_authorized',
            (new AutomaticInvoiceEmissionPreflight())->evaluate($invoice)['reason']);

        $this->get(route('invoices.show', $invoice->id))->assertOk()
            ->assertDontSee('data-nfse-native-panel="true"', false)
            ->assertDontSee('data-nfse-native-modal-trigger="true"', false);

        $list = $this->get(route('invoices.index', ['list_records' => 'all']))->assertOk();
        $html = $list->getContent();
        self::assertIsString($html);
        self::assertStringNotContainsString('"' . $invoice->id . '":{"status":"pending"', $html);

        foreach (['absent', 'pending'] as $status) {
            $filtered = $this->get(route('invoices.index', [
                'search' => 'nfse_status:' . $status, 'list_records' => 'all',
            ]))->assertOk();
            self::assertNotContains($invoice->id,
                $filtered->viewData('invoices')->getCollection()->pluck('id')->all());
        }

        self::assertSame($original->amount, $invoice->fresh()->amount);
        self::assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseCount('nfse_receipts', 0);
        $this->assertDatabaseCount('nfse_invoice_decisions', 0);
    }

    public function testHistoricalInvoiceBecomesEligibleOnCurrentCategoryAuthorizationWithoutMutation(): void
    {
        $this->loginAs();
        $companyId = (int) company_id();
        $category = Category::factory()->create([
            'company_id' => $companyId, 'type' => 'income', 'enabled' => true,
        ]);
        $invoice = Document::factory()->invoice()->create([
            'company_id' => $companyId, 'category_id' => $category->id,
            'issued_at' => '2023-12-01 12:00:00',
        ]);
        $original = $invoice->fresh();

        $policy = new CategoryIssuancePolicy();
        self::assertFalse($policy->canIssue($invoice));

        $policy->record($companyId, (int) $category->id, (int) auth()->id(),
            'issue', 'Synthetic service category explicitly allowed.');
        self::assertTrue($policy->canIssue($invoice));
        self::assertCount(1, $policy->history($companyId, (int) $category->id));
        self::assertNotNull(DB::table('documents')->where('id', $invoice->id)->first());
        self::assertSame($original->status, $invoice->fresh()->status);
        self::assertSame($original->amount, $invoice->fresh()->amount);
        $this->assertDatabaseCount('nfse_invoice_decisions', 0);

        $this->get(route('invoices.show', $invoice->id))->assertOk()
            ->assertSee('data-nfse-native-panel="true"', false)
            ->assertSee('data-nfse-native-emit="true"', false);

        $policy->record($companyId, (int) $category->id, (int) auth()->id(),
            'do_not_issue', 'Synthetic category no longer authorizes issuance.');
        self::assertFalse($policy->canIssue($invoice));
        $this->get(route('invoices.show', $invoice->id))->assertOk()
            ->assertDontSee('data-nfse-native-panel="true"', false);
        self::assertSame($original->amount, $invoice->fresh()->amount);
        $this->assertDatabaseCount('nfse_receipts', 0);
    }

    public function testPreviouslyIssuedNfseEvidenceRemainsVisibleAfterCategoryIsDisabled(): void
    {
        $this->loginAs();
        $companyId = (int) company_id();
        $category = Category::factory()->create([
            'company_id' => $companyId, 'type' => 'income', 'enabled' => true,
        ]);
        $invoice = Document::factory()->invoice()->create([
            'company_id' => $companyId, 'category_id' => $category->id,
        ]);
        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => 'SYNTH-HISTORICAL',
            'chave_acesso' => str_repeat('6', 50),
            'status' => 'emitted',
        ]);

        $this->get(route('invoices.show', $invoice->id))->assertOk()
            ->assertSee('data-nfse-native-panel="true"', false)
            ->assertDontSee('data-nfse-native-substitute="true"', false);
        $list = $this->get(route('invoices.index', ['list_records' => 'all']))->assertOk();
        self::assertStringContainsString('"' . $invoice->id . '":{"status":"emitted"', $list->getContent());
        $this->assertDatabaseCount('nfse_receipts', 1);
    }

    public function testCompanyIncomeEnabledAndLatestPolicyAreAllRequired(): void
    {
        $this->withExceptionHandling()->loginAs();
        $companyId = (int) company_id();
        $policy = new CategoryIssuancePolicy();
        $foreign = Category::factory()->create([
            'company_id' => $companyId + 1000, 'type' => 'income', 'enabled' => true,
        ]);
        $expense = Category::factory()->create([
            'company_id' => $companyId, 'type' => 'expense', 'enabled' => true,
        ]);
        $other = Category::factory()->create([
            'company_id' => $companyId, 'type' => 'other', 'enabled' => true,
        ]);
        $disabled = Category::factory()->create([
            'company_id' => $companyId, 'type' => 'income', 'enabled' => false,
        ]);

        foreach ([$foreign, $expense, $other, $disabled] as $category) {
            $this->postJson(route('nfse.settings.category-issuance.store'), [
                'category_id' => $category->id,
                'mode' => 'issue',
                'justification' => 'Synthetic unapproved category authorization.',
            ])->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
        }

        $this->assertDatabaseCount('nfse_category_issuance_policies', 0);
        $invoice = Document::factory()->invoice()->create([
            'company_id' => $companyId, 'category_id' => $disabled->id,
        ]);
        self::assertFalse($policy->canIssue($invoice));
    }
}

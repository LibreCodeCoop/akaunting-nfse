<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use App\Models\Setting\Category;
use Modules\Nfse\Application\CategoryIssuancePolicy;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class NativeInvoiceFiscalFilterTest extends FeatureTestCase
{
    public function testOperationalDecisionFiltersWithoutConfusingReceiptStatus(): void
    {
        $this->loginAs();
        $category = $this->authorizeSyntheticCategory();
        $deferred = Document::factory()->invoice()->create([
            'company_id' => company_id(), 'category_id' => $category->id,
        ]);
        $reversed = Document::factory()->invoice()->create([
            'company_id' => company_id(), 'category_id' => $category->id,
        ]);
        $authorized = Document::factory()->invoice()->create([
            'company_id' => company_id(), 'category_id' => $category->id,
        ]);
        $decision = new \Modules\Nfse\Application\InvoiceFiscalDecision();
        foreach ([$deferred, $reversed] as $invoice) {
            $decision->record($invoice, (int) company_id(), (int) auth()->id(),
                'do_not_issue', 'operational_review', 'Synthetic operational review pending.', null);
        }
        $decision->record($reversed, (int) company_id(), (int) auth()->id(),
            'reversed', 'reconsidered', 'Synthetic operator reversed this decision.', null);
        $this->receipt($authorized, 'emitted');

        $ids = $this->invoiceIdsAcrossPages('do_not_issue');
        self::assertContains($deferred->id, $ids);
        self::assertNotContains($reversed->id, $ids);
        self::assertNotContains($authorized->id, $ids);
    }

    public function testFiscalOnlyFilterDoesNotInheritDefaultUnpaidTab(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create([
            'company_id' => company_id(),
            'status' => 'draft',
        ]);
        $this->receipt($invoice, 'emitted');

        $ids = $this->invoiceIdsAcrossPages('emitted');

        self::assertContains($invoice->id, $ids);
    }

    public function testNativeInvoiceFilterUsesLatestReceipt(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $excluded = Document::factory()->invoice()->create(['company_id' => company_id()]);

        $this->receipt($invoice, 'cancelled');
        $this->receipt($invoice, 'emitted');
        $this->receipt($excluded, 'cancelled');

        $response = $this->get(route('invoices.index', ['nfse_status' => 'emitted']));

        $response->assertOk();
        $response->assertViewHas('invoices', static function ($invoices) use ($invoice, $excluded): bool {
            $ids = $invoices->getCollection()->pluck('id')->all();

            return in_array($invoice->id, $ids, true)
                && !in_array($excluded->id, $ids, true);
        });
    }

    public function testNativeInvoiceFilterForAbsentReceiptDoesNotIncludeIssuedInvoice(): void
    {
        $this->loginAs();

        $category = $this->authorizeSyntheticCategory();
        $withoutReceipt = Document::factory()->invoice()->create([
            'company_id' => company_id(), 'category_id' => $category->id,
        ]);
        $withReceipt = Document::factory()->invoice()->create([
            'company_id' => company_id(), 'category_id' => $category->id,
        ]);
        $this->receipt($withReceipt, 'emitted');

        $ids = $this->invoiceIdsAcrossPages('absent');

        self::assertContains($withoutReceipt->id, $ids);
        self::assertNotContains($withReceipt->id, $ids);
    }

    public function testNativeFiscalFilterRespectsNativePagination(): void
    {
        $this->loginAs();

        $first = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $second = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $excluded = Document::factory()->invoice()->create(['company_id' => company_id()]);

        $this->receipt($first, 'emitted');
        $this->receipt($second, 'emitted');
        $this->receipt($excluded, 'cancelled');

        $ids = $this->invoiceIdsAcrossPages('emitted');

        self::assertContains($first->id, $ids);
        self::assertContains($second->id, $ids);
        self::assertNotContains($excluded->id, $ids);
    }

    public function testNativeUnknownFiscalStatusDoesNotIncludeAbsentReceipts(): void
    {
        $this->loginAs();

        $unknown = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $without = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $known = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $this->receipt($unknown, 'unexpected-provider-state');
        $this->receipt($known, 'emitted');

        $ids = $this->invoiceIdsAcrossPages('unknown');

        self::assertContains($unknown->id, $ids);
        self::assertNotContains($without->id, $ids);
        self::assertNotContains($known->id, $ids);
    }


    public function testNativeInvoiceFiscalFilterCombinesCommercialStatusSearchSortingAndPaginationWithoutDuplicates(): void
    {
        $this->loginAs();

        $expected = [];

        for ($i = 1; $i <= 27; ++$i) {
            $invoice = Document::factory()->invoice()->create([
                'company_id' => company_id(),
                'document_number' => sprintf('NFSE364-%03d', $i),
                'status' => 'paid',
            ]);

            if ($i === 1) {
                $this->receipt($invoice, 'cancelled');
            }

            $this->receipt($invoice, 'emitted');
            $expected[] = (int) $invoice->id;
        }

        $commercialMismatch = Document::factory()->invoice()->create([
            'company_id' => company_id(),
            'document_number' => 'NFSE364-DRAFT',
            'status' => 'draft',
        ]);
        $this->receipt($commercialMismatch, 'emitted');

        $fiscalMismatch = Document::factory()->invoice()->create([
            'company_id' => company_id(),
            'document_number' => 'NFSE364-CANCELLED',
            'status' => 'paid',
        ]);
        $this->receipt($fiscalMismatch, 'cancelled');

        $response = $this->get(route('invoices.index', [
            'nfse_status' => 'emitted',
            'search' => 'status:paid NFSE364-',
            'sort' => 'document_number',
            'direction' => 'asc',
            'page' => 2,
        ]));

        $response->assertOk();
        $response->assertViewHas('invoices', static function ($invoices) use ($expected, $commercialMismatch, $fiscalMismatch): bool {
            $ids = $invoices->getCollection()->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            return $invoices->total() === 27
                && $invoices->currentPage() === 2
                && $invoices->count() === 2
                && count($ids) === count(array_unique($ids))
                && $ids === array_slice($expected, 25, 2)
                && !in_array((int) $commercialMismatch->id, $ids, true)
                && !in_array((int) $fiscalMismatch->id, $ids, true);
        });
    }

    public function testFiscalStatusIsRegisteredAsNativeSearchOption(): void
    {
        $this->loginAs();
        Document::factory()->invoice()->create(['company_id' => company_id()]);

        $response = $this->get(route('invoices.index'));

        $response->assertOk();
        $config = config('search-string.' . Document::class . '.columns.nfse_status');
        $response->assertDontSee('id="nfse-fiscal-status-filter"', false);
        self::assertSame('nfse::general.ledger.filter_fiscal_status', $config['translation'] ?? null);
        self::assertArrayHasKey('emitted', $config['values'] ?? []);
        self::assertArrayHasKey('absent', $config['values'] ?? []);
        $response->assertSee('nfse_status', false);
    }

    public function testNativeSearchChipFiltersInvoicesAndPreservesVisibleSearch(): void
    {
        $this->loginAs();

        $matching = Document::factory()->invoice()->create([
            'company_id' => company_id(),
            'status' => 'paid',
            'document_number' => 'NFSNATIVE-001',
        ]);
        $wrongFiscal = Document::factory()->invoice()->create([
            'company_id' => company_id(),
            'status' => 'paid',
            'document_number' => 'NFSNATIVE-002',
        ]);
        $wrongCommercial = Document::factory()->invoice()->create([
            'company_id' => company_id(),
            'status' => 'draft',
            'document_number' => 'NFSNATIVE-003',
        ]);
        $this->receipt($matching, 'emitted');
        $this->receipt($wrongFiscal, 'cancelled');
        $this->receipt($wrongCommercial, 'emitted');

        $search = 'status:paid nfse_status:emitted NFSNATIVE-';
        $response = $this->get(route('invoices.index', ['search' => $search]));

        $response->assertOk();
        $response->assertViewHas('invoices', static function ($invoices) use ($matching, $wrongFiscal, $wrongCommercial): bool {
            $ids = $invoices->getCollection()->pluck('id')->all();

            return in_array($matching->id, $ids, true)
                && !in_array($wrongFiscal->id, $ids, true)
                && !in_array($wrongCommercial->id, $ids, true);
        });
        self::assertSame($search, request()->input('search'));
    }

    public function testNativeFiscalSearchSupportsMultipleStatusesAndNegation(): void
    {
        $this->loginAs();

        $emitted = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $cancelled = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $category = $this->authorizeSyntheticCategory();
        $absent = Document::factory()->invoice()->create([
            'company_id' => company_id(), 'category_id' => $category->id,
        ]);
        $this->receipt($emitted, 'emitted');
        $this->receipt($cancelled, 'cancelled');

        $multiple = $this->get(route('invoices.index', ['search' => 'nfse_status:emitted,cancelled']));
        $multiple->assertOk();
        $multipleIds = $multiple->viewData('invoices')->getCollection()->pluck('id')->all();
        self::assertContains($emitted->id, $multipleIds);
        self::assertContains($cancelled->id, $multipleIds);
        self::assertNotContains($absent->id, $multipleIds);

        $negated = $this->get(route('invoices.index', ['search' => 'not nfse_status:emitted']));
        $negated->assertOk();
        $negatedIds = $negated->viewData('invoices')->getCollection()->pluck('id')->all();
        self::assertNotContains($emitted->id, $negatedIds);
        self::assertContains($cancelled->id, $negatedIds);
        self::assertContains($absent->id, $negatedIds);
    }

    public function testPendingIncludesAuthorizedInvoicesWithoutReceiptButNotUnconfiguredOnes(): void
    {
        $this->loginAs();
        $pending = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $category = $this->authorizeSyntheticCategory();
        $absent = Document::factory()->invoice()->create([
            'company_id' => company_id(), 'category_id' => $category->id,
        ]);
        $excludedCategory = Category::factory()->create([
            'company_id' => company_id(), 'type' => 'income', 'enabled' => true,
        ]);
        $excluded = Document::factory()->invoice()->create([
            'company_id' => company_id(), 'category_id' => $excludedCategory->id,
        ]);
        $this->receipt($pending, 'pending');

        $response = $this->get(route('invoices.index', ['search' => 'nfse_status:pending']));
        $response->assertOk();
        $ids = $response->viewData('invoices')->getCollection()->pluck('id')->all();
        self::assertContains($pending->id, $ids);
        self::assertContains($absent->id, $ids);
        self::assertNotContains($excluded->id, $ids);
    }

    private function authorizeSyntheticCategory(): Category
    {
        $category = Category::factory()->create([
            'company_id' => company_id(), 'type' => 'income', 'enabled' => true,
        ]);
        (new CategoryIssuancePolicy())->record((int) company_id(), (int) $category->id,
            (int) auth()->id(), 'issue', 'Synthetic eligible category for filter testing.');

        return $category;
    }

    /**
     * Collect all native Akaunting pages rather than relying on the first page
     * or assuming the native controller honors a custom limit parameter.
     *
     * @return list<int>
     */
    private function invoiceIdsAcrossPages(string $status): array
    {
        $ids = [];
        $page = 1;

        do {
            $response = $this->get(route('invoices.index', [
                'nfse_status' => $status,
                'page' => $page,
            ]));
            $response->assertOk();

            $invoices = $response->viewData('invoices');
            self::assertNotNull($invoices);
            self::assertTrue(method_exists($invoices, 'lastPage'));

            foreach ($invoices->getCollection() as $invoice) {
                $ids[] = (int) $invoice->id;
            }

            $lastPage = $invoices->lastPage();
            ++$page;
        } while ($page <= $lastPage);

        return $ids;
    }

    private function receipt(Document $invoice, string $status): void
    {
        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => (string) $invoice->id . '-' . $status,
            'chave_acesso' => str_repeat('1', 50),
            'status' => $status,
        ]);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Jobs\Auth\CreateUser;
use App\Models\Document\Document;
use App\Traits\Permissions;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class NativeInvoiceFiscalFilterTest extends FeatureTestCase
{
    use Permissions;
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

        $withoutReceipt = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $withReceipt = Document::factory()->invoice()->create(['company_id' => company_id()]);
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

    public function testNativeInvoiceViewAndFiscalFilterRequireSalesInvoiceReadPermission(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $this->receipt($invoice, 'emitted');

        $role = $this->createRole('nfse-native-invoice-restricted');
        $this->attachPermission($role, 'read-admin-panel');

        $restrictedUser = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$this->company->id],
            'roles' => [$role->id],
        ])));

        $this->withExceptionHandling()
            ->loginAs($restrictedUser)
            ->get(route('invoices.index', ['nfse_status' => 'emitted']))
            ->assertForbidden();

        $this->withExceptionHandling()
            ->loginAs($restrictedUser)
            ->get(route('invoices.show', $invoice->id))
            ->assertForbidden();
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

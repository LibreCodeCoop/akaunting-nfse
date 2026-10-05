<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Console\Commands;

use App\Models\Common\Company;
use App\Models\Common\Contact;
use App\Models\Common\Item;
use App\Models\Document\Document;
use App\Models\Setting\Category;
use Illuminate\Console\Command;
use Modules\Nfse\Models\AdnSyncDocument;
use Modules\Nfse\Models\ItemFiscalProfile;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\Testing\FiscalTestHarnessConfig;
use Modules\Nfse\Support\Testing\SyntheticPkcs12Factory;

final class ProvisionTestHarness extends Command
{
    protected $signature = 'nfse:test-harness:provision
        {--company-id=1 : Company ID used by deterministic tests}
        {--cnpj=11222333000181 : Synthetic provider CNPJ}
        {--municipio=3303302 : IBGE municipality code}
        {--item-lista=0107 : Canonical LC 116 item}
        {--codigo-nacional=010701 : National taxation code}
        {--password=nfse-test-password : Synthetic PKCS#12 password}
        {--substitution-fixture : Create an emitted invoice fixture for substitution UI tests}
        {--adn-review-fixture : Create a received NFS-e review fixture and explicit accounting mappings}
        {--item-validation-fixture : Create an item with a deterministic valid NFS-e fiscal profile}
        {--grouped-invoice-fixture : Create an invoice with two persisted fiscal-group receipts}
        {--pending-invoice-fixture : Create a pending invoice for modal accessibility tests}
        {--bulk-emission-fixture : Create deterministic ready and blocked invoices for bulk UI tests}
        {--json : Emit machine-readable output}';

    protected $description = 'Provision deterministic local/testing fiscal settings and synthetic PKCS#12 material';

    public function handle(): int
    {
        if (!FiscalTestHarnessConfig::enabled(
            (string) app()->environment(),
            (\getenv('NFSE_TEST_HARNESS') ?: false),
        )) {
            return $this->fail('NFSE_TEST_HARNESS=1 is required to provision deterministic fiscal fixtures.');
        }

        $companyId = (int) $this->option('company-id');
        $company = Company::query()->find($companyId);

        if (!$company instanceof Company) {
            return $this->fail('Company ' . $companyId . ' was not found.');
        }

        if (method_exists($company, 'makeCurrent')) {
            $company->makeCurrent();
        }

        $cnpj = preg_replace('/\D+/', '', (string) $this->option('cnpj')) ?: '';
        $municipio = preg_replace('/\D+/', '', (string) $this->option('municipio')) ?: '';
        $itemLista = preg_replace('/\D+/', '', (string) $this->option('item-lista')) ?: '';
        $codigoNacional = preg_replace('/\D+/', '', (string) $this->option('codigo-nacional')) ?: '';
        $password = (string) $this->option('password');

        if (preg_match('/^\d{14}$/', $cnpj) !== 1) {
            return $this->fail('Synthetic provider CNPJ must contain 14 digits.');
        }

        if (preg_match('/^\d{7}$/', $municipio) !== 1) {
            return $this->fail('Municipality IBGE code must contain 7 digits.');
        }

        if (preg_match('/^\d{4}$/', $itemLista) !== 1) {
            return $this->fail('LC 116 item must contain 4 digits.');
        }

        if (preg_match('/^\d{6}$/', $codigoNacional) !== 1) {
            return $this->fail('National taxation code must contain 6 digits.');
        }

        if ($password === '') {
            return $this->fail('Synthetic PKCS#12 password cannot be empty.');
        }

        $directory = storage_path('app/nfse/pfx');
        $fileName = $cnpj . '.pfx';
        $pkcs12 = SyntheticPkcs12Factory::create($directory, $fileName, $password);

        setting()->set([
            'nfse.cnpj_prestador' => $cnpj,
            'nfse.municipio_ibge' => $municipio,
            'nfse.item_lista_servico' => $itemLista,
            'nfse.codigo_tributacao_nacional' => $codigoNacional,
            'nfse.sandbox_mode' => '1',
            'nfse.bao_addr' => 'http://openbao.invalid.test:8200',
            'nfse.bao_mount' => 'secret',
            'nfse.bao_token' => 'deterministic-test-token',
        ]);
        setting()->save();

        $payload = [
            'company_id' => $companyId,
            'cnpj' => $cnpj,
            'municipio_ibge' => $municipio,
            'item_lista_servico' => $itemLista,
            'codigo_tributacao_nacional' => $codigoNacional,
            'pfx_path' => $pkcs12['path'],
            'pfx_password' => $pkcs12['password'],
        ];

        if ((bool) $this->option('substitution-fixture')) {
            $invoice = Document::query()
                ->where('company_id', $companyId)
                ->where('type', 'invoice')
                ->where('document_number', 'NFSE-E2E-SUBSTITUTION')
                ->first();

            if (!$invoice instanceof Document) {
                $invoice = Document::factory()->invoice()->create([
                    'company_id' => $companyId,
                    'document_number' => 'NFSE-E2E-SUBSTITUTION',
                ]);
            }

            NfseReceipt::query()->updateOrCreate(
                [
                    'invoice_id' => (int) $invoice->id,
                    'nfse_number' => '4242',
                ],
                [
                    'chave_acesso' => str_repeat('4', 50),
                    'status' => 'emitted',
                ],
            );

            $payload['substitution_invoice_id'] = (int) $invoice->id;
        }

        if ((bool) $this->option('pending-invoice-fixture')) {
            $invoice = Document::query()
                ->where('company_id', $companyId)
                ->where('type', 'invoice')
                ->where('document_number', 'NFSE-E2E-PENDING')
                ->first();

            if (!$invoice instanceof Document) {
                $invoice = Document::factory()->invoice()->create([
                    'company_id' => $companyId,
                    'document_number' => 'NFSE-E2E-PENDING',
                    'amount' => 100.00,
                ]);
            }

            NfseReceipt::query()
                ->where('invoice_id', (int) $invoice->id)
                ->delete();

            $payload['pending_invoice_id'] = (int) $invoice->id;
        }

        if ((bool) $this->option('grouped-invoice-fixture')) {
            $invoice = Document::query()
                ->where('company_id', $companyId)
                ->where('type', 'invoice')
                ->where('document_number', 'NFSE-E2E-GROUPED')
                ->first();

            if (!$invoice instanceof Document) {
                $invoice = Document::factory()->invoice()->create([
                    'company_id' => $companyId,
                    'document_number' => 'NFSE-E2E-GROUPED',
                    'amount' => 100.00,
                ]);
            }

            NfseReceipt::query()->updateOrCreate(
                [
                    'invoice_id' => (int) $invoice->id,
                    'emission_group_key' => 'service:0107|tax:010701|rate:2.00',
                ],
                [
                    'nfse_number' => '5101',
                    'chave_acesso' => str_repeat('5', 50),
                    'status' => 'emitted',
                ],
            );

            NfseReceipt::query()->updateOrCreate(
                [
                    'invoice_id' => (int) $invoice->id,
                    'emission_group_key' => 'service:0101|tax:010101|rate:3.00',
                ],
                [
                    'nfse_number' => '5102',
                    'chave_acesso' => str_repeat('6', 50),
                    'status' => 'emitted',
                ],
            );

            $payload['grouped_invoice_id'] = (int) $invoice->id;
        }

        if ((bool) $this->option('bulk-emission-fixture')) {
            $ready = $this->bulkInvoiceFixture(
                companyId: $companyId,
                documentNumber: 'NFSE-E2E-BULK-READY',
                country: 'BR',
                itemLista: $itemLista,
                codigoNacional: $codigoNacional,
            );
            $blocked = $this->bulkInvoiceFixture(
                companyId: $companyId,
                documentNumber: 'NFSE-E2E-BULK-BLOCKED',
                country: 'GB',
                itemLista: $itemLista,
                codigoNacional: $codigoNacional,
            );

            $payload['bulk_ready_invoice_id'] = (int) $ready->id;
            $payload['bulk_blocked_invoice_id'] = (int) $blocked->id;
        }

        if ((bool) $this->option('item-validation-fixture')) {
            $item = Item::query()
                ->where('company_id', $companyId)
                ->where('name', 'NFSE E2E Fiscal Item')
                ->first();

            if (!$item instanceof Item) {
                $item = Item::factory()->enabled()->create([
                    'company_id' => $companyId,
                    'name' => 'NFSE E2E Fiscal Item',
                    'sale_price' => 100.00,
                ]);
            }

            ItemFiscalProfile::query()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'item_id' => (int) $item->id,
                ],
                [
                    'item_lista_servico' => $itemLista,
                    'codigo_tributacao_nacional' => $codigoNacional,
                    'aliquota' => '2.00',
                ],
            );

            $payload['item_validation_item_id'] = (int) $item->id;
        }

        if ((bool) $this->option('adn-review-fixture')) {
            $vendor = Contact::query()
                ->where('company_id', $companyId)
                ->where('type', Contact::VENDOR_TYPE)
                ->where('name', 'NFSE E2E Vendor')
                ->first();

            if (!$vendor instanceof Contact) {
                $vendor = Contact::factory()->vendor()->enabled()->create([
                    'company_id' => $companyId,
                    'name' => 'NFSE E2E Vendor',
                    'tax_number' => '99887766000155',
                ]);
            }

            $category = Category::query()
                ->where('company_id', $companyId)
                ->where('type', Category::EXPENSE_TYPE)
                ->where('name', 'NFSE E2E Expense')
                ->first();

            if (!$category instanceof Category) {
                $category = Category::factory()->expense()->create([
                    'company_id' => $companyId,
                    'name' => 'NFSE E2E Expense',
                ]);
            }

            $item = Item::query()
                ->where('company_id', $companyId)
                ->where('name', 'NFSE E2E Received Service')
                ->first();

            if (!$item instanceof Item) {
                $item = Item::factory()->enabled()->create([
                    'company_id' => $companyId,
                    'name' => 'NFSE E2E Received Service',
                    'purchase_price' => 100.00,
                    'category_id' => $category->id,
                ]);
            }

            $adnDocument = AdnSyncDocument::query()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'environment' => 'sandbox',
                    'document_key' => 'nfse:e2e-received',
                ],
                [
                    'nsu' => 9001,
                    'chave_acesso' => str_repeat('8', 50),
                    'tipo_documento' => 'NFSe',
                    'xml' => '<NFSe><infNFSe><prest><CNPJ>11222333000181</CNPJ><xNome>Fornecedor ADN E2E Ltda</xNome></prest><DPS><infDPS><dCompet>2026-10-01</dCompet><serv><cServ><xDescServ>Consultoria ADN deterministica</xDescServ></cServ></serv><valores><vServPrest><vServ>100.00</vServ></vServPrest></valores></infDPS></DPS><valores><vLiq>100.00</vLiq></valores></infNFSe></NFSe>',
                    'recognized_event' => false,
                    'fiscal_role' => 'received',
                    'review_status' => 'pending',
                    'ignored_at' => null,
                    'imported_document_id' => null,
                ],
            );

            $payload['adn_review_document_id'] = (int) $adnDocument->id;
            $payload['adn_vendor_id'] = (int) $vendor->id;
            $payload['adn_category_id'] = (int) $category->id;
            $payload['adn_item_id'] = (int) $item->id;
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Deterministic NFS-e fiscal harness provisioned for company ' . $companyId . '.');
        $this->line('PFX path: ' . $pkcs12['path']);

        return self::SUCCESS;
    }

    private function bulkInvoiceFixture(
        int $companyId,
        string $documentNumber,
        string $country,
        string $itemLista,
        string $codigoNacional,
    ): Document {
        $contact = Contact::query()
            ->where('company_id', $companyId)
            ->where('type', Contact::CUSTOMER_TYPE)
            ->where('name', $documentNumber . ' Customer')
            ->first();

        if (!$contact instanceof Contact) {
            $contact = Contact::factory()->customer()->enabled()->create([
                'company_id' => $companyId,
                'name' => $documentNumber . ' Customer',
                'country' => $country,
            ]);
        } else {
            $contact->forceFill(['country' => $country])->saveQuietly();
        }

        $invoice = Document::query()
            ->where('company_id', $companyId)
            ->where('type', 'invoice')
            ->where('document_number', $documentNumber)
            ->first();

        if (!$invoice instanceof Document) {
            $invoice = Document::factory()->invoice()->create([
                'company_id' => $companyId,
                'document_number' => $documentNumber,
                'contact_id' => $contact->id,
                'amount' => 100.00,
            ]);
        } else {
            $invoice->forceFill(['contact_id' => $contact->id])->saveQuietly();
        }

        $invoice->unsetRelation('contact');
        $invoice->items()->delete();
        $invoice->unsetRelation('items');

        $item = Item::query()
            ->where('company_id', $companyId)
            ->where('name', $documentNumber . ' Item')
            ->first();

        if (!$item instanceof Item) {
            $item = Item::factory()->enabled()->create([
                'company_id' => $companyId,
                'name' => $documentNumber . ' Item',
                'sale_price' => 100.00,
            ]);
        }

        $invoice->items()->create([
            'company_id' => $companyId,
            'type' => 'item',
            'item_id' => $item->id,
            'name' => $documentNumber . ' Item',
            'quantity' => 1,
            'price' => '100.00',
            'total' => '100.00',
        ]);

        ItemFiscalProfile::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'item_id' => (int) $item->id,
            ],
            [
                'item_lista_servico' => $itemLista,
                'codigo_tributacao_nacional' => $codigoNacional,
                'aliquota' => '5.00',
            ],
        );

        NfseReceipt::query()
            ->where('invoice_id', (int) $invoice->id)
            ->delete();

        $invoice->load(['items', 'contact']);

        return $invoice;
    }

    private function fail(string $message): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(['error' => $message], JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}

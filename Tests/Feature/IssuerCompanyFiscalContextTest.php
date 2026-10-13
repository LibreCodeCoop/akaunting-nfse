<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document as Invoice;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Modules\Nfse\Models\NfseCompanyProfile;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\NfseCompanyCertificatePath;
use Modules\Nfse\Support\NfseCompanyIssuerPayload;
use Tests\Feature\FeatureTestCase;

final class IssuerCompanyFiscalContextTest extends FeatureTestCase
{
    private const CNPJ = '11222333000181';

    public function testOnlyOwningInvoiceCompanySuppliesDpsIssuerParameters(): void
    {
        $this->loginAs();
        $owner = (int) company_id();
        $other = $this->secondCompany();
        $this->profile($owner, [
            'municipio_ibge' => '3304557',
            'opcao_simples_nacional' => '3',
            'regime_apuracao_simples_nacional' => '2',
            'regime_especial_tributacao' => '9',
            'tributacao_issqn' => '3',
            'issqn_pais_resultado' => 'US',
            'tipo_retencao_iss' => '1',
            'prestador_email' => 'owner@example.test',
            'sandbox_mode' => '1',
        ]);
        $this->profile($other, [
            'municipio_ibge' => '4106902',
            'opcao_simples_nacional' => '1',
            'regime_especial_tributacao' => '0',
            'tributacao_issqn' => '2',
            'issqn_tipo_imunidade' => '4',
            'prestador_email' => 'other@example.test',
            'sandbox_mode' => '0',
        ]);
        // Deliberate conflicting legacy configuration must have no influence.
        setting([
            'nfse.municipio_ibge' => '9999999',
            'nfse.prestador_email' => 'legacy@example.test',
            'nfse.opcao_simples_nacional' => '2',
            'nfse.sandbox_mode' => '0',
        ]);
        setting()->save();

        $invoice = new Invoice();
        $invoice->company_id = $owner;
        $controller = $this->controller();
        $facts = $controller->factsFor($invoice);

        self::assertSame(self::CNPJ, $facts['cnpj_prestador']);
        self::assertSame('3304557', $facts['municipio_ibge']);
        self::assertSame('3', $facts['opcao_simples_nacional']);
        self::assertSame('2', $facts['regime_apuracao_simples_nacional']);
        self::assertSame('owner@example.test', $facts['contact']['email']);
        self::assertSame('US', $facts['issqn']['issqnPaisResultado']);
        self::assertTrue($facts['sandbox']);

        $otherInvoice = new Invoice();
        $otherInvoice->company_id = $other;
        $this->expectException(\DomainException::class);
        $controller->factsFor($otherInvoice);
    }

    public function testMissingCompanyProfileFailsClosedEvenIfLegacyIssuerWasConfigured(): void
    {
        $this->loginAs();
        setting([
            'nfse.cnpj_prestador' => self::CNPJ,
            'nfse.municipio_ibge' => '3304557',
            'nfse.opcao_simples_nacional' => '3',
        ]);
        setting()->save();

        $invoice = new Invoice();
        $invoice->company_id = (int) company_id();

        $this->expectException(\DomainException::class);
        $this->controller()->factsFor($invoice);
    }

    public function testCompanyProfileWithoutPhysicalCertificateCannotPassTransmissionPreflight(): void
    {
        $this->loginAs();
        $owner = (int) company_id();
        $this->profile($owner, [
            'uf' => 'RJ',
            'municipio_ibge' => '3304557',
            'opcao_simples_nacional' => '1',
            'regime_especial_tributacao' => '0',
            'tributacao_issqn' => '1',
            'tipo_retencao_iss' => '1',
        ]);

        $invoice = new Invoice();
        $invoice->company_id = $owner;
        $controller = $this->controller();
        $controller->factsFor($invoice);
        $readiness = $controller->preflight();

        self::assertFalse($readiness['isReady']);
        self::assertFalse($readiness['checklist']['company_certificate']);
    }

    public function testBulkReceiptReconciliationCannotSelectAnotherCompanyReceipt(): void
    {
        $this->loginAs();
        $other = $this->secondCompany();

        $ownInvoice = Invoice::factory()->invoice()->create();
        $otherInvoice = Invoice::factory()->invoice()->create(['company_id' => $other]);

        $own = NfseReceipt::query()->create([
            'invoice_id' => $ownInvoice->id,
            'nfse_number' => 'SYNTH-OWN',
            'chave_acesso' => str_repeat('A', 50),
            'status' => 'processing',
        ]);
        $foreign = NfseReceipt::query()->create([
            'invoice_id' => $otherInvoice->id,
            'nfse_number' => 'SYNTH-FOREIGN',
            'chave_acesso' => str_repeat('B', 50),
            'status' => 'processing',
        ]);

        $controller = new class () extends InvoiceController {
            /** @return list<int> */
            public function refreshableIds(): array
            {
                $ids = [];
                foreach ($this->refreshableReceipts() as $receipt) {
                    $ids[] = (int) $receipt->id;
                }

                return $ids;
            }

            /** @return array{total:int,emitted:int,cancelled:int,sandbox_mode:bool} */
            public function scopedStats(): array
            {
                return $this->dashboardStats();
            }
        };

        self::assertSame([(int) $own->id], $controller->refreshableIds());
        self::assertNotContains((int) $foreign->id, $controller->refreshableIds());
        self::assertSame(1, $controller->scopedStats()['total']);
        self::assertSame(0, $controller->scopedStats()['emitted']);
    }

    private function controller(): InvoiceController
    {
        return new class () extends InvoiceController {
            /** @return array<string,mixed> */
            public function factsFor(Invoice $invoice): array
            {
                $fiscal = $this->bindIssuerFiscalParameters($invoice);
                $mapper = new NfseCompanyIssuerPayload();

                return [
                    'cnpj_prestador' => $fiscal['cnpj_prestador'] ?? '',
                    'municipio_ibge' => $fiscal['municipio_ibge'] ?? '',
                    'opcao_simples_nacional' => $fiscal['opcao_simples_nacional'] ?? null,
                    'regime_apuracao_simples_nacional' => $fiscal['regime_apuracao_simples_nacional'] ?? null,
                    'issqn' => $mapper->issqn($fiscal),
                    'contact' => $mapper->contact($fiscal),
                    'sandbox' => $mapper->sandboxMode($fiscal),
                ];
            }

            /** @return array{isReady:bool,checklist:array<string,bool>} */
            public function preflight(): array
            {
                return $this->companyEmissionReadiness();
            }
        };
    }

    /** @param array<string,mixed> $parameters */
    private function profile(int $companyId, array $parameters): void
    {
        NfseCompanyProfile::query()->create([
            'company_id' => $companyId,
            'status' => 'active',
            'certificate_ref' => NfseCompanyCertificatePath::newReference(),
            'certificate_cnpj' => self::CNPJ,
            'certificate_valid_from' => time() - 3600,
            'certificate_valid_to' => time() + 3600,
            'fiscal_parameters' => $parameters,
        ]);
    }

    private function secondCompany(): int
    {
        $companyId = (int) DB::table('companies')->insertGetId([
            'domain' => 'synthetic-issuer-foreign-company.example.test',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_companies')->insert([
            'user_id' => (int) auth()->id(),
            'company_id' => $companyId,
        ]);

        return $companyId;
    }
}

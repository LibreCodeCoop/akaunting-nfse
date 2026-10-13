<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Modules\Nfse\Application\AutomaticInvoiceEmissionPreflight;
use Modules\Nfse\Application\InvoiceDpsIdentity;
use Modules\Nfse\Models\NfseCompanyProfile;
use Modules\Nfse\Support\VaultConfig;
use Tests\Feature\FeatureTestCase;

final class TestHarnessProvisioningTest extends FeatureTestCase
{
    private const CNPJ = '11222333000181';

    protected function tearDown(): void
    {
        \putenv('NFSE_TEST_HARNESS');

        $profile = NfseCompanyProfile::query()
            ->where('company_id', (int) $this->company->id)
            ->first();
        $path = $profile?->certificateFilePath();
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }

        parent::tearDown();
    }

    public function testProvisionCommandCreatesSyntheticCertificateAndFiscalSettings(): void
    {
        \putenv('NFSE_TEST_HARNESS=1');

        $this->artisan('nfse:test-harness:provision', [
            '--company-id' => (string) $this->company->id,
            '--cnpj' => self::CNPJ,
            '--municipio' => '3303302',
            '--item-lista' => '0107',
            '--codigo-nacional' => '010701',
            '--password' => 'browser-test-password',
        ])->assertSuccessful();

        $profile = NfseCompanyProfile::query()->where('company_id', (int) $this->company->id)->sole();
        $path = $profile->certificateFilePath();

        self::assertSame('active', $profile->status);
        self::assertSame(self::CNPJ, $profile->certificate_cnpj);
        self::assertStringStartsWith('pfx/companies/' . $this->company->id . '/', (string) $profile->certificateVaultPath());
        self::assertFileExists((string) $path);
        self::assertSame('11222333000181', (string) setting('nfse.cnpj_prestador'));
        self::assertSame('3303302', (string) setting('nfse.municipio_ibge'));
        self::assertSame('0107', (string) setting('nfse.item_lista_servico'));
        self::assertSame('010701', (string) setting('nfse.codigo_tributacao_nacional'));
        self::assertSame('0', (string) setting('nfse.regime_especial_tributacao'));
        self::assertSame('1', (string) setting('nfse.sandbox_mode'));
        self::assertNull(setting('nfse.bao_addr'));
        self::assertNull(setting('nfse.bao_token'));

        $vault = VaultConfig::secretStoreConfig();
        self::assertSame('https://127.0.0.1:29999', $vault['addr']);
        self::assertSame('/secret', $vault['mount']);
        self::assertSame('deterministic-test-token', $vault['token']);

        $pkcs12 = file_get_contents($path);
        self::assertIsString($pkcs12);

        $certificates = [];
        self::assertTrue(openssl_pkcs12_read($pkcs12, $certificates, 'browser-test-password'));
        self::assertArrayHasKey('cert', $certificates);
        self::assertArrayHasKey('pkey', $certificates);
    }

    public function testBulkFixtureUsesStableCompetenceAndPreflightOutcomes(): void
    {
        \putenv('NFSE_TEST_HARNESS=1');

        $this->artisan('nfse:test-harness:provision', [
            '--company-id' => (string) $this->company->id,
            '--bulk-emission-fixture' => true,
        ])->assertSuccessful();

        $ready = Document::query()
            ->where('company_id', $this->company->id)
            ->where('document_number', 'NFSE-E2E-BULK-READY')
            ->firstOrFail();
        $blocked = Document::query()
            ->where('company_id', $this->company->id)
            ->where('document_number', 'NFSE-E2E-BULK-BLOCKED')
            ->firstOrFail();

        self::assertSame('2026-09-30', (new InvoiceDpsIdentity())->competenceDate($ready));

        $preflight = new AutomaticInvoiceEmissionPreflight();
        self::assertSame('ready', $preflight->evaluate($ready)['status']);
        $blockedResult = $preflight->evaluate($blocked);
        self::assertSame('blocked', $blockedResult['status']);
        self::assertSame('foreign_taker_requires_review', $blockedResult['reason']);
    }

    public function testAtomicityBrowserFixtureCreatesLegacyItemAndInjectsIsolatedSqlFailure(): void
    {
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The browser fault fixture is SQLite-only.');
        }

        \putenv('NFSE_TEST_HARNESS=1');

        $this->artisan('nfse:test-harness:provision', [
            '--company-id' => (string) $this->company->id,
            '--item-atomicity-fixture' => true,
        ])->assertSuccessful();

        $legacy = \App\Models\Common\Item::query()
            ->where('company_id', $this->company->id)
            ->where('name', 'NFSE E2E Legacy Fiscal Item')
            ->firstOrFail();
        $failing = \App\Models\Common\Item::query()
            ->where('company_id', $this->company->id)
            ->where('name', 'NFSE E2E Failing Fiscal Item')
            ->firstOrFail();

        $this->assertDatabaseHas('nfse_item_fiscal_profiles', [
            'company_id' => $this->company->id,
            'item_id' => $legacy->id,
            'codigo_tributacao_nacional' => '999999',
        ]);
        $this->assertDatabaseHas('nfse_item_fiscal_profiles', [
            'company_id' => $this->company->id,
            'item_id' => $failing->id,
            'codigo_tributacao_nacional' => '010701',
        ]);

        try {
            \Modules\Nfse\Models\ItemFiscalProfile::query()
                ->where('company_id', $this->company->id)
                ->where('item_id', $failing->id)
                ->update(['codigo_tributacao_nacional' => '010101']);
            self::fail('Expected the synthetic fiscal UPDATE to fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            self::assertStringContainsString('Synthetic fiscal persistence failure', $error->getMessage());
        }

        $this->assertDatabaseHas('nfse_item_fiscal_profiles', [
            'company_id' => $this->company->id,
            'item_id' => $failing->id,
            'codigo_tributacao_nacional' => '010701',
        ]);
    }

    public function testProvisionCommandRefusesToRunWithoutExplicitHarnessFlag(): void
    {
        \putenv('NFSE_TEST_HARNESS');

        $this->artisan('nfse:test-harness:provision', [
            '--company-id' => (string) $this->company->id,
        ])->assertFailed();
    }
}

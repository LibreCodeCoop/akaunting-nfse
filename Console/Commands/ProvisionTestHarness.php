<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Console\Commands;

use App\Models\Common\Company;
use App\Models\Document\Document;
use Illuminate\Console\Command;
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

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Deterministic NFS-e fiscal harness provisioned for company ' . $companyId . '.');
        $this->line('PFX path: ' . $pkcs12['path']);

        return self::SUCCESS;
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

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Jobs\Auth\CreateUser;
use App\Models\Document\Document;
use App\Traits\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\NfseCompanyProfile;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\GlobalVaultSettings;
use Modules\Nfse\Support\NfseCompanyCertificatePath;
use Modules\Nfse\Support\VaultDiagnosticTransport;
use Modules\Nfse\Support\VaultDiagnostics;
use Modules\Nfse\Tests\Support\SyntheticCompanyPkcs12;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Tests\Feature\FeatureTestCase;

/** Test-only Vault KV; failures may occur after a write has taken effect. */
final class CompanyProfileFakeSecrets implements SecretStoreInterface
{
    /** @var array<string,array<string,string>> */
    public array $data = [];

    /** @var list<string> */
    public array $filePaths = [];

    public bool $failPutAfterWrite = false;
    public bool $failDelete = false;

    public function get(string $path): array
    {
        if (!isset($this->data[$path])) {
            throw new \RuntimeException('Synthetic secret does not exist.');
        }

        return $this->data[$path];
    }

    public function put(string $path, array $data): void
    {
        $this->data[$path] = $data;
        if (isset($data['pfx_path']) && is_string($data['pfx_path'])) {
            $this->filePaths[] = $data['pfx_path'];
        }

        if ($this->failPutAfterWrite) {
            $this->failPutAfterWrite = false;
            throw new \RuntimeException('Synthetic uncertain Vault write.');
        }
    }

    public function delete(string $path): void
    {
        if ($this->failDelete) {
            throw new \RuntimeException('Synthetic Vault deletion failure.');
        }

        unset($this->data[$path]);
    }
}

/** Deterministic HTTP responses for the exact KV v2 capabilities path. */
final class CompanyProfileFakeVaultTransport implements VaultDiagnosticTransport
{
    public bool $offline = false;
    public bool $denyCompanyPaths = false;

    /** @var list<string> */
    public array $paths = [];

    public function request(string $method, string $url, ?string $token = null, array $body = []): array
    {
        if (str_ends_with($url, '/sys/health')) {
            return ['status' => $this->offline ? 503 : 200, 'body' => []];
        }

        if (str_ends_with($url, '/auth/token/lookup-self')) {
            return ['status' => 200, 'body' => []];
        }

        if (str_contains($url, '/sys/internal/ui/mounts/')) {
            return ['status' => 200, 'body' => ['data' => ['type' => 'kv', 'options' => ['version' => 2]]]];
        }

        if (str_ends_with($url, '/sys/capabilities-self')) {
            $path = (string) ($body['paths'][0] ?? '');
            $this->paths[] = $path;
            $rights = $this->denyCompanyPaths && str_contains($path, '/pfx/companies/')
                ? ['deny'] : ['read', 'create', 'update', 'delete'];

            return ['status' => 200, 'body' => [$path => $rights]];
        }

        return ['status' => 404, 'body' => []];
    }
}

final class CompanyProfileLifecycleTest extends FeatureTestCase
{
    use Permissions;

    private const CNPJ = '12345678000195';

    private CompanyProfileFakeSecrets $secrets;
    private CompanyProfileFakeVaultTransport $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs();
        $this->withExceptionHandling()->withHeader('Accept', 'application/json');

        (new GlobalVaultSettings())->save([
            'addr' => 'https://vault.example.test',
            'mount' => '/nfse',
            'auth_mode' => 'token',
            'token' => 'synthetic-global-token',
        ]);
        setting()->set(['company.tax_number' => self::CNPJ]);
        setting()->save();

        $this->secrets = new CompanyProfileFakeSecrets();
        $this->vault = new CompanyProfileFakeVaultTransport();
        app()->instance(SecretStoreInterface::class, $this->secrets);
        app()->instance(VaultDiagnostics::class, new VaultDiagnostics($this->vault));
    }

    protected function tearDown(): void
    {
        foreach (array_unique($this->secrets->filePaths ?? []) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testCreateIsUniqueAndOnlyExposesSafeMetadata(): void
    {
        $id = (int) $this->company->id;
        $response = $this->post(route('nfse.settings.company-profiles.store'), $this->creation($id))
            ->assertCreated()
            ->assertJsonPath('data.company_id', $id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.fiscal_parameters.municipio_ibge', '3304557');

        self::assertSame(1, NfseCompanyProfile::query()->where('company_id', $id)->count());
        self::assertStringNotContainsString(SyntheticCompanyPkcs12::PASSWORD, $response->getContent());
        self::assertStringNotContainsString('synthetic-global-token', $response->getContent());

        $profile = NfseCompanyProfile::query()->where('company_id', $id)->firstOrFail();
        self::assertSame(self::CNPJ, $profile->certificate_cnpj);
        self::assertNotNull($profile->certificateVaultPath());
        self::assertStringStartsWith('pfx/companies/' . $id . '/', (string) $profile->certificateVaultPath());

        $listing = $this->get(route('nfse.settings.company-profiles.index'))
            ->assertOk()->json();
        self::assertCount(1, $listing['profiles']);
        self::assertCount(0, $listing['eligible_companies']);

        $this->post(route('nfse.settings.company-profiles.store'), $this->creation($id))
            ->assertStatus(409);
        self::assertSame(1, NfseCompanyProfile::query()->where('company_id', $id)->count());
    }

    public function testUserCannotDiscoverOrMutateACompanyOutsideTheirAssignments(): void
    {
        $other = $this->secondCompany(false);

        $listing = $this->get(route('nfse.settings.company-profiles.index'))
            ->assertOk()->json();
        self::assertCount(1, $listing['eligible_companies']);
        self::assertNotContains($other, array_column($listing['eligible_companies'], 'company_id'));

        $this->post(route('nfse.settings.company-profiles.store'), $this->creation($other))
            ->assertNotFound();
        $this->patch(route('nfse.settings.company-profiles.update', $other), [
            'fiscal_parameters' => ['emission_policy' => 'manual'],
        ])->assertNotFound();
        $this->delete(route('nfse.settings.company-profiles.destroy', $other))
            ->assertNotFound();
    }

    public function testSameCnpjCompaniesHaveSeparateCertificatesAndEligibleSelection(): void
    {
        $first = (int) $this->company->id;
        $second = $this->secondCompany(true);

        $this->post(route('nfse.settings.company-profiles.store'), $this->creation($first))
            ->assertCreated();
        $eligible = $this->get(route('nfse.settings.company-profiles.index'))
            ->assertOk()->json('eligible_companies');
        self::assertSame([$second], array_column($eligible, 'company_id'));

        $this->post(route('nfse.settings.company-profiles.store'), $this->creation($second))
            ->assertCreated();

        $a = NfseCompanyProfile::query()->where('company_id', $first)->firstOrFail();
        $b = NfseCompanyProfile::query()->where('company_id', $second)->firstOrFail();
        self::assertSame(self::CNPJ, $a->certificate_cnpj);
        self::assertSame(self::CNPJ, $b->certificate_cnpj);
        self::assertNotSame($a->certificateVaultPath(), $b->certificateVaultPath());
        self::assertNotSame($a->certificateFilePath(), $b->certificateFilePath());
        self::assertCount(2, $this->secrets->data);

        $this->delete(route('nfse.settings.company-profiles.destroy', $first))
            ->assertNoContent();
        self::assertCount(1, $this->secrets->data);
        self::assertSame(1, NfseCompanyProfile::query()->where('company_id', $second)->count());

        $available = $this->get(route('nfse.settings.company-profiles.index'))
            ->assertOk()->json('eligible_companies');
        self::assertSame([$first], array_column($available, 'company_id'));
    }

    public function testAHealthyGenericVaultProbeDoesNotGrantTenantCertificateAccess(): void
    {
        $this->vault->denyCompanyPaths = true;

        $generic = (new VaultDiagnostics($this->vault))->check(
            \Modules\Nfse\Support\VaultConfig::secretStoreConfig(),
        );
        self::assertSame('operational', $generic['state']);

        $this->post(route('nfse.settings.company-profiles.store'), $this->creation((int) $this->company->id))
            ->assertStatus(409);
        self::assertSame(0, NfseCompanyProfile::query()->count());
        self::assertCount(0, $this->secrets->data);
        self::assertTrue(count($this->vault->paths) >= 2);
    }

    public function testFailedVaultWriteIsCompensatedAndUncertainCleanupRemainsVisible(): void
    {
        $id = (int) $this->company->id;
        $this->secrets->failPutAfterWrite = true;

        $this->post(route('nfse.settings.company-profiles.store'), $this->creation($id))
            ->assertStatus(409);

        self::assertSame(0, NfseCompanyProfile::query()->count());
        self::assertSame([], $this->secrets->data);

        $this->secrets->failPutAfterWrite = true;
        $this->secrets->failDelete = true;
        $this->post(route('nfse.settings.company-profiles.store'), $this->creation($id))
            ->assertStatus(409);

        $incomplete = NfseCompanyProfile::query()->where('company_id', $id)->firstOrFail();
        self::assertSame('provisioning', $incomplete->status);
        self::assertNull($incomplete->certificate_ref);
        self::assertNotNull($incomplete->staged_certificate_ref);
        self::assertSame(1, count($this->secrets->data));

        $this->secrets->failDelete = false;
        $this->post(route('nfse.settings.company-profiles.cleanup', $id))
            ->assertOk();
        self::assertSame(0, NfseCompanyProfile::query()->count());
        self::assertSame([], $this->secrets->data);
    }

    public function testRotationKeepsPreviousCertificateUntilNewRevisionIsPublished(): void
    {
        $id = (int) $this->company->id;
        $this->post(route('nfse.settings.company-profiles.store'), $this->creation($id))
            ->assertCreated();
        $before = NfseCompanyProfile::query()->where('company_id', $id)->firstOrFail();
        $oldRef = (string) $before->certificate_ref;

        $this->secrets->failPutAfterWrite = true;
        $this->patch(route('nfse.settings.company-profiles.update', $id), [
            'pfx_file' => $this->certificate(),
            'pfx_password' => SyntheticCompanyPkcs12::PASSWORD,
        ])->assertStatus(409);

        self::assertSame($oldRef, NfseCompanyProfile::query()->where('company_id', $id)->value('certificate_ref'));
        self::assertCount(1, $this->secrets->data);

        $this->patch(route('nfse.settings.company-profiles.update', $id), [
            'pfx_file' => $this->certificate(),
            'pfx_password' => SyntheticCompanyPkcs12::PASSWORD,
            'fiscal_parameters' => ['emission_policy' => 'manual'],
        ])->assertOk()->assertJsonPath('data.fiscal_parameters.emission_policy', 'manual');

        $newRef = (string) NfseCompanyProfile::query()->where('company_id', $id)->value('certificate_ref');
        self::assertNotSame($oldRef, $newRef);
        self::assertCount(1, $this->secrets->data);
        self::assertSame(0, DB::table('nfse_company_certificate_retirements')->count());
        self::assertArrayHasKey(NfseCompanyCertificatePath::vaultPath($id, $newRef), $this->secrets->data);
    }

    public function testDeletionFailureIsRetriableWithoutRemovingFiscalHistory(): void
    {
        $id = (int) $this->company->id;
        $this->post(route('nfse.settings.company-profiles.store'), $this->creation($id))
            ->assertCreated();
        $invoice = Document::factory()->invoice()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => 'SYNTH-100',
            'chave_acesso' => str_repeat('A', 50),
            'status' => 'emitted',
        ]);

        $this->secrets->failDelete = true;
        $this->delete(route('nfse.settings.company-profiles.destroy', $id))
            ->assertStatus(409);
        self::assertSame('deleting', NfseCompanyProfile::query()->where('company_id', $id)->value('status'));
        self::assertNotNull(NfseReceipt::query()->find($receipt->id));

        $eligible = $this->get(route('nfse.settings.company-profiles.index'))
            ->assertOk()->json('eligible_companies');
        self::assertCount(0, $eligible);

        $this->secrets->failDelete = false;
        $this->post(route('nfse.settings.company-profiles.cleanup', $id))
            ->assertOk();
        self::assertSame(0, NfseCompanyProfile::query()->count());
        self::assertNotNull(NfseReceipt::query()->find($receipt->id));
    }

    public function testPendingFiscalReceiptBlocksRotationAndDeletion(): void
    {
        $id = (int) $this->company->id;
        $this->post(route('nfse.settings.company-profiles.store'), $this->creation($id))
            ->assertCreated();

        $invoice = Document::factory()->invoice()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => 'SYNTH-PENDING',
            'chave_acesso' => str_repeat('B', 50),
            'status' => 'processing',
        ]);
        $currentRef = (string) NfseCompanyProfile::query()->where('company_id', $id)->value('certificate_ref');

        $this->patch(route('nfse.settings.company-profiles.update', $id), [
            'pfx_file' => $this->certificate(),
            'pfx_password' => SyntheticCompanyPkcs12::PASSWORD,
        ])->assertStatus(409);
        $this->delete(route('nfse.settings.company-profiles.destroy', $id))
            ->assertStatus(409);
        self::assertSame($currentRef, NfseCompanyProfile::query()->where('company_id', $id)->value('certificate_ref'));
        self::assertSame('active', NfseCompanyProfile::query()->where('company_id', $id)->value('status'));

        $receipt->update(['status' => 'emitted']);
        $this->delete(route('nfse.settings.company-profiles.destroy', $id))
            ->assertNoContent();
    }

    public function testUnrecognizedFiscalOrSensitiveInputsAreRejected(): void
    {
        $id = (int) $this->company->id;
        $this->post(route('nfse.settings.company-profiles.store'), array_merge($this->creation($id), [
            'fiscal_parameters' => ['webdav_password' => 'synthetic-credential-must-not-be-persisted'],
        ]))->assertUnprocessable();
        self::assertSame(0, NfseCompanyProfile::query()->count());

        $this->post(route('nfse.settings.company-profiles.store'), $this->creation($id))
            ->assertCreated();
        $this->patch(route('nfse.settings.company-profiles.update', $id), [
            'company_id' => $id + 1,
            'fiscal_parameters' => ['emission_policy' => 'manual'],
        ])->assertUnprocessable();
    }

    public function testReadOnlyUserMayListButCannotModifyCompanyProfiles(): void
    {
        $id = (int) $this->company->id;
        $role = $this->createRole('nfse-company-profile-readonly');
        $this->attachPermission($role, 'read-admin-panel');
        $this->attachPermission($role, 'read-nfse-settings');
        $reader = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$id],
            'roles' => [$role->id],
        ])));
        $this->loginAs($reader);

        $this->get(route('nfse.settings.company-profiles.index'))->assertOk();
        $this->post(route('nfse.settings.company-profiles.store'), $this->creation($id))
            ->assertForbidden();
        $this->delete(route('nfse.settings.company-profiles.destroy', $id))
            ->assertForbidden();
        $this->post(route('nfse.settings.company-profiles.cleanup', $id))
            ->assertForbidden();
        self::assertSame(0, NfseCompanyProfile::query()->count());
    }

    /** @return array<string,mixed> */
    private function creation(int $companyId): array
    {
        return [
            'company_id' => $companyId,
            'pfx_file' => $this->certificate(),
            'pfx_password' => SyntheticCompanyPkcs12::PASSWORD,
            'fiscal_parameters' => ['municipio_ibge' => '3304557', 'sandbox_mode' => '1'],
        ];
    }

    private function certificate(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('synthetic.pfx', SyntheticCompanyPkcs12::bytes(self::CNPJ));
    }

    private function secondCompany(bool $assigned): int
    {
        $id = (int) DB::table('companies')->insertGetId([
            'domain' => 'synthetic-nfse-secondary.example.test',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('settings')->insert([
            'company_id' => $id,
            'key' => 'company.tax_number',
            'value' => self::CNPJ,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($assigned) {
            DB::table('user_companies')->insert(['user_id' => (int) auth()->id(), 'company_id' => $id]);
        }

        return $id;
    }
}

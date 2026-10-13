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
use Modules\Nfse\Support\NfseCompanyProfileManager;
use Modules\Nfse\Support\VaultDiagnostics;
use Modules\Nfse\Tests\Support\CompanyProfileFakeSecrets;
use Modules\Nfse\Tests\Support\CompanyProfileFakeVaultTransport;
use Modules\Nfse\Tests\Support\SyntheticCompanyPkcs12;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Tests\Feature\FeatureTestCase;

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
        // Surface the original exception rather than hiding a server failure
        // behind a generic 500 response during the core create contract.
        $this->withoutExceptionHandling();
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

    public function testCleanupCannotOvertakeInFlightCertificateCreation(): void
    {
        $id = (int) $this->company->id;
        $attempts = 0;
        $this->secrets->beforePut = static function () use ($id, &$attempts): void {
            ++$attempts;

            try {
                app(NfseCompanyProfileManager::class)->cleanup($id);
            } catch (\DomainException $error) {
                self::assertSame(
                    'Another NFS-e profile operation is in progress for this company.',
                    $error->getMessage(),
                );

                return;
            }

            self::fail('Concurrent cleanup removed a profile while its PFX was still being persisted.');
        };

        $this->post(route('nfse.settings.company-profiles.store'), $this->creation($id))
            ->assertCreated();
        self::assertSame(1, $attempts);

        $profile = NfseCompanyProfile::query()->where('company_id', $id)->firstOrFail();
        self::assertSame('active', $profile->status);
        self::assertFileExists((string) $profile->certificateFilePath());
        self::assertCount(1, $this->secrets->data);
        self::assertArrayHasKey((string) $profile->certificateVaultPath(), $this->secrets->data);
    }

    public function testDeletionCannotOvertakeInFlightCertificateRotation(): void
    {
        $id = (int) $this->company->id;
        $this->post(route('nfse.settings.company-profiles.store'), $this->creation($id))
            ->assertCreated();
        $originalRef = (string) NfseCompanyProfile::query()->where('company_id', $id)->value('certificate_ref');

        $attempts = 0;
        $this->secrets->beforePut = static function () use ($id, &$attempts): void {
            ++$attempts;

            try {
                app(NfseCompanyProfileManager::class)->delete($id);
            } catch (\DomainException $error) {
                self::assertSame(
                    'Another NFS-e profile operation is in progress for this company.',
                    $error->getMessage(),
                );

                return;
            }

            self::fail('Concurrent deletion removed a profile while its new PFX was still being persisted.');
        };

        $this->patch(route('nfse.settings.company-profiles.update', $id), [
            'pfx_file' => $this->certificate(),
            'pfx_password' => SyntheticCompanyPkcs12::PASSWORD,
        ])->assertOk();

        self::assertSame(1, $attempts);
        $profile = NfseCompanyProfile::query()->where('company_id', $id)->firstOrFail();
        self::assertSame('active', $profile->status);
        self::assertNotSame($originalRef, $profile->certificate_ref);
        self::assertFileExists((string) $profile->certificateFilePath());
        self::assertCount(1, $this->secrets->data);
        self::assertArrayHasKey((string) $profile->certificateVaultPath(), $this->secrets->data);
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

        // The Akaunting Laravel validator must reject an orphaned password
        // and a certificate upload lacking its password, without server errors.
        $this->patch(route('nfse.settings.company-profiles.update', $id), [
            'pfx_password' => SyntheticCompanyPkcs12::PASSWORD,
        ])->assertUnprocessable();
        $this->patch(route('nfse.settings.company-profiles.update', $id), [
            'pfx_file' => $this->certificate(),
        ])->assertUnprocessable();

        self::assertSame(1, NfseCompanyProfile::query()->where('company_id', $id)->count());
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
        ]);

        if ($assigned) {
            DB::table('user_companies')->insert(['user_id' => (int) auth()->id(), 'company_id' => $id]);
        }

        return $id;
    }
}

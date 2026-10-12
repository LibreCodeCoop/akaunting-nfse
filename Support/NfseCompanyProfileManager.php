<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\NfseCompanyProfile;

/**
 * A database-only transaction cannot atomically commit Vault and the PFX file.
 * Every external operation is first referenced by a durable profile row.
 * Interrupted cleanup remains discoverable and blocks unsafe reuse.
 */
final class NfseCompanyProfileManager
{
    public function __construct(
        private readonly NfseCompanyCertificateStore $certificates,
        private readonly NfseCompanyProfilePendingOperations $pending,
        private readonly PfxInspector $inspector = new PfxInspector(),
    ) {
    }

    /**
     * @param array<string,mixed> $fiscal
     */
    public function create(int $companyId, string $pfx, string $password, array $fiscal): NfseCompanyProfile
    {
        $details = $this->validatedCertificate($companyId, $pfx, $password);
        $ref = NfseCompanyCertificatePath::newReference();
        $this->certificates->assertOperational($companyId, $ref);

        $profile = DB::transaction(function () use ($companyId, $ref, $fiscal): NfseCompanyProfile {
            // Locking the native company row serializes simultaneous registrations.
            $this->lockCompany($companyId);
            if (NfseCompanyProfile::query()->where('company_id', $companyId)->exists()) {
                throw new \DomainException('An NFS-e configuration already exists for this company.');
            }

            return NfseCompanyProfile::query()->create([
                'company_id' => $companyId,
                'status' => 'provisioning',
                'staged_certificate_ref' => $ref,
                'fiscal_parameters' => $fiscal,
            ]);
        });

        try {
            $this->certificates->put($companyId, $ref, $pfx, $password);
            $this->publish($profile, $ref, $details, $fiscal);
        } catch (\Throwable) {
            $this->compensate($companyId, $ref);
            throw new \DomainException('Certificate registration failed. Retry cleanup if the profile remains provisional.');
        }

        return $profile->refresh();
    }

    /**
     * @param array<string,mixed> $fiscal
     */
    public function update(
        int $companyId,
        array $fiscal,
        ?string $pfx = null,
        ?string $password = null,
    ): NfseCompanyProfile {
        if ($pfx === null) {
            return DB::transaction(function () use ($companyId, $fiscal): NfseCompanyProfile {
                $profile = $this->lockedProfile($companyId);
                $this->assertEditable($profile, $companyId);
                $profile->update(['fiscal_parameters' => array_replace($profile->fiscal_parameters ?? [], $fiscal)]);

                return $profile->refresh();
            });
        }

        if ($password === null) {
            throw new \InvalidArgumentException('PFX password is required.');
        }

        $details = $this->validatedCertificate($companyId, $pfx, $password);
        $ref = NfseCompanyCertificatePath::newReference();
        $this->certificates->assertOperational($companyId, $ref);

        $profile = DB::transaction(function () use ($companyId, $ref): NfseCompanyProfile {
            $profile = $this->lockedProfile($companyId);
            $this->assertEditable($profile, $companyId);
            $profile->update(['staged_certificate_ref' => $ref]);

            return $profile;
        });

        try {
            $this->certificates->put($companyId, $ref, $pfx, $password);
            DB::transaction(function () use ($companyId, $ref, $details, $fiscal): void {
                $locked = $this->lockedProfile($companyId);
                if ($locked->status !== 'active' || $locked->staged_certificate_ref !== $ref) {
                    throw new \DomainException('Certificate rotation has been interrupted.');
                }

                $previous = $locked->certificate_ref;
                $this->pending->assertNone($companyId);
                $this->applyCertificate($locked, $ref, $details, array_replace($locked->fiscal_parameters ?? [], $fiscal));

                if (is_string($previous) && $previous !== '') {
                    DB::table('nfse_company_certificate_retirements')->insertOrIgnore([
                        'company_id' => $companyId, 'certificate_ref' => $previous,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
        } catch (\Throwable) {
            $this->compensate($companyId, $ref);
            throw new \DomainException('Certificate rotation failed. The previous certificate remains configured.');
        }

        // The active certificate is committed before retiring the old revision.
        // A failed cleanup stays in the retirement table for an explicit retry.
        try {
            $this->retireOldCertificates($companyId);
        } catch (\Throwable) {
            // Do not invalidate the successfully committed new certificate.
        }

        return $profile->refresh();
    }

    public function delete(int $companyId): void
    {
        DB::transaction(function () use ($companyId): void {
            $profile = $this->lockedProfile($companyId);
            $this->pending->assertNone($companyId);
            $profile->update(['status' => 'deleting']);
        });

        $this->finishDeletion($companyId);
    }

    /**
     * Explicit recovery for interrupted create, rotation and retirement.
     * A deleting profile is removed only after every secret is erased.
     */
    public function cleanup(int $companyId): void
    {
        $profile = NfseCompanyProfile::query()->where('company_id', $companyId)->firstOrFail();
        if (in_array($profile->status, ['provisioning', 'deleting'], true)) {
            $this->delete($companyId);

            return;
        }

        if ($profile->status !== 'active') {
            throw new \DomainException('Profile cleanup is unavailable in this state.');
        }

        $staged = $profile->staged_certificate_ref;
        if (is_string($staged) && $staged !== '') {
            $this->certificates->delete($companyId, $staged);
            DB::transaction(function () use ($companyId, $staged): void {
                $locked = $this->lockedProfile($companyId);
                if ($locked->status === 'active' && $locked->staged_certificate_ref === $staged) {
                    $locked->update(['staged_certificate_ref' => null]);
                }
            });
        }

        $this->retireOldCertificates($companyId);
    }

    /**
     * @param array<string,mixed> $details
     * @param array<string,mixed> $fiscal
     */
    private function publish(NfseCompanyProfile $profile, string $ref, array $details, array $fiscal): void
    {
        DB::transaction(function () use ($profile, $ref, $details, $fiscal): void {
            $locked = $this->lockedProfile((int) $profile->company_id);
            if ($locked->status !== 'provisioning' || $locked->staged_certificate_ref !== $ref) {
                throw new \DomainException('Profile creation has been interrupted.');
            }

            $this->applyCertificate($locked, $ref, $details, $fiscal);
        });
    }

    /** @param array<string,mixed> $details
     *  @param array<string,mixed> $fiscal
     */
    private function applyCertificate(NfseCompanyProfile $profile, string $ref, array $details, array $fiscal): void
    {
        $profile->update([
            'status' => 'active',
            'certificate_ref' => $ref,
            'staged_certificate_ref' => null,
            'certificate_cnpj' => $details['cnpj'],
            'certificate_fingerprint_sha256' => $details['fingerprint_sha256'],
            'certificate_valid_from' => $details['valid_from'],
            'certificate_valid_to' => $details['valid_to'],
            'fiscal_parameters' => $fiscal,
        ]);
    }

    private function compensate(int $companyId, string $ref): void
    {
        $pending = NfseCompanyProfile::query()->where('company_id', $companyId)
            ->where('staged_certificate_ref', $ref)->first();

        if (!$pending instanceof NfseCompanyProfile) {
            return;
        }

        try {
            $this->certificates->delete($companyId, $ref);
            DB::transaction(function () use ($companyId, $ref): void {
                $locked = $this->lockedProfile($companyId);
                if ($locked->staged_certificate_ref !== $ref) {
                    return;
                }
                if ($locked->status === 'provisioning') {
                    $locked->delete();

                    return;
                }
                if ($locked->status === 'active') {
                    $locked->update(['staged_certificate_ref' => null]);
                }
            });
        } catch (\Throwable) {
            // Keep the durable staged reference. Only explicit cleanup may retry.
        }
    }

    private function finishDeletion(int $companyId): void
    {
        $profile = NfseCompanyProfile::query()->where('company_id', $companyId)->firstOrFail();

        try {
            foreach (array_unique(array_filter([
                $profile->staged_certificate_ref, $profile->certificate_ref,
            ])) as $ref) {
                $this->certificates->delete($companyId, (string) $ref);
            }
            $this->retireOldCertificates($companyId);
        } catch (\Throwable) {
            throw new \DomainException('Profile deletion remains pending certificate cleanup.');
        }

        DB::transaction(function () use ($companyId): void {
            $locked = $this->lockedProfile($companyId);
            $this->pending->assertNone($companyId);
            if ($locked->status !== 'deleting') {
                throw new \DomainException('Profile deletion was interrupted.');
            }
            $locked->delete();
        });
    }

    private function retireOldCertificates(int $companyId): void
    {
        $refs = DB::table('nfse_company_certificate_retirements')
            ->where('company_id', $companyId)->orderBy('id')->get(['id', 'certificate_ref']);

        foreach ($refs as $retired) {
            $this->certificates->delete($companyId, (string) $retired->certificate_ref);
            DB::table('nfse_company_certificate_retirements')
                ->where('id', $retired->id)->where('company_id', $companyId)->delete();
        }
    }

    private function lockedProfile(int $companyId): NfseCompanyProfile
    {
        return NfseCompanyProfile::query()->where('company_id', $companyId)
            ->lockForUpdate()->firstOrFail();
    }

    private function assertEditable(NfseCompanyProfile $profile, int $companyId): void
    {
        if ($profile->status !== 'active' || $profile->staged_certificate_ref !== null) {
            throw new \DomainException('Profile is not ready for editing until certificate cleanup is complete.');
        }

        $this->pending->assertNone($companyId);
    }

    private function lockCompany(int $companyId): void
    {
        $exists = DB::table('companies')->where('id', $companyId)
            ->where('enabled', 1)->whereNull('deleted_at')->lockForUpdate()->first(['id']) !== null;

        if (!$exists) {
            throw new \DomainException('Akaunting company is unavailable.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function validatedCertificate(int $companyId, string $pfx, string $password): array
    {
        try {
            $details = $this->inspector->inspect($pfx, $password);
        } catch (\Throwable) {
            throw new \DomainException('PFX content, password or certificate is invalid.');
        }

        $taxNumber = DB::table('settings')->where('company_id', $companyId)
            ->where('key', 'company.tax_number')->value('value');
        $expected = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $taxNumber) ?? '');

        if (($details['is_currently_valid'] ?? false) !== true
            || !is_string($details['cnpj'] ?? null)
            || $details['cnpj'] === ''
            || $expected !== strtoupper($details['cnpj'])) {
            throw new \DomainException('PFX is not valid for this Akaunting company identity.');
        }

        return $details;
    }
}

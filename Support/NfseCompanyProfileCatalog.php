<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use App\Models\Auth\User;
use App\Models\Common\Company;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\NfseCompanyProfile;

/**
 * The native Akaunting company remains the only source of business ownership.
 * User-to-company membership and the settings permission are separate checks.
 */
final class NfseCompanyProfileCatalog
{
    public function assertAccessible(User $user, int $companyId): void
    {
        if ($companyId <= 0 || !DB::table('user_companies')
            ->join('companies', 'companies.id', '=', 'user_companies.company_id')
            ->where('user_companies.user_id', (int) $user->id)
            ->where('companies.id', $companyId)
            ->where('companies.enabled', 1)
            ->whereNull('companies.deleted_at')
            ->exists()) {
            abort(404);
        }
    }

    /**
     * Never enumerate companies not assigned to the authenticated user.
     *
     * @return array{profiles:list<array<string,mixed>>,eligible_companies:list<array{company_id:int,name:string}>}
     */
    public function forUser(User $user): array
    {
        $ids = DB::table('user_companies')
            ->join('companies', 'companies.id', '=', 'user_companies.company_id')
            ->where('user_companies.user_id', (int) $user->id)
            ->where('companies.enabled', 1)
            ->whereNull('companies.deleted_at')
            ->pluck('companies.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return ['profiles' => [], 'eligible_companies' => []];
        }

        $profiles = NfseCompanyProfile::query()->whereIn('company_id', $ids)->get();
        $linked = $profiles->pluck('company_id')->map(static fn ($id): int => (int) $id)->all();
        $companies = Company::query()->whereIn('id', $ids)->orderBy('id')->get();
        // Akaunting keeps each company's display name in its native settings table.
        // Restrict this lookup to the already authorized company IDs.
        $names = DB::table('settings')->whereIn('company_id', $ids)
            ->where('key', 'company.name')->pluck('value', 'company_id');
        $nameOf = static function (int $id) use ($names): string {
            $name = trim((string) ($names[$id] ?? ''));

            return $name !== '' ? $name : 'Empresa #' . $id;
        };

        $eligible = $companies
            ->filter(static fn (Company $company): bool => !in_array((int) $company->id, $linked, true))
            ->map(static fn (Company $company): array => [
                'company_id' => (int) $company->id,
                'name' => $nameOf((int) $company->id),
            ])->values()->all();

        return [
            'profiles' => $profiles->map(fn (NfseCompanyProfile $profile): array => array_merge(
                $this->publicData($profile),
                ['name' => $nameOf((int) $profile->company_id)],
            ))->all(),
            'eligible_companies' => $eligible,
        ];
    }

    /**
     * Read-only administrative shape. Keep persistence queries outside the
     * Eloquent model and do not expose private certificate paths.
     *
     * @return array<string,mixed>
     */
    public function publicData(NfseCompanyProfile $profile): array
    {
        $pendingRetirements = DB::table('nfse_company_certificate_retirements')
            ->where('company_id', (int) $profile->company_id)
            ->exists();

        return array_merge($profile->publicData(), [
            'cleanup_pending' => $profile->staged_certificate_ref !== null || $pendingRetirements,
            'fiscal_readiness' => (new NfseCompanyFiscalReadiness())->evaluate($profile->publicData()),
        ]);
    }

    public function requireActive(int $companyId): NfseCompanyProfile
    {
        $profile = NfseCompanyProfile::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->first();

        if (!$profile instanceof NfseCompanyProfile
            || $profile->certificateVaultPath() === null
            || $profile->certificateFilePath() === null) {
            throw new \DomainException('NFS-e company profile is not configured.');
        }

        return $profile;
    }
}

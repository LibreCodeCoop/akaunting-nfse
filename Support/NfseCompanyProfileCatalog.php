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

        $eligible = $companies
            ->filter(static fn (Company $company): bool => !in_array((int) $company->id, $linked, true))
            ->map(static fn (Company $company): array => [
                'company_id' => (int) $company->id,
                'name' => (string) $company->name,
            ])->values()->all();

        return [
            'profiles' => $profiles->map(static fn (NfseCompanyProfile $profile): array => $profile->publicData())->all(),
            'eligible_companies' => $eligible,
        ];
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

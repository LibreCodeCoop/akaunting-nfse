<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Support\Facades\DB;

/** Tenant-scoped fiscal profile, independent from core Akaunting contacts. */
final class ContactFiscalProfileStore
{
    /** @return array{municipal_registration:string,legal_name:string} */
    public function forContact(?object $contact): array
    {
        $companyId = is_object($contact) && is_numeric($contact->company_id ?? null)
            ? (int) $contact->company_id : 0;
        $contactId = is_object($contact) && is_numeric($contact->id ?? null)
            ? (int) $contact->id : 0;
        if ($companyId <= 0 || $contactId <= 0) {
            return ['municipal_registration' => '', 'legal_name' => ''];
        }
        $profile = DB::table('nfse_contact_fiscal_profiles')
            ->where('company_id', $companyId)
            ->where('contact_id', $contactId)
            ->first(['municipal_registration', 'legal_name']);

        return [
            'municipal_registration' => trim((string) ($profile->municipal_registration ?? '')),
            'legal_name' => trim((string) ($profile->legal_name ?? '')),
        ];
    }

    /** @param array{municipal_registration:string,legal_name:string} $values */
    public function persist(int $companyId, int $contactId, array $values): void
    {
        $query = DB::table('nfse_contact_fiscal_profiles')
            ->where('company_id', $companyId)
            ->where('contact_id', $contactId);
        if ($values['municipal_registration'] === '' && $values['legal_name'] === '') {
            $query->delete();
            return;
        }

        DB::table('nfse_contact_fiscal_profiles')->updateOrInsert(
            ['company_id' => $companyId, 'contact_id' => $contactId],
            [
                'municipal_registration' => $values['municipal_registration'] ?: null,
                'legal_name' => $values['legal_name'] ?: null,
                'updated_at' => now(),
            ],
        );
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Nfse\Models\NfseCompanyProfile;
use Modules\Nfse\Support\NfseCompanyIssuerPayload;

/**
 * A read-only assessment of the single-schema DPS authority for the current
 * Akaunting company. This cannot attest the external SEFIN high-water mark.
 */
final class DpsOperationalReadiness
{
    /** @return array<string,mixed> */
    public function inspect(int $companyId): array
    {
        $result = [
            'company_id' => $companyId,
            'profile_active' => false,
            'environment' => null,
            'series' => null,
            'authority_present' => false,
            'local_present' => false,
            'authority_status' => null,
            'local_status' => null,
            'member_status' => null,
            'installation_configured' => false,
            'generation_matches' => false,
            'reference_matches' => false,
            'attested_floor' => null,
            'last_number' => null,
            'candidate_number' => null,
            'evidence_recorded' => false,
            'mechanically_ready' => false,
            'external_reconciliation' => 'not_proven_by_this_endpoint',
            'reasons' => [],
        ];

        $profile = NfseCompanyProfile::query()->where('company_id', $companyId)
            ->where('status', 'active')->first();
        if (!$profile instanceof NfseCompanyProfile) {
            $result['reasons'] = ['profile_missing_or_inactive'];
            return $result;
        }

        $fiscal = $profile->fiscal_parameters ?? [];
        try {
            $issuer = new DpsFiscalIssuer(
                (string) ($fiscal['municipio_ibge'] ?? ''),
                2,
                (string) ($profile->certificate_cnpj ?? ''),
            );
            $series = DpsIssuanceIdentity::normalizeSeries($fiscal['dps_series'] ?? null);
        } catch (\InvalidArgumentException|\LogicException) {
            $result['reasons'] = ['fiscal_identity_or_series_invalid'];
            return $result;
        }

        $environment = (new NfseCompanyIssuerPayload())->sandboxMode($fiscal) ? 2 : 1;
        $result['profile_active'] = true;
        $result['environment'] = $environment;
        $result['series'] = $series;

        if (DB::connection()->getDriverName() !== 'mysql'
            || !Schema::hasTable('nfse_dps_series')
            || !Schema::hasTable('nfse_dps_coordination_series')
            || !Schema::hasTable('nfse_dps_coordination_members')
            || !Schema::hasTable('nfse_dps_coordination_allocations')) {
            $result['reasons'] = ['single_mysql_authority_unavailable'];
            return $result;
        }

        $identity = [
            'environment' => $environment,
            'issuer_municipality' => $issuer->municipality,
            'issuer_registration_type' => $issuer->registrationType,
            'issuer_registration' => $issuer->registration,
            'series' => $series,
        ];
        $local = DB::table('nfse_dps_series')->where($identity)->first();
        $authority = DB::table('nfse_dps_coordination_series')->where($identity)->first();
        $result['local_present'] = is_object($local);
        $result['authority_present'] = is_object($authority);
        if (!is_object($authority) || !is_object($local)) {
            $result['reasons'] = ['matching_series_not_provisioned'];
            return $result;
        }

        $result['authority_status'] = (string) $authority->status;
        $result['local_status'] = (string) $local->status;
        $result['attested_floor'] = (string) $authority->attested_floor;
        $result['last_number'] = (string) $authority->last_number;
        $result['evidence_recorded'] =
            preg_match('/^[a-f0-9]{64}$/D', (string) $authority->evidence_sha256) === 1
            && (string) $authority->evidence_sha256 !== str_repeat('0', 64);
        if ((int) $authority->last_number < 999999999999999) {
            $result['candidate_number'] = (string) ((int) $authority->last_number + 1);
        }

        $result['reference_matches'] = (string) $local->coordination_reference !== ''
            && hash_equals(
                (string) $authority->coordination_reference,
                (string) $local->coordination_reference,
            );
        $reasons = [];
        if ($authority->status !== 'active' || $local->status !== 'active') {
            $reasons[] = 'series_inactive';
        }
        if (!$result['reference_matches']
            || (string) $authority->last_number !== (string) $local->last_number
            || (int) $authority->attested_floor > (int) $authority->last_number) {
            $reasons[] = 'authority_local_mismatch';
        }
        if (!$result['evidence_recorded']) {
            $reasons[] = 'attestation_record_missing';
        }

        $installation = getenv('NFSE_DPS_INSTALLATION_ID');
        $generation = getenv('NFSE_DPS_INSTALLATION_GENERATION');
        $result['installation_configured'] = is_string($installation)
            && preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $installation) === 1
            && is_string($generation) && preg_match('/^[1-9][0-9]*$/D', $generation) === 1;
        if (!$result['installation_configured']) {
            $reasons[] = 'installation_configuration_missing';
        } else {
            $member = DB::table('nfse_dps_coordination_members')
                ->where('series_id', (int) $authority->id)
                ->where('installation_id', $installation)->first();
            $result['member_status'] = is_object($member) ? (string) $member->status : null;
            $result['generation_matches'] = is_object($member)
                && (string) $member->generation === (string) $generation;
            if (!is_object($member) || $member->status !== 'active'
                || !$result['generation_matches']) {
                $reasons[] = 'member_inactive_or_generation_mismatch';
            }
        }

        $result['reasons'] = $reasons;
        $result['mechanically_ready'] = $reasons === [];
        return $result;
    }
}

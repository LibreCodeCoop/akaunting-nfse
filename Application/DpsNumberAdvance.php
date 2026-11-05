<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Nfse\Models\NfseCompanyProfile;
use Modules\Nfse\Support\NfseCompanyIssuerPayload;

/**
 * Exceptional monotonic DPS high-water adjustment; it never allocates or posts
 * a DPS. The local view and authoritative ledger use ONE MySQL transaction.
 */
final class DpsNumberAdvance
{
    private const MAX_NUMBER = 999999999999999;

    /** @return array{series:string,previous_next:string,next_number:string} */
    public function advance(
        int $companyId,
        int $actorId,
        string $expectedNext,
        string $requestedNext,
        string $reason,
    ): array {
        $reason = trim($reason);
        if ($companyId < 1 || $actorId < 1
            || preg_match('/^[1-9][0-9]{0,14}$/D', $expectedNext) !== 1
            || preg_match('/^[1-9][0-9]{0,14}$/D', $requestedNext) !== 1
            || mb_strlen($reason) < 12 || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('Invalid DPS adjustment request.');
        }

        if (DB::connection()->getDriverName() !== 'mysql'
            || !Schema::hasTable('nfse_dps_number_adjustments')
            || !Schema::hasTable('nfse_dps_coordination_series')
            || !Schema::hasTable('nfse_dps_series')) {
            throw new \LogicException('DPS adjustment requires the provisioned Akaunting MySQL authority.');
        }

        return DB::transaction(function () use (
            $companyId, $actorId, $expectedNext, $requestedNext, $reason,
        ): array {
            // Lock the selected company's fiscal identity before changing the
            // shared authority. The route owns tenant and permission checks.
            $profile = NfseCompanyProfile::query()->where('company_id', $companyId)
                ->where('status', 'active')->lockForUpdate()->first();
            if (!$profile instanceof NfseCompanyProfile) {
                throw new \LogicException('An active company fiscal profile is required.');
            }

            $fiscal = $profile->fiscal_parameters ?? [];
            $issuer = new DpsFiscalIssuer(
                (string) ($fiscal['municipio_ibge'] ?? ''),
                2,
                (string) ($profile->certificate_cnpj ?? ''),
            );
            $series = DpsIssuanceIdentity::normalizeSeries($fiscal['dps_series'] ?? null);
            $identity = [
                'environment' => (new NfseCompanyIssuerPayload())->sandboxMode($fiscal) ? 2 : 1,
                'issuer_municipality' => $issuer->municipality,
                'issuer_registration_type' => $issuer->registrationType,
                'issuer_registration' => $issuer->registration,
                'series' => $series,
            ];

            // Same locking order as reservation: local series, then authority.
            // All changes (both counters + audit) commit or roll back together.
            $local = DB::table('nfse_dps_series')->where($identity)->lockForUpdate()->first();
            $authority = DB::table('nfse_dps_coordination_series')->where($identity)->lockForUpdate()->first();
            if (!is_object($local) || !is_object($authority)
                || $local->coordination_mode !== 'shared_database'
                || !in_array($local->status, ['active', 'inactive'], true)
                || $local->status !== $authority->status
                || !is_string($local->coordination_reference)
                || preg_match('/^[a-f0-9]{64}$/D', $local->coordination_reference) !== 1
                || !hash_equals((string) $authority->coordination_reference, $local->coordination_reference)
                || preg_match('/^[a-f0-9]{64}$/D', (string) $authority->evidence_sha256) !== 1
                || (string) $authority->evidence_sha256 === str_repeat('0', 64)
                || (int) $authority->attested_floor > (int) $authority->last_number
                || (string) $local->last_number !== (string) $authority->last_number) {
                throw new \LogicException('Fiscal series is not consistently provisioned and attested.');
            }

            $current = (int) $authority->last_number;
            if ($current >= self::MAX_NUMBER) {
                throw new \LogicException('DPS sequence is exhausted.');
            }
            $actualNext = (string) ($current + 1);
            if ($actualNext !== $expectedNext) {
                throw new \LogicException('DPS sequence changed; refresh the displayed number.');
            }
            $requested = (int) $requestedNext;
            if ($requested <= $current + 1 || $requested > self::MAX_NUMBER) {
                throw new \LogicException('New DPS number must exceed the current next number.');
            }

            // A corrupted high-water mark must be investigated, not silently
            // repaired by an administrative jump.
            $maxLocal = (int) DB::table('nfse_dps_reservations')
                ->where('series_id', $local->id)->max('number');
            $maxGlobal = (int) DB::table('nfse_dps_coordination_allocations')
                ->where('series_id', $authority->id)->max('number');
            if ($maxLocal > $current || $maxGlobal > $current) {
                throw new \LogicException('DPS ledger exceeds its high-water; reconcile before adjustment.');
            }

            $newLast = $requested - 1;
            DB::table('nfse_dps_coordination_series')->where('id', $authority->id)
                ->update(['last_number' => $newLast]);
            DB::table('nfse_dps_series')->where('id', $local->id)
                ->update(['last_number' => $newLast, 'updated_at' => Carbon::now()]);
            DB::table('nfse_dps_number_adjustments')->insert([
                'series_id' => (int) $authority->id,
                'company_id' => $companyId,
                'actor_id' => $actorId,
                'previous_last_number' => $current,
                'new_last_number' => $newLast,
                'reason' => $reason,
                'created_at' => Carbon::now(),
            ]);

            return [
                'series' => $series,
                'previous_next' => $actualNext,
                'next_number' => $requestedNext,
            ];
        }, 1);
    }
}

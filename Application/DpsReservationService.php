<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Opt-in local transactional DPS allocator. Not integrated into issuance.
 *
 * The caller must independently authorize the operation's company (#421).
 * A local database only coordinates installations using THIS same database.
 * Series administration/activation must prove ownership before it may create
 * an active series (#417); this service never activates one automatically.
 */
final class DpsReservationService
{
    private const MAX_DPS_NUMBER = 999999999999999;

    /**
     * @return array{reservation_id:int,company_id:int,invoice_id:int,series:string,number:string}
     */
    public function reserve(
        int $companyId,
        int $invoiceId,
        DpsFiscalIssuer $issuer,
        int $environment,
        string $series,
        string $operationKey,
        string $origin,
    ): array {
        if ($companyId <= 0 || $invoiceId <= 0) {
            throw new \InvalidArgumentException('A persisted fiscal invoice and owning company are required.');
        }

        if (!in_array($environment, [1, 2], true)) {
            throw new \InvalidArgumentException('Invalid DPS target environment.');
        }

        if (preg_match('/^[0-9]{1,5}$/D', $series) !== 1 || (int) $series === 0) {
            throw new \InvalidArgumentException('DPS series must contain one to five nonzero decimal digits.');
        }

        $series = str_pad((string) ((int) $series), 5, '0', STR_PAD_LEFT);

        if (preg_match('/^[a-f0-9]{64}$/D', $operationKey) !== 1) {
            throw new \InvalidArgumentException('A stable hashed fiscal operation key is required.');
        }

        // Origin is audit metadata, not part of the numbering policy or
        // idempotency key. Allow future scheduler/API callers explicitly.
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $origin) !== 1) {
            throw new \InvalidArgumentException('Invalid DPS emission origin.');
        }

        return DB::transaction(function () use (
            $companyId, $invoiceId, $issuer, $environment, $series, $operationKey, $origin,
        ): array {
            // The commercial invoice establishes tenant ownership, not the
            // nationally recognized issuer key. Do not use the HTTP session.
            $invoice = DB::table('documents')
                ->where('id', $invoiceId)
                ->where('type', 'invoice')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first(['id']);

            if (!is_object($invoice)) {
                throw new AuthorizationException('Fiscal operation does not belong to the specified company.');
            }

            $counterQuery = DB::table('nfse_dps_series')
                ->where('environment', $environment)
                ->where('issuer_municipality', $issuer->municipality)
                ->where('issuer_registration_type', $issuer->registrationType)
                ->where('issuer_registration', $issuer->registration)
                ->where('series', $series);

            // Existing reservations remain recoverable even when the series
            // was later suspended. Never change their immutable identity.
            $existing = DB::table('nfse_dps_reservations')
                ->where('operation_key', $operationKey)
                ->lockForUpdate()
                ->first();

            if (is_object($existing)) {
                $originalSeries = DB::table('nfse_dps_series')
                    ->where('id', $existing->series_id)
                    ->first();

                if ((int) $existing->company_id !== $companyId
                    || (int) $existing->invoice_id !== $invoiceId
                    || !is_object($originalSeries)
                    || (int) $originalSeries->environment !== $environment
                    || (string) $originalSeries->issuer_municipality !== $issuer->municipality
                    || (int) $originalSeries->issuer_registration_type !== $issuer->registrationType
                    || (string) $originalSeries->issuer_registration !== $issuer->registration
                    || (string) $originalSeries->series !== $series) {
                    throw new \LogicException('Fiscal operation key is bound to a different immutable DPS identity.');
                }

                return $this->result($existing, $companyId, $invoiceId, $series);
            }

            $counter = $counterQuery->lockForUpdate()->first();

            if (!is_object($counter)
                || (string) $counter->status !== 'active'
                || (string) $counter->coordination_mode !== 'shared_database'
                || trim((string) $counter->coordination_reference) === '') {
                throw new \LogicException('Fiscal series has no proven active local coordination; reserve denied.');
            }

            $current = (int) $counter->last_number;

            if ($current >= self::MAX_DPS_NUMBER) {
                throw new \OverflowException('Fiscal DPS series has exhausted its fifteen-digit sequence.');
            }

            $next = $current + 1;
            DB::table('nfse_dps_series')->where('id', $counter->id)->update([
                'last_number' => $next,
                'updated_at' => now(),
            ]);
            $reservationId = (int) DB::table('nfse_dps_reservations')->insertGetId([
                'series_id' => (int) $counter->id,
                'company_id' => $companyId,
                'invoice_id' => $invoiceId,
                'operation_key' => $operationKey,
                'origin' => $origin,
                'number' => $next,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'reservation_id' => $reservationId,
                'company_id' => $companyId,
                'invoice_id' => $invoiceId,
                'series' => $series,
                'number' => (string) $next,
            ];
        }, 3);
    }

    /**
     * @return array{reservation_id:int,company_id:int,invoice_id:int,series:string,number:string}
     */
    private function result(object $record, int $companyId, int $invoiceId, string $series): array
    {
        return [
            'reservation_id' => (int) $record->id,
            'company_id' => $companyId,
            'invoice_id' => $invoiceId,
            'series' => $series,
            'number' => (string) $record->number,
        ];
    }
}

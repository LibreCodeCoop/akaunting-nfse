<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support\Testing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Application\DpsFiscalIssuer;
use Modules\Nfse\Application\DpsSeriesCoordinator;

/**
 * Single-database synthetic coordination for disposable browser/HTTP fixtures.
 *
 * This is NOT a multi-installation authority. Construction and every use
 * require the explicit testing harness, a testing application and SQLite.
 * Real deployments always use the shared MySQL implementation.
 */
final class SyntheticDpsSeriesCoordinator implements DpsSeriesCoordinator
{
    public function __construct()
    {
        $this->assertTestDatabase();
    }

    public static function reference(): string
    {
        return hash('sha256', 'nfse-disposable-sqlite-coordinator-only');
    }

    public function allocate(
        DpsFiscalIssuer $issuer,
        int $environment,
        string $series,
        string $reference,
        string $operationKey,
    ): array {
        $this->assertTestDatabase();
        $seriesRow = $this->series($issuer, $environment, $series, $reference);
        $already = DB::table('nfse_dps_reservations')
            ->where('operation_key', $operationKey)
            ->first();

        if ($already !== null) {
            return ['number' => (int) $already->number, 'fresh' => false];
        }

        // The real reservation service holds this local series row locked in
        // its transaction. This synthetic implementation shares that single
        // transaction, without claiming any external coordination guarantees.
        return ['number' => ((int) $seriesRow->last_number) + 1, 'fresh' => true];
    }

    public function assertExisting(
        DpsFiscalIssuer $issuer,
        int $environment,
        string $series,
        string $reference,
        string $operationKey,
        int $number,
    ): void {
        $this->assertTestDatabase();
        $seriesRow = $this->series($issuer, $environment, $series, $reference);
        $record = DB::table('nfse_dps_reservations')
            ->where('series_id', (int) $seriesRow->id)
            ->where('operation_key', $operationKey)
            ->where('number', $number)
            ->first();
        if ($record === null) {
            throw new \LogicException('Synthetic DPS allocation does not match its persisted operation.');
        }
    }

    public function claimFirstTransmission(
        DpsFiscalIssuer $issuer,
        int $environment,
        string $series,
        string $reference,
        string $operationKey,
        int $number,
        string $payloadFingerprint,
    ): void {
        $this->assertExisting($issuer, $environment, $series, $reference, $operationKey, $number);
        if (preg_match('/^[a-f0-9]{64}$/D', $payloadFingerprint) !== 1) {
            throw new \InvalidArgumentException('Invalid synthetic DPS fingerprint.');
        }

        try {
            DB::table('nfse_test_dps_first_posts')->insert([
                'operation_key' => $operationKey,
                'payload_fingerprint' => $payloadFingerprint,
                'created_at' => now(),
            ]);
        } catch (QueryException $error) {
            throw new \LogicException('Synthetic DPS first POST was already claimed.', previous: $error);
        }
    }

    private function series(DpsFiscalIssuer $issuer, int $environment, string $series, string $reference): object
    {
        if (!hash_equals(self::reference(), $reference)) {
            throw new \LogicException('Synthetic DPS reference is not approved.');
        }

        $row = DB::table('nfse_dps_series')
            ->where('environment', $environment)
            ->where('issuer_municipality', $issuer->municipality)
            ->where('issuer_registration_type', $issuer->registrationType)
            ->where('issuer_registration', $issuer->registration)
            ->where('series', $series)
            ->where('status', 'active')
            ->where('coordination_mode', 'shared_database')
            ->where('coordination_reference', $reference)
            ->first();

        if (!is_object($row)) {
            throw new \LogicException('Synthetic fiscal series is not provisioned.');
        }

        return $row;
    }

    private function assertTestDatabase(): void
    {
        if (!FiscalTestHarnessConfig::enabled((string) app()->environment(), getenv('NFSE_TEST_HARNESS'))
            || !app()->environment('testing')
            || DB::connection()->getDriverName() !== 'sqlite') {
            throw new \LogicException('Synthetic DPS coordination requires an isolated SQLite testing harness.');
        }
    }
}

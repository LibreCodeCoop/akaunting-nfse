<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use PDO;

/**
 * Direct MySQL coordinator, NOT another local DPS sequencer.
 *
 * The PDO connection must address a single independent, non-rollbackable
 * primary store shared by all installations using a given fiscal series.
 * Schema and provisioning are intentionally separate from Akaunting migrations.
 *
 * No configuration/connection is registered by default. The activation and
 * first-POST preflight remain blocked until external-issuer exclusivity,
 * historic number floor and the final emission gate have been demonstrated.
 */
final class SharedMysqlDpsSeriesCoordinator implements DpsSeriesCoordinator
{
    private const MAX_DPS_NUMBER = 999999999999999;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $installationId,
        private readonly int $generation,
    ) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \InvalidArgumentException('DPS coordinator requires an authoritative MySQL connection.');
        }

        if (preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $installationId) !== 1
            || $generation < 1) {
            throw new \InvalidArgumentException('A stable installation UUID and positive fencing generation are required.');
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function allocate(
        DpsFiscalIssuer $issuer,
        int $environment,
        string $series,
        string $reference,
        string $operationKey,
    ): array {
        $this->validate($environment, $series, $reference, $operationKey);

        return $this->transaction(function () use ($issuer, $environment, $series, $reference, $operationKey): array {
            $authority = $this->lockAuthorizedSeries($issuer, $environment, $series, $reference);
            $existing = $this->findOperation($operationKey);

            if ($existing !== null) {
                if ((int) $existing['series_id'] !== (int) $authority['id']) {
                    throw new \LogicException('Fiscal operation is already bound to another authoritative DPS series.');
                }

                return ['number' => (int) $existing['number'], 'fresh' => false];
            }

            $current = (int) $authority['last_number'];
            if ($current >= self::MAX_DPS_NUMBER) {
                throw new \OverflowException('Global DPS series exhausted its fifteen-digit sequence.');
            }

            $next = $current + 1;
            $this->execute(
                'UPDATE nfse_dps_coordination_series SET last_number = :number WHERE id = :id',
                ['number' => $next, 'id' => (int) $authority['id']],
            );
            $this->execute(
                'INSERT INTO nfse_dps_coordination_allocations '
                . '(series_id, installation_id, operation_key, number) '
                . 'VALUES (:series_id, :installation_id, :operation_key, :number)',
                [
                    'series_id' => (int) $authority['id'],
                    'installation_id' => $this->installationId,
                    'operation_key' => $operationKey,
                    'number' => $next,
                ],
            );

            // The central ledger commits before any local reservation. If the
            // local commit subsequently fails, never reclaim this number.
            return ['number' => $next, 'fresh' => true];
        });
    }

    public function assertExisting(
        DpsFiscalIssuer $issuer,
        int $environment,
        string $series,
        string $reference,
        string $operationKey,
        int $number,
    ): void {
        $this->validate($environment, $series, $reference, $operationKey);
        if ($number < 1 || $number > self::MAX_DPS_NUMBER) {
            throw new \LogicException('Invalid persisted DPS number.');
        }

        $this->transaction(function () use ($issuer, $environment, $series, $reference, $operationKey, $number): void {
            $authority = $this->lockAuthorizedSeries($issuer, $environment, $series, $reference);
            $existing = $this->findOperation($operationKey);

            if ($existing === null
                || (int) $existing['series_id'] !== (int) $authority['id']
                || (int) $existing['number'] !== $number) {
                throw new \LogicException('Local DPS reservation has no matching authoritative allocation.');
            }
        });
    }

    /** @return array<string,mixed> */
    private function lockAuthorizedSeries(
        DpsFiscalIssuer $issuer,
        int $environment,
        string $series,
        string $reference,
    ): array {
        // Lock the authoritative fiscal key, not a local coordination label.
        $row = $this->queryOne(
            'SELECT id, status, coordination_reference, attested_floor, '
            . 'evidence_sha256, last_number FROM nfse_dps_coordination_series '
            . 'WHERE environment = :environment AND issuer_municipality = :municipality '
            . 'AND issuer_registration_type = :registration_type '
            . 'AND issuer_registration = :registration AND series = :series FOR UPDATE',
            [
                'environment' => $environment,
                'municipality' => $issuer->municipality,
                'registration_type' => $issuer->registrationType,
                'registration' => $issuer->registration,
                'series' => $series,
            ],
        );

        if ($row === null
            || (string) $row['status'] !== 'active'
            || !hash_equals((string) $row['coordination_reference'], $reference)
            || preg_match('/^[a-f0-9]{64}$/D', (string) $row['evidence_sha256']) !== 1
            || (string) $row['evidence_sha256'] === str_repeat('0', 64)
            || (int) $row['attested_floor'] < 0
            || (int) $row['last_number'] < (int) $row['attested_floor']
            || (int) $row['last_number'] > self::MAX_DPS_NUMBER) {
            throw new \LogicException('Fiscal series has no valid active central authority or starting-number evidence.');
        }

        // This lock serializes generation revocation with number reservation.
        // A stale process cannot allocate after revocation commits.
        $member = $this->queryOne(
            'SELECT generation, status FROM nfse_dps_coordination_members '
            . 'WHERE series_id = :series_id AND installation_id = :installation_id FOR UPDATE',
            ['series_id' => (int) $row['id'], 'installation_id' => $this->installationId],
        );

        if ($member === null || (string) $member['status'] !== 'active'
            || (int) $member['generation'] !== $this->generation) {
            throw new \LogicException('DPS installation authority is missing or fenced by a newer generation.');
        }

        return $row;
    }

    /** @return array{series_id:int|string,number:int|string}|null */
    private function findOperation(string $operationKey): ?array
    {
        return $this->queryOne(
            'SELECT series_id, number FROM nfse_dps_coordination_allocations '
            . 'WHERE installation_id = :installation_id AND operation_key = :operation_key FOR UPDATE',
            ['installation_id' => $this->installationId, 'operation_key' => $operationKey],
        );
    }

    private function validate(int $environment, string $series, string $reference, string $operationKey): void
    {
        if (!in_array($environment, [1, 2], true)
            || preg_match('/^[0-9]{5}$/D', $series) !== 1
            || $series === '00000'
            || preg_match('/^[a-f0-9]{64}$/D', $reference) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $operationKey) !== 1) {
            throw new \InvalidArgumentException('Invalid authoritative DPS coordination scope or operation key.');
        }
    }

    /** @param array<string,int|string> $parameters
     *  @return array<string,mixed>|null
     */
    private function queryOne(string $sql, array $parameters): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string,int|string> $parameters */
    private function execute(string $sql, array $parameters): void
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
    }

    /** @template T
     *  @param callable():T $callback
     *  @return T
     */
    private function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('DPS coordination must own its central transaction boundary.');
        }

        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}

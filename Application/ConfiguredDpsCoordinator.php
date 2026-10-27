<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use PDO;
use PDOException;

/**
 * Opt-in authority. An absent or unreachable shared primary is NEVER replaced
 * by a local allocator. Secrets must be provisioned outside version control.
 */
final class ConfiguredDpsCoordinator
{
    public function connect(): DpsSeriesCoordinator
    {
        $dsn = trim((string) getenv('NFSE_DPS_COORDINATOR_DSN'));
        $user = (string) getenv('NFSE_DPS_COORDINATOR_USER');
        $password = getenv('NFSE_DPS_COORDINATOR_PASSWORD');
        $installation = trim((string) getenv('NFSE_DPS_INSTALLATION_ID'));
        $generation = (string) getenv('NFSE_DPS_INSTALLATION_GENERATION');

        if (!str_starts_with($dsn, 'mysql:') || $user === ''
            || $password === false || $installation === ''
            || preg_match('/^[1-9][0-9]*$/D', $generation) !== 1) {
            throw new \LogicException('Shared DPS coordination is not configured; fiscal transmission is disabled.');
        }

        try {
            return new SharedMysqlDpsSeriesCoordinator(
                new PDO($dsn, $user, $password, [
                    PDO::ATTR_TIMEOUT => 3,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]),
                $installation,
                (int) $generation,
            );
        } catch (PDOException $exception) {
            throw new \LogicException(
                'Shared DPS coordination is unavailable; fiscal transmission is disabled.',
                previous: $exception,
            );
        }
    }
}

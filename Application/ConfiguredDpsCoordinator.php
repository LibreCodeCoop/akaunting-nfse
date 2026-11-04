<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * Use the SAME database as the selected Akaunting installation.
 *
 * An independent PDO connection is intentional: its allocation and first-POST
 * commits must survive a rollback of a separate invoice/journal transaction.
 * It does not require a second database, installation or MySQL server.
 */
final class ConfiguredDpsCoordinator
{
    public function connect(): DpsSeriesCoordinator
    {
        $connection = DB::connection();
        $config = $connection->getConfig();
        $installation = trim((string) getenv('NFSE_DPS_INSTALLATION_ID'));
        $generation = (string) getenv('NFSE_DPS_INSTALLATION_GENERATION');

        if ($connection->getDriverName() !== 'mysql' || !is_array($config)
            || $installation === '' || preg_match('/^[1-9][0-9]*$/D', $generation) !== 1
            || ($config['read'] ?? null) !== null || ($config['write'] ?? null) !== null
            || trim((string) ($config['prefix'] ?? '')) !== '') {
            throw new \LogicException('A single writable Akaunting MySQL primary and authorized DPS installation are required.');
        }

        $database = trim((string) ($config['database'] ?? ''));
        $configuredHost = $config['host'] ?? '';
        // Laravel may normalize DB_HOST into a one-element host list.
        // Multiple hosts are rejected: the authority must never connect
        // to a different writable primary than the Akaunting connection.
        if (is_array($configuredHost)) {
            if (count($configuredHost) !== 1 || !is_string(reset($configuredHost))) {
                throw new \LogicException('DPS coordination requires one configured MySQL primary.');
            }
            $configuredHost = reset($configuredHost);
        }
        $host = trim((string) $configuredHost);
        $socket = trim((string) ($config['unix_socket'] ?? ''));
        $port = (string) ($config['port'] ?? '3306');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        if (preg_match('/^[a-zA-Z0-9_-]+$/D', $database) !== 1
            || ($socket === '' && ($host === '' || preg_match('/^[a-zA-Z0-9._:-]+$/D', $host) !== 1
                || preg_match('/^[0-9]{1,5}$/D', $port) !== 1))
            || preg_match('/^[a-zA-Z0-9]+$/D', $charset) !== 1) {
            throw new \LogicException('The Akaunting database connection cannot safely initialize DPS coordination.');
        }

        // Dedicated least-privilege credentials are optional; they must point
        // to the exact same schema. Never accept a separately configured DSN.
        $overrideUser = getenv('NFSE_DPS_COORDINATOR_USER');
        $overridePassword = getenv('NFSE_DPS_COORDINATOR_PASSWORD');
        if (($overrideUser === false) !== ($overridePassword === false)) {
            throw new \LogicException('Both DPS authority credentials must be supplied together.');
        }
        $user = $overrideUser !== false ? $overrideUser : (string) ($config['username'] ?? '');
        $password = $overridePassword !== false ? $overridePassword : (string) ($config['password'] ?? '');
        if ($user === '') {
            throw new \LogicException('Akaunting DPS database credentials are unavailable.');
        }

        $dsn = $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $database, $charset)
            : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset);

        try {
            $options = array_replace(
                (array) ($config['options'] ?? []),
                [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $pdo = new PDO($dsn, $user, $password, $options);

            // Never use a schema with the same name on a different connection.
            if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
                throw new \LogicException('DPS authority does not use the Akaunting database schema.');
            }

            return new SharedMysqlDpsSeriesCoordinator($pdo, $installation, (int) $generation);
        } catch (PDOException $exception) {
            throw new \LogicException('Akaunting DPS authority is unavailable; fiscal transmission is disabled.', previous: $exception);
        }
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Integration\Migrations;

use Modules\Nfse\Application\DpsFiscalIssuer;
use Modules\Nfse\Application\SharedMysqlDpsSeriesCoordinator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

// The dedicated migration harness intentionally has no Akaunting autoloader.
require_once dirname(__DIR__, 3) . '/Application/DpsFiscalIssuer.php';
require_once dirname(__DIR__, 3) . '/Application/DpsSeriesCoordinator.php';
require_once dirname(__DIR__, 3) . '/Application/SharedMysqlDpsSeriesCoordinator.php';

/**
 * Actual independent MySQL databases/connections and one durable primary.
 * All identities and proofs are synthetic; NO SEFIN request is performed.
 */
final class SharedMysqlDpsSeriesCoordinatorTest extends TestCase
{
    private const INSTALL_A = '11111111-1111-4111-8111-111111111111';
    private const INSTALL_B = '22222222-2222-4222-8222-222222222222';
    private const REFERENCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private ?PDO $admin = null;
    private ?PDO $global = null;
    private ?PDO $peer = null;
    private string $globalDatabase = '';
    private string $peerDatabase = '';

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('NFSE_MIGRATION_TEST_DB');
        if ($database === false || !preg_match('/^[a-zA-Z0-9_]+_migration_tests$/D', $database)) {
            self::markTestSkipped('Use a dedicated *_migration_tests MySQL database.');
        }

        $this->globalDatabase = $database . '_dps_global';
        $this->peerDatabase = $database . '_dps_peer';
        $this->admin = $this->connect($database);

        foreach ([$this->globalDatabase, $this->peerDatabase] as $name) {
            $this->admin->exec('DROP DATABASE IF EXISTS `' . $name . '`');
            $this->admin->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4');
        }

        $this->global = $this->connect($this->globalDatabase);
        $this->peer = $this->connect($this->peerDatabase);

        $sql = file_get_contents(dirname(__DIR__, 3) . '/docs/sql/dps-coordination-schema.sql');
        self::assertIsString($sql);
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $this->global->exec($statement);
            }
        }
        $this->admin->exec('CREATE TABLE IF NOT EXISTS dps_local_fixture '
            . '(operation_key CHAR(64) PRIMARY KEY, number BIGINT NOT NULL) ENGINE=InnoDB');
        $this->admin->exec('TRUNCATE TABLE dps_local_fixture');
        $this->peer->exec('CREATE TABLE dps_local_fixture '
            . '(operation_key CHAR(64) PRIMARY KEY, number BIGINT NOT NULL) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        $this->global = null;
        $this->peer = null;
        if ($this->admin !== null) {
            foreach ([$this->peerDatabase, $this->globalDatabase] as $name) {
                if ($name !== '' && preg_match('/^[a-zA-Z0-9_]+_migration_tests_dps_(peer|global)$/D', $name)) {
                    $this->admin->exec('DROP DATABASE IF EXISTS `' . $name . '`');
                }
            }
            $this->admin->exec('DROP TABLE IF EXISTS dps_local_fixture');
        }
        $this->admin = null;
        parent::tearDown();
    }

    public function testIndependentAkauntingDatabasesAllocateDistinctNumbersWithOneGlobalAuthority(): void
    {
        $issuer = $this->issuer();
        $this->registerSeries($issuer, 2, 40);
        $a = $this->coordinator(self::INSTALL_A);
        $b = $this->coordinator(self::INSTALL_B, 1, $this->connect($this->globalDatabase));
        $keyA = $this->key('invoice-company-one');
        $keyB = $this->key('invoice-company-two');

        $one = $a->allocate($issuer, 2, '00001', self::REFERENCE, $keyA);
        $two = $b->allocate($issuer, 2, '00001', self::REFERENCE, $keyB);
        self::assertSame(['number' => 41, 'fresh' => true], $one);
        self::assertSame(['number' => 42, 'fresh' => true], $two);

        // These writes go to DIFFERENT local schemas, not to the coordinator.
        $this->admin->prepare('INSERT INTO dps_local_fixture VALUES (?, ?)')
            ->execute([$keyA, $one['number']]);
        $this->peer->prepare('INSERT INTO dps_local_fixture VALUES (?, ?)')
            ->execute([$keyB, $two['number']]);

        self::assertSame('41', (string) $this->admin->query('SELECT number FROM dps_local_fixture')->fetchColumn());
        self::assertSame('42', (string) $this->peer->query('SELECT number FROM dps_local_fixture')->fetchColumn());
        self::assertSame(2, $this->allocationCount());
        self::assertSame(42, $this->highWater());
    }

    public function testTwoCentralMySqlConnectionsHaveRealRowLockContention(): void
    {
        $issuer = $this->issuer();
        $this->registerSeries($issuer, 2, 7);
        $other = $this->connect($this->globalDatabase);
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $this->global->beginTransaction();
        try {
            self::assertNotFalse($this->global->query('SELECT id FROM nfse_dps_coordination_series FOR UPDATE')->fetch());

            try {
                $this->coordinator(self::INSTALL_B, 1, $other)
                    ->allocate($issuer, 2, '00001', self::REFERENCE, $this->key('blocked'));
                self::fail('A second connection acquired a locked global counter.');
            } catch (PDOException $exception) {
                self::assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
            }

            self::assertSame(0, $this->allocationCount());
            $this->global->commit();
        } finally {
            if ($this->global->inTransaction()) {
                $this->global->rollBack();
            }
        }

        $allocated = $this->coordinator(self::INSTALL_B, 1, $other)
            ->allocate($issuer, 2, '00001', self::REFERENCE, $this->key('released'));
        self::assertSame(8, $allocated['number']);
        self::assertSame(1, $this->allocationCount());
    }

    public function testIdempotencySurvivesCrashAfterGlobalCommitWithNoLocalRecord(): void
    {
        $issuer = $this->issuer();
        $this->registerSeries($issuer, 2);
        $a = $this->coordinator(self::INSTALL_A);
        $operation = $this->key('crash-after-global-before-local');

        self::assertSame(['number' => 1, 'fresh' => true], $a->allocate(
            $issuer,
            2,
            '00001',
            self::REFERENCE,
            $operation,
        ));
        // Deliberately omit the local insert; simulate crash and stale restore.
        self::assertSame(0, (int) $this->admin->query('SELECT count(*) FROM dps_local_fixture')->fetchColumn());
        self::assertSame(['number' => 1, 'fresh' => false], $a->allocate(
            $issuer,
            2,
            '00001',
            self::REFERENCE,
            $operation,
        ));
        $a->assertExisting($issuer, 2, '00001', self::REFERENCE, $operation, 1);
        try {
            $a->assertExisting($issuer, 2, '00001', self::REFERENCE, $operation, 2);
            self::fail('An invented local DPS number cannot match the global ledger.');
        } catch (\LogicException) {
        }

        // A distinct operation never reclaims the abandoned number.
        self::assertSame(2, $a->allocate(
            $issuer,
            2,
            '00001',
            self::REFERENCE,
            $this->key('next-operation'),
        )['number']);
        self::assertSame(2, $this->allocationCount());
    }

    public function testSameOperationKeysFromDifferentInstallationsCannotCollide(): void
    {
        $issuer = $this->issuer();
        $this->registerSeries($issuer, 2);
        $sameLocalKey = $this->key('company=1;invoice=1;environment=2');
        $a = $this->coordinator(self::INSTALL_A)->allocate(
            $issuer,
            2,
            '00001',
            self::REFERENCE,
            $sameLocalKey,
        );
        $b = $this->coordinator(self::INSTALL_B, 1, $this->connect($this->globalDatabase))->allocate(
            $issuer,
            2,
            '00001',
            self::REFERENCE,
            $sameLocalKey,
        );

        self::assertSame(1, $a['number']);
        self::assertSame(2, $b['number']);
    }

    public function testProductionRestrictedAndDifferentFiscalIssuersHaveIndependentCounters(): void
    {
        $issuer = $this->issuer();
        $otherIssuer = new DpsFiscalIssuer('3550308', 2, '99111222000199');
        $this->registerSeries($issuer, 1);
        $this->registerSeries($issuer, 2);
        $this->registerSeries($otherIssuer, 2);

        $coordinator = $this->coordinator(self::INSTALL_A);
        self::assertSame(1, $coordinator->allocate(
            $issuer,
            1,
            '00001',
            self::REFERENCE,
            $this->key('prod'),
        )['number']);
        self::assertSame(1, $coordinator->allocate(
            $issuer,
            2,
            '00001',
            self::REFERENCE,
            $this->key('restricted'),
        )['number']);
        self::assertSame(1, $coordinator->allocate(
            $otherIssuer,
            2,
            '00001',
            self::REFERENCE,
            $this->key('other-issuer'),
        )['number']);
    }

    public function testRevocationFencesOldProcessAndNewGenerationCanResume(): void
    {
        $issuer = $this->issuer();
        $seriesId = $this->registerSeries($issuer, 2);
        $old = $this->coordinator(self::INSTALL_A);
        self::assertSame(1, $old->allocate(
            $issuer,
            2,
            '00001',
            self::REFERENCE,
            $this->key('before-revoke'),
        )['number']);

        $this->global->exec('UPDATE nfse_dps_coordination_members '
            . 'SET status = \'revoked\', generation = 2 WHERE series_id = ' . $seriesId
            . " AND installation_id = '" . self::INSTALL_A . "'");

        try {
            $old->allocate($issuer, 2, '00001', self::REFERENCE, $this->key('stale-process'));
            self::fail('A revoked process reused an old generation.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('fenced', $exception->getMessage());
        }

        $this->global->exec('UPDATE nfse_dps_coordination_members SET status = \'active\' '
            . 'WHERE series_id = ' . $seriesId . " AND installation_id = '" . self::INSTALL_A . "'");

        $new = $this->coordinator(self::INSTALL_A, 2, $this->connect($this->globalDatabase));
        self::assertSame(2, $new->allocate(
            $issuer,
            2,
            '00001',
            self::REFERENCE,
            $this->key('after-revoke'),
        )['number']);
        $this->expectException(\LogicException::class);
        $old->assertExisting($issuer, 2, '00001', self::REFERENCE, $this->key('before-revoke'), 1);
    }

    public function testWrongReferenceMissingEvidenceAndUnregisteredIssuerFailClosed(): void
    {
        $issuer = $this->issuer();
        $seriesId = $this->registerSeries($issuer, 2);
        $coordinator = $this->coordinator(self::INSTALL_A);

        foreach ([
            [new DpsFiscalIssuer('3550308', 2, '99111222000199'), self::REFERENCE],
            [$issuer, str_repeat('b', 64)],
        ] as [$who, $reference]) {
            try {
                $coordinator->allocate($who, 2, '00001', $reference, $this->key('forged'));
                self::fail('Wrong fiscal identity or reference was accepted.');
            } catch (\LogicException) {
            }
        }

        $this->global->exec('UPDATE nfse_dps_coordination_series SET evidence_sha256 = '
            . "'" . str_repeat('0', 64) . "' WHERE id = " . $seriesId);
        $this->expectException(\LogicException::class);
        try {
            $coordinator->allocate($issuer, 2, '00001', self::REFERENCE, $this->key('no-evidence'));
        } finally {
            self::assertSame(0, $this->allocationCount());
            self::assertSame(0, $this->highWater());
        }
    }

    public function testSeriesSuspensionPreventsNewAllocationEvenIfMemberStillActive(): void
    {
        $issuer = $this->issuer();
        $id = $this->registerSeries($issuer, 2);
        $this->global->exec("UPDATE nfse_dps_coordination_series SET status = 'inactive' WHERE id = " . $id);
        $this->expectException(\LogicException::class);
        $this->coordinator(self::INSTALL_A)->allocate(
            $issuer,
            2,
            '00001',
            self::REFERENCE,
            $this->key('inactive'),
        );
    }

    public function testLostCoordinatorConnectionCannotAllocateOrReuseOldNumber(): void
    {
        $issuer = $this->issuer();
        $this->registerSeries($issuer, 2);
        $remote = $this->connect($this->globalDatabase);
        $allocator = $this->coordinator(self::INSTALL_A, 1, $remote);

        // A deliberate transport failure, not a SEFIN test. CI MySQL user
        // is root on its private service container.
        $connectionId = (int) $remote->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->global->exec('KILL CONNECTION ' . $connectionId);

        try {
            $allocator->allocate($issuer, 2, '00001', self::REFERENCE, $this->key('offline'));
            self::fail('Lost coordinator connectivity allowed an allocation.');
        } catch (PDOException) {
            self::assertSame(0, $this->allocationCount());
            self::assertSame(0, $this->highWater());
        }
    }

    public function testOfficialRejectionAndAmbiguousResultCannotRecycleAnAllocatedNumber(): void
    {
        $issuer = $this->issuer();
        $this->registerSeries($issuer, 2);
        $a = $this->coordinator(self::INSTALL_A);
        $rejected = $this->key('confirmed-rejection');
        $ambiguous = $this->key('ambiguous-response');

        self::assertSame(1, $a->allocate($issuer, 2, '00001', self::REFERENCE, $rejected)['number']);
        self::assertSame(2, $a->allocate($issuer, 2, '00001', self::REFERENCE, $ambiguous)['number']);
        // No API to release or recycle either reservation on fiscal outcome.
        self::assertSame(['number' => 1, 'fresh' => false], $a->allocate(
            $issuer,
            2,
            '00001',
            self::REFERENCE,
            $rejected,
        ));
        self::assertSame(3, $a->allocate(
            $issuer,
            2,
            '00001',
            self::REFERENCE,
            $this->key('unrelated-new-emission'),
        )['number']);
    }

    public function testGlobalUpperBoundIsEnforcedWithoutWrappingOrErasingHistory(): void
    {
        $issuer = $this->issuer();
        $this->registerSeries($issuer, 2, 999999999999998);
        $a = $this->coordinator(self::INSTALL_A);
        self::assertSame(999999999999999, $a->allocate(
            $issuer,
            2,
            '00001',
            self::REFERENCE,
            $this->key('last-number'),
        )['number']);
        $this->expectException(\OverflowException::class);
        try {
            $a->allocate($issuer, 2, '00001', self::REFERENCE, $this->key('overflow'));
        } finally {
            self::assertSame(999999999999999, $this->highWater());
            self::assertSame(1, $this->allocationCount());
        }
    }

    public function testCoordinatorWorksWithLeastPrivilegeMysqlCredentials(): void
    {
        $issuer = $this->issuer();
        $this->registerSeries($issuer, 2);
        $name = 'nfse_dps_test_worker';
        $login = "'nfse_dps_test_worker'@'%'";
        $this->admin->exec('DROP USER IF EXISTS ' . $login);
        $this->admin->exec("CREATE USER " . $login . " IDENTIFIED BY 'synthetic-ci-coordinator-only'");

        try {
            $schema = '`' . $this->globalDatabase . '`.';
            $this->admin->exec('GRANT SELECT ON ' . $schema . 'nfse_dps_coordination_series TO ' . $login);
            $this->admin->exec('GRANT UPDATE (last_number) ON ' . $schema
                . 'nfse_dps_coordination_series TO ' . $login);
            $this->admin->exec('GRANT SELECT ON ' . $schema . 'nfse_dps_coordination_members TO ' . $login);
            $this->admin->exec('GRANT SELECT, INSERT ON ' . $schema
                . 'nfse_dps_coordination_allocations TO ' . $login);

            $worker = new PDO(
                'mysql:host=' . (getenv('NFSE_MIGRATION_TEST_HOST') ?: '127.0.0.1')
                . ';port=' . (getenv('NFSE_MIGRATION_TEST_PORT') ?: '3306')
                . ';dbname=' . $this->globalDatabase,
                $name,
                'synthetic-ci-coordinator-only',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
            );

            $coordinator = $this->coordinator(self::INSTALL_A, 1, $worker);
            $operation = $this->key('least-privileged');
            self::assertSame(['number' => 1, 'fresh' => true], $coordinator->allocate(
                $issuer,
                2,
                '00001',
                self::REFERENCE,
                $operation,
            ));
            $coordinator->assertExisting($issuer, 2, '00001', self::REFERENCE, $operation, 1);
            self::assertSame(['number' => 1, 'fresh' => false], $coordinator->allocate(
                $issuer,
                2,
                '00001',
                self::REFERENCE,
                $operation,
            ));

            try {
                $worker->exec("UPDATE nfse_dps_coordination_members SET status = 'active'");
                self::fail('Allocator credentials must not be able to mutate member authority.');
            } catch (PDOException $exception) {
                self::assertContains((int) ($exception->errorInfo[1] ?? 0), [1142, 1143]);
            }

            $worker = null;
        } finally {
            $this->admin->exec('DROP USER IF EXISTS ' . $login);
        }
    }

    public function testMemberRevocationWaitsForAnInFlightSharedLock(): void
    {
        $issuer = $this->issuer();
        $this->registerSeries($issuer, 2);
        $another = $this->connect($this->globalDatabase);
        $another->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $this->global->beginTransaction();
        try {
            self::assertNotFalse($this->global->query(
                'SELECT generation FROM nfse_dps_coordination_members '
                . "WHERE installation_id = '" . self::INSTALL_A . "' FOR SHARE",
            )->fetch());
            $another->beginTransaction();
            try {
                $another->exec("UPDATE nfse_dps_coordination_members SET status = 'revoked' "
                    . "WHERE installation_id = '" . self::INSTALL_A . "'");
                self::fail('Revocation bypassed an in-flight authorization lock.');
            } catch (PDOException $exception) {
                self::assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
            } finally {
                if ($another->inTransaction()) {
                    $another->rollBack();
                }
            }
            $this->global->commit();
        } finally {
            if ($this->global->inTransaction()) {
                $this->global->rollBack();
            }
        }

        $another->exec("UPDATE nfse_dps_coordination_members SET status = 'revoked' "
            . "WHERE installation_id = '" . self::INSTALL_A . "'");
        $this->expectException(\LogicException::class);
        $this->coordinator(self::INSTALL_A)->allocate(
            $issuer,
            2,
            '00001',
            self::REFERENCE,
            $this->key('revoked-once-lock-released'),
        );
    }

    public function testFirstPostCanBeClaimedOnceAcrossConnectionsAndNotReplayedAfterRestore(): void
    {
        $issuer = $this->issuer();
        $this->registerSeries($issuer, 2);
        $a = $this->coordinator(self::INSTALL_A);
        $peer = $this->coordinator(self::INSTALL_A, 1, $this->connect($this->globalDatabase));
        $operation = $this->key('first-post-only');
        $fingerprint = $this->key('payload-v1');
        $number = $a->allocate($issuer, 2, '00001', self::REFERENCE, $operation)['number'];

        $a->claimFirstTransmission($issuer, 2, '00001', self::REFERENCE, $operation, $number, $fingerprint);
        $this->expectException(\LogicException::class);
        $peer->claimFirstTransmission($issuer, 2, '00001', self::REFERENCE, $operation, $number, $fingerprint);
    }

    public function testRevocationOrMissingAllocationBlocksFirstPostWithoutClaiming(): void
    {
        $issuer = $this->issuer();
        $this->registerSeries($issuer, 2);
        $a = $this->coordinator(self::INSTALL_A);
        $operation = $this->key('revoked-post');
        $fingerprint = $this->key('payload-revoked');
        $a->allocate($issuer, 2, '00001', self::REFERENCE, $operation);
        $this->global->exec("UPDATE nfse_dps_coordination_members SET status = 'revoked' "
            . "WHERE installation_id = '" . self::INSTALL_A . "'");

        try {
            $a->claimFirstTransmission($issuer, 2, '00001', self::REFERENCE, $operation, 1, $fingerprint);
            self::fail('Revoked installation cannot start the first fiscal POST.');
        } catch (\LogicException) {
        }

        self::assertSame(0, (int) $this->global->query(
            'SELECT COUNT(*) FROM nfse_dps_coordination_allocations WHERE first_post_claimed_at IS NOT NULL',
        )->fetchColumn());
    }

    public function testFailedClaimDoesNotConsumeAnotherOperationOrEmit(): void
    {
        $issuer = $this->issuer();
        $this->registerSeries($issuer, 2);
        $a = $this->coordinator(self::INSTALL_A);
        $first = $this->key('first');
        $second = $this->key('second');
        $a->allocate($issuer, 2, '00001', self::REFERENCE, $first);
        $a->allocate($issuer, 2, '00001', self::REFERENCE, $second);
        try {
            $a->claimFirstTransmission($issuer, 2, '00001', self::REFERENCE, $first, 2, $this->key('bad'));
            self::fail('Wrong number is not entitled to first POST.');
        } catch (\LogicException) {
        }
        $a->claimFirstTransmission($issuer, 2, '00001', self::REFERENCE, $first, 1, $this->key('good'));
        $a->claimFirstTransmission($issuer, 2, '00001', self::REFERENCE, $second, 2, $this->key('good2'));

        self::assertSame(2, (int) $this->global->query(
            'SELECT COUNT(*) FROM nfse_dps_coordination_allocations WHERE first_post_claimed_at IS NOT NULL',
        )->fetchColumn());
    }

    public function testTwoConnectionsCannotClaimFirstPostWhileTheAuthorityRowIsLocked(): void
    {
        $issuer = $this->issuer();
        $this->registerSeries($issuer, 2);
        $operation = $this->key('first-post-lock');
        $fingerprint = $this->key('first-post-payload');
        $authority = $this->coordinator(self::INSTALL_A);
        $authority->allocate($issuer, 2, '00001', self::REFERENCE, $operation);

        $other = $this->connect($this->globalDatabase);
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->global->beginTransaction();
        try {
            self::assertNotFalse($this->global->query(
                'SELECT id FROM nfse_dps_coordination_allocations FOR UPDATE',
            )->fetch());

            try {
                $this->coordinator(self::INSTALL_A, 1, $other)
                    ->claimFirstTransmission($issuer, 2, '00001', self::REFERENCE, $operation, 1, $fingerprint);
                self::fail('Second connection bypassed the authoritative first-POST lock.');
            } catch (PDOException $exception) {
                self::assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
            }

            self::assertSame(0, (int) $this->global->query(
                'SELECT COUNT(*) FROM nfse_dps_coordination_allocations WHERE first_post_claimed_at IS NOT NULL',
            )->fetchColumn());
            $this->global->commit();
        } finally {
            if ($this->global->inTransaction()) {
                $this->global->rollBack();
            }
        }

        $authority->claimFirstTransmission($issuer, 2, '00001', self::REFERENCE, $operation, 1, $fingerprint);
        self::assertSame(1, (int) $this->global->query(
            'SELECT COUNT(*) FROM nfse_dps_coordination_allocations WHERE first_post_claimed_at IS NOT NULL',
        )->fetchColumn());
    }

    private function coordinator(string $installation, int $generation = 1, ?PDO $pdo = null): SharedMysqlDpsSeriesCoordinator
    {
        return new SharedMysqlDpsSeriesCoordinator($pdo ?? $this->global, $installation, $generation);
    }

    private function issuer(): DpsFiscalIssuer
    {
        return new DpsFiscalIssuer('3303302', 2, '11222333000181');
    }

    private function key(string $label): string
    {
        return hash('sha256', 'synthetic-test:' . $label);
    }

    private function registerSeries(DpsFiscalIssuer $issuer, int $environment, int $floor = 0): int
    {
        $statement = $this->global->prepare(
            'INSERT INTO nfse_dps_coordination_series '
            . '(environment, issuer_municipality, issuer_registration_type, '
            . 'issuer_registration, series, coordination_reference, status, '
            . 'attested_floor, evidence_sha256, last_number) '
            . "VALUES (?, ?, ?, ?, '00001', ?, 'active', ?, ?, ?)",
        );
        $statement->execute([
            $environment,
            $issuer->municipality,
            $issuer->registrationType,
            $issuer->registration,
            self::REFERENCE,
            $floor,
            hash('sha256', 'synthetic-historical-evidence:' . $floor),
            $floor,
        ]);
        $id = (int) $this->global->lastInsertId();
        $member = $this->global->prepare(
            'INSERT INTO nfse_dps_coordination_members '
            . "(series_id, installation_id, generation, status) VALUES (?, ?, 1, 'active')",
        );
        $member->execute([$id, self::INSTALL_A]);
        $member->execute([$id, self::INSTALL_B]);

        return $id;
    }

    private function highWater(): int
    {
        return (int) $this->global->query('SELECT max(last_number) FROM nfse_dps_coordination_series')->fetchColumn();
    }

    private function allocationCount(): int
    {
        return (int) $this->global->query('SELECT count(*) FROM nfse_dps_coordination_allocations')->fetchColumn();
    }

    private function connect(string $database): PDO
    {
        return new PDO(
            'mysql:host=' . (getenv('NFSE_MIGRATION_TEST_HOST') ?: '127.0.0.1')
            . ';port=' . (getenv('NFSE_MIGRATION_TEST_PORT') ?: '3306')
            . ';dbname=' . $database,
            getenv('NFSE_MIGRATION_TEST_USER') ?: 'root',
            getenv('NFSE_MIGRATION_TEST_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }
}

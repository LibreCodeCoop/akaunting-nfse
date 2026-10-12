<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Integration\Migrations;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

final class NfseCompanyProfileConcurrencyTest extends TestCase
{
    private bool $connected = false;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('NFSE_MIGRATION_TEST_DB');

        if ($database === false || !str_ends_with($database, '_migration_tests')) {
            self::markTestSkipped('A dedicated *_migration_tests MySQL database is required.');
        }

        $capsule = new Manager();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => getenv('NFSE_MIGRATION_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('NFSE_MIGRATION_TEST_PORT') ?: '3306',
            'database' => $database,
            'username' => getenv('NFSE_MIGRATION_TEST_USER') ?: 'root',
            'password' => getenv('NFSE_MIGRATION_TEST_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => true,
        ]);

        $container = $capsule->getContainer();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->bind('db.schema', static fn () => $capsule->getConnection()->getSchemaBuilder());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        DB::connection()->getPdo();
        $this->connected = true;

        $this->dropTables();
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        if ($this->connected) {
            $this->dropTables();
            DB::disconnect();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function testTwoConnectionsCannotCreateProfilesForTheSameCompany(): void
    {
        self::assertTrue(Schema::hasIndex('nfse_company_profiles', 'nfse_company_profiles_company_id_unique'));
        $first = DB::connection()->getPdo();
        $second = new \PDO(
            'mysql:host=' . (getenv('NFSE_MIGRATION_TEST_HOST') ?: '127.0.0.1')
            . ';port=' . (getenv('NFSE_MIGRATION_TEST_PORT') ?: '3306')
            . ';dbname=' . getenv('NFSE_MIGRATION_TEST_DB'),
            getenv('NFSE_MIGRATION_TEST_USER') ?: 'root',
            getenv('NFSE_MIGRATION_TEST_PASSWORD') ?: '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $second->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $insert = "INSERT INTO nfse_company_profiles (company_id, status, created_at, updated_at)"
            . " VALUES (120, 'provisioning', NOW(), NOW())";

        $first->beginTransaction();
        try {
            $first->exec($insert);
            $second->beginTransaction();
            try {
                $second->exec($insert);
                self::fail('Concurrent insert of the same company must wait on uniqueness.');
            } catch (\PDOException $error) {
                self::assertSame('HY000', $error->getCode());
            } finally {
                if ($second->inTransaction()) {
                    $second->rollBack();
                }
            }
        } finally {
            if ($first->inTransaction()) {
                $first->commit();
            }
        }

        try {
            $second->exec($insert);
            self::fail('After the first commit a second association must violate the unique key.');
        } catch (\PDOException $error) {
            self::assertSame('23000', $error->getCode());
        }

        self::assertSame(1, DB::table('nfse_company_profiles')->where('company_id', 120)->count());
    }

    public function testRollbackRefusesToOrphanAnyPersistedCertificateReference(): void
    {
        $timestamp = gmdate('Y-m-d H:i:s');
        DB::table('nfse_company_profiles')->insert([
            'company_id' => 120,
            'status' => 'deleting',
            'staged_certificate_ref' => str_repeat('a', 32),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        try {
            $this->migration()->down();
            self::fail('Migration rollback must not orphan a staged certificate.');
        } catch (\LogicException) {
            self::assertTrue(Schema::hasTable('nfse_company_profiles'));
            self::assertSame(1, DB::table('nfse_company_profiles')->count());
        }
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require __DIR__ . '/../../../Database/Migrations/2026_10_12_230100_create_nfse_company_profiles.php';
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('nfse_company_certificate_retirements');
        Schema::dropIfExists('nfse_company_profiles');
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Integration\Migrations;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Modules\Nfse\Application\DpsNumberAdvance;
use Modules\Nfse\Application\DpsFiscalIssuer;
use Modules\Nfse\Application\SharedMysqlDpsSeriesCoordinator;
use PDO;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/Application/DpsFiscalIssuer.php';
require_once dirname(__DIR__, 3) . '/Application/DpsSeriesCoordinator.php';
require_once dirname(__DIR__, 3) . '/Application/DpsIssuanceIdentityContract.php';
require_once dirname(__DIR__, 3) . '/Application/DpsIssuanceIdentity.php';
require_once dirname(__DIR__, 3) . '/Application/SharedMysqlDpsSeriesCoordinator.php';
require_once dirname(__DIR__, 3) . '/Support/NfseCompanyIssuerPayload.php';
require_once dirname(__DIR__, 3) . '/Models/NfseCompanyProfile.php';
require_once dirname(__DIR__, 3) . '/Application/DpsNumberAdvance.php';

/** Dedicated disposable MySQL only; no external certificate or SEFIN calls. */
final class DpsNumberAdvanceTest extends TestCase
{
    private const REFERENCE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const INSTALLATION = '11111111-1111-4111-8111-111111111111';
    private bool $connected = false;
    private int $seriesId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('NFSE_MIGRATION_TEST_DB');
        if ($database === false || !preg_match('/^[a-zA-Z0-9_]+_migration_tests$/D', $database)) {
            self::markTestSkipped('Dedicated *_migration_tests MySQL database required.');
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
        Model::setConnectionResolver($capsule->getDatabaseManager());
        DB::connection()->getPdo();
        $this->connected = true;
        $this->dropFixtures();

        Schema::create('nfse_company_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->string('status', 16);
            $table->string('certificate_cnpj', 14);
            $table->json('fiscal_parameters');
            $table->timestamps();
        });
        Schema::create('documents', static function (Blueprint $table): void {
            $table->increments('id');
        });
        Schema::create('nfse_emission_attempts', static function (Blueprint $table): void {
            $table->id();
        });

        $migration = require dirname(__DIR__, 3) . '/Database/Migrations/2026_10_12_230000_create_nfse_dps_reservations.php';
        $migration->up();
        $migration = require dirname(__DIR__, 3) . '/Database/Migrations/2026_11_04_000001_create_nfse_local_dps_authority.php';
        $migration->up();
        $migration = require dirname(__DIR__, 3) . '/Database/Migrations/2026_11_04_000002_create_nfse_dps_number_adjustments.php';
        $migration->up();

        DB::table('nfse_company_profiles')->insert([
            'company_id' => 1,
            'status' => 'active',
            'certificate_cnpj' => '11222333000181',
            'fiscal_parameters' => json_encode([
                'municipio_ibge' => '3303302',
                'dps_series' => '00001',
                'sandbox_mode' => true,
            ], JSON_THROW_ON_ERROR),
        ]);
        $identity = [
            'environment' => 2,
            'issuer_municipality' => '3303302',
            'issuer_registration_type' => 2,
            'issuer_registration' => '11222333000181',
            'series' => '00001',
        ];
        $this->seriesId = (int) DB::table('nfse_dps_coordination_series')->insertGetId($identity + [
            'coordination_reference' => self::REFERENCE,
            'status' => 'inactive',
            'attested_floor' => 0,
            'evidence_sha256' => hash('sha256', 'synthetic evidence that series is unused'),
            'last_number' => 0,
        ]);
        DB::table('nfse_dps_series')->insert($identity + [
            'coordination_mode' => 'shared_database',
            'coordination_reference' => self::REFERENCE,
            'status' => 'inactive',
            'last_number' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->connected) {
            $this->dropFixtures();
            DB::disconnect();
        }
        Model::unsetConnectionResolver();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function testAdvancesBothHighWatersAndRecordsWhoWhyAndWhen(): void
    {
        $result = $this->advance('1', '51');
        self::assertSame(['series' => '00001', 'previous_next' => '1', 'next_number' => '51'], $result);
        self::assertSame(50, $this->localLast());
        self::assertSame(50, $this->globalLast());
        $audit = DB::table('nfse_dps_number_adjustments')->sole();
        self::assertSame(1, (int) $audit->company_id);
        self::assertSame(7, (int) $audit->actor_id);
        self::assertSame(0, (int) $audit->previous_last_number);
        self::assertSame(50, (int) $audit->new_last_number);
        self::assertSame('Synthetic old emitter numbered through 50', $audit->reason);
        self::assertNotNull($audit->created_at);
        self::assertSame(0, DB::table('nfse_dps_coordination_allocations')->count());
    }

    public function testStaleReplayAndBackwardsNumbersFailClosed(): void
    {
        $this->advance('1', '51');
        foreach ([['1', '55'], ['51', '50'], ['51', '51'], ['51', '0']] as [$expected, $target]) {
            try {
                $this->advance($expected, $target);
                self::fail('Stale or non-increasing adjustment was accepted');
            } catch (\LogicException|\InvalidArgumentException) {
                self::assertSame(50, $this->globalLast());
                self::assertSame(50, $this->localLast());
            }
        }
        self::assertSame(1, DB::table('nfse_dps_number_adjustments')->count());
    }

    public function testWrongCompanyAndMismatchingAuthoritiesCannotAdvance(): void
    {
        try {
            (new DpsNumberAdvance())->advance(2, 7, '1', '51', 'Synthetic cross tenant attempt');
            self::fail('Other tenant must not change a fiscal sequence');
        } catch (\LogicException) {
            self::assertSame(0, $this->globalLast());
        }
        DB::table('nfse_dps_series')->update(['coordination_reference' => str_repeat('c', 64)]);
        try {
            $this->advance('1', '51');
            self::fail('Mismatched local authority must fail closed');
        } catch (\LogicException) {
            self::assertSame(0, $this->globalLast());
            self::assertSame(0, DB::table('nfse_dps_number_adjustments')->count());
        }
    }

    public function testAuditWriteFailureRollsBackBothCounters(): void
    {
        $this->advance('1', '51');
        Schema::table('nfse_dps_number_adjustments', static function (Blueprint $table): void {
            $table->unique('series_id', 'synthetic_one_adjustment_only');
        });
        try {
            $this->advance('51', '52');
            self::fail('Synthetic second audit insert must fail');
        } catch (\Illuminate\Database\QueryException) {
            self::assertSame(50, $this->globalLast());
            self::assertSame(50, $this->localLast());
            self::assertSame(1, DB::table('nfse_dps_number_adjustments')->count());
        }
    }

    public function testAuthoritativeAllocatorContinuesFromAdjustedNumber(): void
    {
        $this->advance('1', '51');
        DB::table('nfse_dps_coordination_series')->update(['status' => 'active']);
        DB::table('nfse_dps_series')->update(['status' => 'active']);
        DB::table('nfse_dps_coordination_members')->insert([
            'series_id' => $this->seriesId,
            'installation_id' => self::INSTALLATION,
            'generation' => 1,
            'status' => 'active',
        ]);
        $coordinator = new SharedMysqlDpsSeriesCoordinator(
            $this->anotherConnection(), self::INSTALLATION, 1,
        );
        $allocated = $coordinator->allocate(
            new DpsFiscalIssuer('3303302', 2, '11222333000181'),
            2, '00001', self::REFERENCE, hash('sha256', 'synthetic-next-operation'),
        );
        self::assertSame(51, $allocated['number']);
        self::assertTrue($allocated['fresh']);
    }

    /** @return array{series:string,previous_next:string,next_number:string} */
    private function advance(string $expected, string $requested): array
    {
        return (new DpsNumberAdvance())->advance(
            1, 7, $expected, $requested, 'Synthetic old emitter numbered through 50',
        );
    }

    private function localLast(): int
    {
        return (int) DB::table('nfse_dps_series')->value('last_number');
    }

    private function globalLast(): int
    {
        return (int) DB::table('nfse_dps_coordination_series')->value('last_number');
    }

    private function anotherConnection(): PDO
    {
        return new PDO(
            'mysql:host=' . (getenv('NFSE_MIGRATION_TEST_HOST') ?: '127.0.0.1')
                . ';port=' . (getenv('NFSE_MIGRATION_TEST_PORT') ?: '3306')
                . ';dbname=' . getenv('NFSE_MIGRATION_TEST_DB'),
            getenv('NFSE_MIGRATION_TEST_USER') ?: 'root',
            getenv('NFSE_MIGRATION_TEST_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function dropFixtures(): void
    {
        foreach ([
            'nfse_dps_number_adjustments', 'nfse_dps_coordination_allocations',
            'nfse_dps_coordination_members', 'nfse_dps_coordination_series',
            'nfse_emission_attempts', 'nfse_dps_reservations', 'nfse_dps_series',
            'nfse_company_profiles', 'documents',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}

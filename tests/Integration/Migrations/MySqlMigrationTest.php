<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Integration\Migrations;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

final class MySqlMigrationTest extends TestCase
{
    private bool $connected = false;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('NFSE_MIGRATION_TEST_DB');
        if ($database === false || !str_ends_with($database, '_migration_tests')) {
            self::markTestSkipped('Set NFSE_MIGRATION_TEST_DB to a dedicated *_migration_tests database.');
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
        $this->dropFixtures();

        Schema::create('documents', function (Blueprint $table): void {
            $table->increments('id');
        });
        $this->migration('2026_01_01_000001_create_nfse_receipts_table')->up();
        // Legacy installations may have only the UNIQUE index supporting the FK.
        if (Schema::hasIndex('nfse_receipts', 'nfse_receipts_invoice_id_index')) {
            Schema::table('nfse_receipts', function (Blueprint $table): void {
                $table->dropIndex(['invoice_id']);
            });
        }
        DB::table('documents')->insert(['id' => 1]);
        DB::table('nfse_receipts')->insert(['id' => 1, 'invoice_id' => 1, 'status' => 'emitted']);
    }

    protected function tearDown(): void
    {
        if ($this->connected) {
            $this->dropFixtures();
            DB::disconnect();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function testReceiptUpgradePreservesRowsAndForeignKey(): void
    {
        $migration = $this->migration('2026_10_04_000001_allow_multiple_nfse_receipts_per_invoice');
        $migration->up();
        $migration->up();

        self::assertSame('emitted', DB::table('nfse_receipts')->where('id', 1)->value('status'));
        self::assertTrue(Schema::hasIndex('nfse_receipts', 'nfse_receipts_invoice_id_index'));
        self::assertFalse(Schema::hasIndex('nfse_receipts', 'nfse_receipts_invoice_id_unique'));
        DB::table('nfse_receipts')->insert(['invoice_id' => 1]);
        self::assertSame(2, DB::table('nfse_receipts')->count());
        DB::table('documents')->where('id', 1)->delete();
        self::assertSame(0, DB::table('nfse_receipts')->count());
    }

    public function testReceiptUpgradeResumesAfterColumnsWereCreated(): void
    {
        Schema::table('nfse_receipts', function (Blueprint $table): void {
            $table->string('emission_group_key', 100)->nullable();
            $table->unsignedBigInteger('replaces_receipt_id')->nullable();
        });
        DB::table('nfse_receipts')->where('id', 1)->update(['emission_group_key' => 'existing-group']);
        $this->migration('2026_10_04_000001_allow_multiple_nfse_receipts_per_invoice')->up();

        self::assertSame('existing-group', DB::table('nfse_receipts')->where('id', 1)->value('emission_group_key'));
        foreach (['emission_group_key', 'replaces_receipt_id'] as $column) {
            self::assertTrue(Schema::hasIndex('nfse_receipts', 'nfse_receipts_' . $column . '_index'));
        }
        self::assertFalse(Schema::hasIndex('nfse_receipts', 'nfse_receipts_invoice_id_unique'));
    }

    public function testReceiptRollbackPreservesMetadataWhenUniquenessCannotBeRestored(): void
    {
        $migration = $this->migration('2026_10_04_000001_allow_multiple_nfse_receipts_per_invoice');
        $migration->up();
        DB::table('nfse_receipts')->where('id', 1)->update(['emission_group_key' => 'original']);
        DB::table('nfse_receipts')->insert(['invoice_id' => 1, 'emission_group_key' => 'second']);
        try {
            $migration->down();
            self::fail('Rollback must reject multiple receipts for an invoice.');
        } catch (QueryException) {
            self::assertTrue(Schema::hasColumn('nfse_receipts', 'emission_group_key'));
            self::assertSame(['original', 'second'], DB::table('nfse_receipts')->orderBy('id')->pluck('emission_group_key')->all());
        }
    }

    public function testReceiptRollbackAndReapply(): void
    {
        $migration = $this->migration('2026_10_04_000001_allow_multiple_nfse_receipts_per_invoice');
        $migration->up();
        $migration->down();
        self::assertFalse(Schema::hasColumn('nfse_receipts', 'emission_group_key'));
        self::assertTrue(Schema::hasIndex('nfse_receipts', 'nfse_receipts_invoice_id_unique'));
        $migration->up();
        self::assertSame(1, DB::table('nfse_receipts')->count());
    }

    public function testBulkUpgradeCreatesCompatibleKeysAndEnforcesDeleteRules(): void
    {
        $migration = $this->migration('2026_10_05_000007_create_nfse_bulk_emission_runs');
        $migration->up();
        $migration->up();
        $this->assertBulkStructure();
        DB::table('nfse_bulk_emission_runs')->insert(['id' => 1, 'company_id' => 1, 'selection_hash' => 'selection']);
        DB::table('nfse_bulk_emission_units')->insert([
            'run_id' => 1, 'invoice_id' => 1, 'receipt_id' => 1, 'emission_group_key' => 'group',
        ]);
        DB::table('nfse_receipts')->where('id', 1)->delete();
        self::assertNull(DB::table('nfse_bulk_emission_units')->value('receipt_id'));
        DB::table('documents')->where('id', 1)->delete();
        self::assertSame(0, DB::table('nfse_bulk_emission_units')->count());
        $migration->down();
        self::assertFalse(Schema::hasTable('nfse_bulk_emission_units'));
        self::assertFalse(Schema::hasTable('nfse_bulk_emission_runs'));
    }

    public function testBulkUpgradeResumesPartialTablesWithoutLosingRows(): void
    {
        $this->createPartialBulkTables();
        DB::table('nfse_bulk_emission_units')->insert([
            'id' => 1, 'run_id' => 1, 'invoice_id' => 1, 'receipt_id' => 1,
            'emission_group_key' => 'existing-group', 'status' => 'processing',
        ]);
        $migration = $this->migration('2026_10_05_000007_create_nfse_bulk_emission_runs');
        $migration->up();
        $migration->up();
        $this->assertBulkStructure();
        self::assertSame('processing', DB::table('nfse_bulk_emission_units')->where('id', 1)->value('status'));
        self::assertSame(1, DB::table('nfse_bulk_emission_runs')->count());
        DB::table('nfse_bulk_emission_runs')->where('id', 1)->delete();
        self::assertSame(0, DB::table('nfse_bulk_emission_units')->count());
    }

    public function testBulkUpgradeRejectsOutOfRangeInvoiceIdsWithoutTruncatingThem(): void
    {
        $this->createPartialBulkTables();
        DB::table('nfse_bulk_emission_units')->insert([
            'run_id' => 1, 'invoice_id' => 4294967296, 'emission_group_key' => 'overflow',
        ]);
        try {
            $this->migration('2026_10_05_000007_create_nfse_bulk_emission_runs')->up();
            self::fail('Out-of-range invoice IDs must not be truncated.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('unsigned INT range', $exception->getMessage());
            self::assertSame('4294967296', (string) DB::table('nfse_bulk_emission_units')->value('invoice_id'));
        }
    }

    public function testBulkUpgradeRejectsOrphanInvoice(): void
    {
        $this->migration('2026_10_05_000007_create_nfse_bulk_emission_runs')->up();
        DB::table('nfse_bulk_emission_runs')->insert(['id' => 1, 'company_id' => 1, 'selection_hash' => 'selection']);
        $this->expectException(QueryException::class);
        DB::table('nfse_bulk_emission_units')->insert([
            'run_id' => 1, 'invoice_id' => 999, 'emission_group_key' => 'orphan',
        ]);
    }

    private function assertBulkStructure(): void
    {
        $columns = array_column(Schema::getColumns('nfse_bulk_emission_units'), null, 'name');
        self::assertSame('int', $columns['invoice_id']['type_name']);
        self::assertStringContainsString('unsigned', $columns['invoice_id']['type']);
        $foreignKeys = array_column(Schema::getForeignKeys('nfse_bulk_emission_units'), 'name');
        foreach (['run_id', 'invoice_id', 'receipt_id'] as $column) {
            self::assertContains('nfse_bulk_emission_units_' . $column . '_foreign', $foreignKeys);
        }
        foreach (['run_id', 'invoice_id', 'status', 'receipt_id'] as $column) {
            self::assertTrue(Schema::hasIndex('nfse_bulk_emission_units', 'nfse_bulk_emission_units_' . $column . '_index'));
        }
        self::assertTrue(Schema::hasIndex('nfse_bulk_emission_units', 'nfse_bulk_emission_unit_unique'));
    }

    private function createPartialBulkTables(): void
    {
        Schema::create('nfse_bulk_emission_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->string('status', 24)->default('queued')->index();
            $table->string('selection_hash', 64)->index();
            $table->timestamps();
        });
        DB::table('nfse_bulk_emission_runs')->insert(['id' => 1, 'company_id' => 1, 'selection_hash' => 'selection']);
        Schema::create('nfse_bulk_emission_units', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('invoice_id');
            $table->string('emission_group_key', 255);
            $table->string('status', 32)->default('queued');
            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->string('error_type', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'invoice_id', 'emission_group_key'], 'nfse_bulk_emission_unit_unique');
            $table->foreign('run_id')->references('id')->on('nfse_bulk_emission_runs')->onDelete('cascade');
        });
    }

    private function migration(string $name): Migration
    {
        return require __DIR__ . '/../../../Database/Migrations/' . $name . '.php';
    }

    private function dropFixtures(): void
    {
        foreach (['nfse_bulk_emission_units', 'nfse_bulk_emission_runs', 'nfse_receipts', 'documents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}

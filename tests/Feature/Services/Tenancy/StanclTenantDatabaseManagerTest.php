<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Services\Tenancy;

use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Services\Tenancy\StanclTenantDatabaseManager;
use Nvade\Numerosis\Tests\TestCase;

/**
 * No `RefreshDatabase`: every assertion here issues DDL, which commits the
 * transaction it would open.
 */
class StanclTenantDatabaseManagerTest extends TestCase
{
    private const string SQLITE_CONNECTION = 'numerosis_sqlite_probe';

    /**
     * `information_schema.schemata` answers this on MySQL and lists schemas
     * rather than databases on PostgreSQL, so the two drivers need different
     * queries to find the same thing.
     */
    public function test_it_lists_and_drops_a_database_on_the_server_driver(): void
    {
        $this->skipWithoutDatabaseServer();

        $manager = new StanclTenantDatabaseManager;

        $prefix = 'numerosis_probe_'.uniqid().'_';
        $name = $prefix.'orphan';

        $this->createServerDatabase($name);

        try {
            $this->assertSame([$name], $manager->namesMatchingPrefix($prefix));

            $manager->dropDatabase($name);

            $this->assertSame([], $manager->namesMatchingPrefix($prefix));
        } finally {
            $this->dropServerDatabase($name);
        }
    }

    public function test_it_reports_no_match_for_an_unused_prefix(): void
    {
        $manager = new StanclTenantDatabaseManager;

        $this->assertSame([], $manager->namesMatchingPrefix('numerosis_no_such_prefix_'.uniqid()));
    }

    /**
     * stancl's `SQLiteDatabaseManager` is one file per tenant under
     * `database_path()`, so listing is a glob and dropping is an `unlink()`.
     */
    public function test_it_lists_and_drops_database_files_on_sqlite(): void
    {
        $this->useSqliteConnection();

        $manager = new StanclTenantDatabaseManager;

        $prefix = 'numerosis_probe_'.uniqid().'_';
        $name = $prefix.'orphan';
        $path = database_path($name);

        file_put_contents($path, '');

        try {
            $this->assertSame([$name], $manager->namesMatchingPrefix($prefix, self::SQLITE_CONNECTION));

            $this->assertTrue($manager->dropDatabase($name, self::SQLITE_CONNECTION));

            $this->assertFileDoesNotExist($path);
            $this->assertSame([], $manager->namesMatchingPrefix($prefix, self::SQLITE_CONNECTION));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_dropping_a_missing_sqlite_file_succeeds(): void
    {
        $this->useSqliteConnection();

        $manager = new StanclTenantDatabaseManager;

        $this->assertTrue($manager->dropDatabase('numerosis_absent_'.uniqid(), self::SQLITE_CONNECTION));
    }

    public function test_it_refuses_an_empty_name(): void
    {
        $this->assertFalse(new StanclTenantDatabaseManager()->dropDatabase(''));
    }

    private function skipWithoutDatabaseServer(): void
    {
        if (DB::connection($this->centralConnection())->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite has no server-side database catalogue.');
        }
    }

    private function useSqliteConnection(): void
    {
        config(['database.connections.'.self::SQLITE_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        DB::purge(self::SQLITE_CONNECTION);
    }

    private function createServerDatabase(string $name): void
    {
        DB::connection($this->centralConnection())->statement($this->quotedDatabaseStatement('CREATE DATABASE', $name));
    }

    private function dropServerDatabase(string $name): void
    {
        DB::connection($this->centralConnection())->statement($this->quotedDatabaseStatement('DROP DATABASE IF EXISTS', $name));
    }

    private function quotedDatabaseStatement(string $verb, string $name): string
    {
        return DB::connection($this->centralConnection())->getDriverName() === 'pgsql'
            ? $verb.' "'.str_replace('"', '""', $name).'"'
            : $verb.' `'.str_replace('`', '``', $name).'`';
    }

    private function centralConnection(): string
    {
        return config()->string('tenancy.database.central_connection', 'central');
    }
}

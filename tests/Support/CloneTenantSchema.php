<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDatabase;
use Nvade\Numerosis\Actions\Tenancy\MigrateTenantDatabase;
use Nvade\Numerosis\Actions\Tenancy\SeedTenantDatabase;
use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseManager;
use Nvade\Numerosis\Enums\Tenancy\DatabaseDriver;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Tests\TestCase;
use RuntimeException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Test-only replacement for MigrateDatabase + SeedTenantDatabase.
 *
 * Migrating and seeding a tenant database costs ~1.9s, and QUEUE_CONNECTION=sync
 * makes every Tenant creation pay it inline. Instead this migrates and seeds a
 * single template database once per process, then copies its structure and
 * seeded rows into each new tenant database.
 *
 * Only wired up from Tests\TestCase, which swaps it in for those two steps in
 * the configured list — production keeps the real pipeline.
 *
 * ## Why nothing here touches the default connection
 *
 * `RefreshDatabase` holds an open transaction on the default connection, and
 * MySQL implicitly commits on DDL. A single `CREATE TABLE` or `DROP DATABASE`
 * issued through the `DB` facade's default connection therefore ends that
 * transaction, and every test after it runs without isolation — which is how
 * the first attempt at this produced 117 unrelated failures. Every statement
 * below runs on the `central` connection or on a purpose-built connection
 * pointed at the tenant database, both of which have their own PDO session and
 * no test transaction. The migrate/seed jobs are safe for the same reason:
 * they work through stancl's separate `tenant` connection.
 */
class CloneTenantSchema implements ProvisioningStep
{
    /**
     * Tenant id of the template. The physical database name is this prefixed
     * with `tenancy.database.prefix`.
     *
     * Each parallel worker gets its own physical template because
     * `tenancy.database.prefix` carries the worker's token (see
     * `TestCase::parallelAwareTenantPrefix()`) — not because of anything here.
     * This id is identical in every worker.
     */
    public const TEMPLATE_ID = 'phpunittemplate';

    /**
     * Connection this class writes tenant databases through.
     *
     * @see connectionTo()
     */
    private const string CONNECTION = 'tenant_schema_clone';

    /**
     * Cached DDL and copyable column list per template table, so cloning costs
     * no INFORMATION_SCHEMA round trips after the first tenant.
     *
     * @var array<int, array{name: string, ddl: string, columns: string}>|null
     */
    private static ?array $blueprint = null;

    /**
     * Forget the cached template, e.g. after the testing database is rebuilt.
     */
    public static function flushTemplate(): void
    {
        self::$blueprint = null;
    }

    public static function templateDatabase(): string
    {
        return Config::string('tenancy.database.prefix', 'tenant').self::TEMPLATE_ID;
    }

    /**
     * Databases this process has cloned, so teardown can drop exactly those
     * instead of scanning INFORMATION_SCHEMA after every single test.
     *
     * @var array<int, string>
     */
    private static array $created = [];

    public function handle(TenantProvision $provision): void
    {
        $tenant = Numerosis::model(Tenant::class)::findOrFail($provision->slug);

        self::cloneFor($tenant);
    }

    /**
     * Also reached from the harness's TenantCreated listener, for the many
     * tests that create a tenant directly and only need a working database.
     */
    public static function cloneFor(TenantWithDatabase $tenant): void
    {
        $database = $tenant->database()->getName();

        throw_if($database === null, RuntimeException::class, 'Tenant database has no name.');

        // The harness clones on TenantCreated and the pipeline clones as a
        // step, so a tenant created through provisioning reaches here twice.
        if (self::hasTables($database)) {
            return;
        }

        self::copyDatabase(self::templateDatabase(), $database);

        self::$created[] = $database;
    }

    /**
     * @return array<int, string>
     */
    public static function takeCreatedDatabases(): array
    {
        $created = self::$created;

        self::$created = [];

        return $created;
    }

    /**
     * Build (once per process) a fully migrated and seeded tenant database to
     * copy from.
     *
     * The real pipeline jobs are invoked rather than reimplemented: the tenant
     * seeder resolves the `tenant` connection, which only exists once tenancy
     * has bootstrapped, so seeding it by hand does not work. They are called
     * through the container because their handle() methods take injected
     * dependencies.
     */
    private static function buildTemplate(): string
    {
        $database = self::templateDatabase();

        resolve(TenantDatabaseManager::class)->dropDatabase($database, self::centralConnectionName());

        // Tenant is abstract (see .claude/plans/archive/package-extraction.md Phase
        // 4.4) — forceCreate() calls `new static`, which late static binding
        // resolves to whatever class the call was written against. Written
        // as Tenant::forceCreate(...) that would be the abstract class
        // itself; going through the configured concrete class instead is
        // what a real request does via the tenant-model config key.
        /** @var class-string<Tenant> $tenantClass */
        $tenantClass = Config::string('tenancy.tenant_model');

        // withoutEvents stops the delete below from firing DeleteDatabase and
        // taking the template with it.
        $tenantClass::withoutEvents(fn () => $tenantClass::forceCreate(['id' => self::TEMPLATE_ID]));

        // Built through the real steps, on a throwaway provision row, so the
        // template is produced by the same code production runs.
        $provisionClass = Numerosis::model(TenantProvision::class);

        /** @var TenantProvision $provision */
        $provision = $provisionClass::forceCreate([
            'slug' => self::TEMPLATE_ID,
            'name' => 'Template',
            'global_id' => 'template',
        ]);

        resolve(CreateTenantDatabase::class)->handle($provision);
        resolve(MigrateTenantDatabase::class)->handle($provision);
        resolve(SeedTenantDatabase::class)->handle($provision);

        $provision->delete();

        /** @var Tenant $tenant */
        $tenant = $tenantClass::findOrFail(self::TEMPLATE_ID);

        // The row goes but the database stays: a surviving row would make
        // Tests\TestCase teardown delete the tenant, and the template with it.
        $tenantClass::withoutEvents(fn () => $tenant->delete());

        return $database;
    }

    private static function copyDatabase(string $from, string $to): void
    {
        match (TestCase::databaseDriver()) {
            DatabaseDriver::Sqlite => self::copyDatabaseFile($from, $to),
            DatabaseDriver::Pgsql => self::copyDatabaseFromTemplate($from, $to),
            default => self::replayTableDefinitions($from, $to),
        };
    }

    /**
     * One file per database, so the copy is the whole thing — foreign keys,
     * indexes and seeded rows included — with no DDL to replay.
     */
    private static function copyDatabaseFile(string $from, string $to): void
    {
        if (! self::databaseExists($from)) {
            self::buildTemplate();
        }

        copy(database_path($from), database_path($to));
    }

    /**
     * `CREATE DATABASE ... WITH TEMPLATE` is server-side and keeps the foreign
     * keys the MySQL path has to replay DDL to preserve. PostgreSQL refuses it
     * while another session is attached to the template, so the connection the
     * template was built through is purged first.
     */
    private static function copyDatabaseFromTemplate(string $from, string $to): void
    {
        if (! self::databaseExists($from)) {
            self::buildTemplate();
        }

        DB::purge(self::CONNECTION);
        DB::purge('tenant');

        self::central()->statement(sprintf(
            'CREATE DATABASE %s WITH TEMPLATE %s',
            self::quoted($to),
            self::quoted($from),
        ));
    }

    private static function replayTableDefinitions(string $from, string $to): void
    {
        $connection = self::connectionTo($to);

        // Template tables reference each other, so no creation order satisfies
        // every foreign key.
        $connection->statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (self::blueprint($from) as $table) {
                $connection->statement($table['ddl']);

                if ($table['columns'] === '') {
                    continue;
                }

                $connection->statement(
                    "INSERT INTO `{$table['name']}` ({$table['columns']}) SELECT {$table['columns']} FROM `{$from}`.`{$table['name']}`"
                );
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS = 1');

            DB::purge(self::CONNECTION);
        }
    }

    private static function quoted(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * A connection whose *default* database is the new tenant.
     *
     * The copied DDL names its foreign keys unqualified, so they resolve
     * against whatever database the connection is pointed at. Running it
     * anywhere else would silently attach the tenant's foreign keys to the
     * central testing database.
     */
    private static function connectionTo(string $database): Connection
    {
        /** @var array<string, mixed> $config */
        $config = config('database.connections.'.self::centralConnectionName());

        config(['database.connections.'.self::CONNECTION => [...$config, 'database' => $database]]);

        DB::purge(self::CONNECTION);

        return DB::connection(self::CONNECTION);
    }

    /**
     * @return array<int, array{name: string, ddl: string, columns: string}>
     */
    private static function blueprint(string $template): array
    {
        if (self::$blueprint !== null) {
            return self::$blueprint;
        }

        if (! self::databaseExists($template)) {
            self::buildTemplate();
        }

        $tables = self::central()->select(
            'SELECT TABLE_NAME AS name FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [$template, 'BASE TABLE'],
        );

        $blueprint = [];

        foreach ($tables as $table) {
            $name = (string) $table->name;

            $blueprint[] = [
                'name' => $name,
                'ddl' => self::createTableStatement($template, $name),
                'columns' => self::copyableColumns($template, $name),
            ];
        }

        return self::$blueprint = $blueprint;
    }

    /**
     * `SHOW CREATE TABLE` rather than `CREATE TABLE ... LIKE`: `LIKE` copies
     * columns and indexes but silently drops foreign keys, which would let
     * tenant databases behave differently under test than in production.
     */
    private static function createTableStatement(string $database, string $table): string
    {
        $row = (array) self::central()->selectOne("SHOW CREATE TABLE `{$database}`.`{$table}`");
        $statement = $row['Create Table'] ?? '';

        return is_string($statement) ? $statement : '';
    }

    /**
     * Generated columns cannot be inserted into, so they are excluded and left
     * to recompute themselves in the copy.
     */
    private static function copyableColumns(string $database, string $table): string
    {
        $columns = self::central()->select(
            "SELECT COLUMN_NAME AS name FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '')",
            [$database, $table],
        );

        return collect($columns)->map(fn ($column) => "`{$column->name}`")->implode(',');
    }

    /**
     * `information_schema.tables` is filtered by `table_schema`, which names a
     * database on MySQL and a schema on PostgreSQL — the same query there
     * answers about `public` and reports the wrong database's tables.
     */
    private static function hasTables(string $database): bool
    {
        if (! self::databaseExists($database)) {
            return false;
        }

        if (TestCase::databaseDriver() === DatabaseDriver::Sqlite) {
            return filesize(database_path($database)) > 0;
        }

        $connection = self::connectionTo($database);

        try {
            /** @var list<object{total: int}> $rows */
            $rows = TestCase::databaseDriver() === DatabaseDriver::Pgsql
                ? $connection->select("select count(*) as total from information_schema.tables where table_schema = 'public'")
                : $connection->select('select count(*) as total from information_schema.tables where table_schema = ?', [$database]);
        } finally {
            DB::purge(self::CONNECTION);
        }

        return ($rows[0]->total ?? 0) > 0;
    }

    private static function databaseExists(string $database): bool
    {
        return in_array(
            $database,
            resolve(TenantDatabaseManager::class)->namesMatchingPrefix($database, self::centralConnectionName()),
            true,
        );
    }

    private static function central(): Connection
    {
        return DB::connection(self::centralConnectionName());
    }

    private static function centralConnectionName(): string
    {
        return Config::string('tenancy.database.central_connection', 'central');
    }
}

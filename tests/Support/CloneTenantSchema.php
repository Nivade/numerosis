<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Nvade\Numerosis\Jobs\SeedTenantDatabase;
use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;

/**
 * Test-only replacement for MigrateDatabase + SeedTenantDatabase.
 *
 * Migrating and seeding a tenant database costs ~1.9s, and QUEUE_CONNECTION=sync
 * makes every Tenant creation pay it inline. Instead this migrates and seeds a
 * single template database once per process, then copies its structure and
 * seeded rows into each new tenant database.
 *
 * Only wired up from tests/Pest.php via TenancyServiceProvider::$tenantCreatedJobs
 * — production keeps the real pipeline.
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
class CloneTenantSchema implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tenant id of the template. The physical database name is this prefixed
     * with `tenancy.database.prefix`, so under `--parallel` each process gets
     * its own template without further wiring.
     */
    public const TEMPLATE_ID = 'phpunittemplate';

    /**
     * Connection this class writes tenant databases through.
     *
     * @see connectionTo()
     */
    private const CONNECTION = 'tenant_schema_clone';

    /**
     * Cached DDL and copyable column list per template table, so cloning costs
     * no INFORMATION_SCHEMA round trips after the first tenant.
     *
     * @var array<int, array{name: string, ddl: string, columns: string}>|null
     */
    private static ?array $blueprint = null;

    public function __construct(protected TenantWithDatabase $tenant) {}

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

    public function handle(): void
    {
        $database = $this->tenant->database()->getName();

        $this->copyDatabase(self::templateDatabase(), $database);

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

        self::central()->statement("DROP DATABASE IF EXISTS `{$database}`");

        // withoutEvents keeps this out of the TenantCreated pipeline that the
        // test bootstrap points at this very class, which would recurse. It
        // also stops the delete below from firing DeleteDatabase and taking
        // the template with it.
        $tenant = Tenant::withoutEvents(fn () => Tenant::forceCreate(['id' => self::TEMPLATE_ID]));

        app()->call([new CreateDatabase($tenant), 'handle']);
        app()->call([new MigrateDatabase($tenant), 'handle']);
        app()->call([new SeedTenantDatabase($tenant), 'handle']);

        // The row goes but the database stays: a surviving row would make
        // Tests\TestCase teardown delete the tenant, and the template with it.
        Tenant::withoutEvents(fn () => $tenant->delete());

        return $database;
    }

    private function copyDatabase(string $from, string $to): void
    {
        $connection = $this->connectionTo($to);

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

    /**
     * A connection whose *default* database is the new tenant.
     *
     * The copied DDL names its foreign keys unqualified, so they resolve
     * against whatever database the connection is pointed at. Running it
     * anywhere else would silently attach the tenant's foreign keys to the
     * central testing database.
     */
    private function connectionTo(string $database): Connection
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

    private static function databaseExists(string $database): bool
    {
        return self::central()->select(
            'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$database],
        ) !== [];
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

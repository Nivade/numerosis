<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Testing;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Override;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Throwable;

/**
 * Undoes the two kinds of write `RefreshDatabase` cannot roll back: rows on
 * the `central` connection, and the physical tenant databases an inline
 * `TenantCreated` pipeline creates. Ends tenancy and releases the test's own
 * transactions before deleting anything, so it works on either side of
 * `RefreshDatabase`'s rollback.
 */
trait CleansUpTenancyDatabases
{
    /**
     * Central tables this test wrote to, so teardown clears exactly those and
     * a new central-connection model needs no change here.
     *
     * @var array<string, true>
     */
    private array $dirtyCentralTables = [];

    private bool $tenancyCleanupRegistered = false;

    /**
     * The application the write tracking is currently listening on, so a
     * refresh can tell whether its listener is still attached.
     */
    private ?object $centralWritesRecordedFor = null;

    /**
     * A connection with its own PDO session, so the `DROP DATABASE` statements
     * cannot implicitly commit a transaction another connection holds.
     */
    private const string MAINTENANCE_CONNECTION = 'numerosis_tenancy_teardown';

    /**
     * Registers the teardown. Safe to call before or after `parent::setUp()`,
     * and safe to call twice.
     */
    protected function setUpCleansUpTenancyDatabases(): void
    {
        if ($this->tenancyCleanupRegistered) {
            return;
        }

        $this->tenancyCleanupRegistered = true;

        // Deferred, because this needs the application: called before
        // parent::setUp() there is no event dispatcher to listen on yet.
        $this->afterApplicationCreated(function (): void {
            $this->recordCentralWrites();
        });

        $this->beforeApplicationDestroyed(function (): void {
            $this->cleanUpTenancyDatabases();
        });
    }

    /**
     * Re-arms the central-write tracking against the rebuilt application.
     *
     * The tracking is a `DB::listen()` on the application it was registered
     * against, and refreshing builds a new one. Without this, every central
     * row written after a mid-test `refreshApplication()` goes untracked and
     * survives teardown, so the next test in that worker starts with a
     * populated `users` table — which reads as a flake, because it depends on
     * which test the runner happens to schedule next.
     */
    #[Override]
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        if ($this->tenancyCleanupRegistered && $this->centralWritesRecordedFor !== $this->app) {
            $this->recordCentralWrites();
        }
    }

    /**
     * Stops `RefreshDatabase` from scheduling a `migrate:fresh` before the next
     * test, which every tenancy test would otherwise trigger by losing its
     * transaction. Call after `parent::tearDown()`.
     *
     * @see self::shouldKeepDatabaseSchema() to opt out
     */
    protected function keepDatabaseSchema(): void
    {
        if ($this->shouldKeepDatabaseSchema()) {
            RefreshDatabaseState::$migrated = true;
        }
    }

    protected function shouldKeepDatabaseSchema(): bool
    {
        return true;
    }

    /**
     * Tenant databases this suite created outside the `tenants` table's own
     * knowledge: a test that fakes the queue and provisions by hand, or a
     * harness that swaps stancl's `TenantCreated` pipeline for a faster
     * stand-in. Names returned here are dropped in addition to the ones
     * derived from surviving tenant rows.
     *
     * @return list<string>
     */
    protected function additionalTenantDatabases(): array
    {
        return [];
    }

    /**
     * Tenant databases teardown must never drop, typically a template database
     * a clone-based speedup builds once per process.
     *
     * @return list<string>
     */
    protected function preservedTenantDatabases(): array
    {
        return [];
    }

    /**
     * Deletes central rows and drops tenant databases. Safe to call directly
     * from a suite with its own teardown ordering, and safe to re-run. Each
     * step keeps its own `finally`, and tenant database names are read before
     * the central deletes empty the `tenants` table.
     */
    protected function cleanUpTenancyDatabases(): void
    {
        if ($this->app === null) {
            return;
        }

        try {
            try {
                try {
                    $this->endTenancy();

                    $this->releaseTestTransactions();

                    $databases = $this->tenantDatabasesToDrop();

                    $this->deleteCentralWrites();
                } finally {
                    $this->dropTenantDatabases($databases ?? []);
                }
            } finally {
                $this->disconnectDatabaseConnections();
            }
        } finally {
            $this->dirtyCentralTables = [];
        }
    }

    /**
     * A test that ends inside tenant context leaves the default connection
     * pointed at the tenant database, the cache prefixed, and the auth guard
     * switched. Reverting first is what lets the rest of teardown, plus
     * `RefreshDatabase`'s rollback on whichever side of this it runs, reach the
     * central database and not one about to be dropped.
     */
    private function endTenancy(): void
    {
        if (! function_exists('tenancy')) {
            return;
        }

        try {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        } catch (Throwable) {
            // Best-effort: a half-bootstrapped tenancy must not stop the
            // deletes below, which are what keeps the next test isolated.
        }
    }

    /**
     * Rolls every connection back to level 0, so the central deletes below do
     * not block on row locks this test's own transaction still holds.
     */
    private function releaseTestTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            if (! $connection instanceof Connection || $connection->transactionLevel() === 0) {
                continue;
            }

            $dispatcher = $connection->getEventDispatcher();
            $connection->unsetEventDispatcher();

            try {
                $connection->rollBack(0);
            } catch (Throwable) {
                // An abandoned PDO session cannot be rolled back through the
                // connection object that no longer holds it; purging below
                // closes the socket, which makes MySQL roll it back instead.
            } finally {
                if ($dispatcher !== null) {
                    $connection->setEventDispatcher($dispatcher);
                }
            }
        }
    }

    /**
     * Notes every table written on the `central` connection. A
     * connection-level listener would be lost to the `DB::purge()` calls
     * tenancy makes mid-test, so this one sits on the event dispatcher.
     */
    private function recordCentralWrites(): void
    {
        $this->centralWritesRecordedFor = $this->app;

        $central = $this->centralConnectionName();

        DB::listen(function (QueryExecuted $query) use ($central): void {
            if ($query->connectionName !== $central) {
                return;
            }

            if (preg_match('/^\s*(?:insert(?:\s+ignore)?\s+into|replace\s+into|update)\s+`?([\w-]+)`?/i', $query->sql, $matches) !== 1) {
                return;
            }

            $this->dirtyCentralTables[$matches[1]] = true;
        });
    }

    /**
     * Transacting `central` is not an option: stancl's database manager issues
     * its `CREATE DATABASE` on that connection, and MySQL implicitly commits on
     * DDL, so any test creating a tenant loses the transaction mid-test.
     */
    private function deleteCentralWrites(): void
    {
        if ($this->dirtyCentralTables === []) {
            return;
        }

        $connection = DB::connection($this->centralConnectionName());

        // Deleting in dependency order would mean tracking relationships
        // between the tables; the rows are all going regardless.
        $foreignKeysDisabled = $this->withoutForeignKeyChecks($connection, false);

        try {
            foreach (array_keys($this->dirtyCentralTables) as $table) {
                $connection->table($table)->delete();
            }
        } finally {
            if ($foreignKeysDisabled) {
                $this->withoutForeignKeyChecks($connection, true);
            }

            $this->dirtyCentralTables = [];
        }
    }

    /**
     * Names of the tenant databases teardown should drop, read while the
     * `tenants` table still has rows. Deletes the rows too: the physical
     * databases go with them, so leaving them behind would advertise tenants
     * whose data no longer exists.
     *
     * @return list<string>
     */
    private function tenantDatabasesToDrop(): array
    {
        $databases = $this->additionalTenantDatabases();

        $central = $this->centralConnectionName();

        if (Schema::connection($central)->hasTable('tenants')) {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $tenantClass */
            $tenantClass = Config::string('tenancy.tenant_model');

            $tenants = $tenantClass::query()->get();

            $tenantClass::query()->delete();

            foreach ($tenants as $tenant) {
                // getName() honours a per-tenant database name stored on the
                // tenant itself; the prefix is only the fallback shape, for a
                // tenant model that isn't one of stancl's database tenants.
                if ($tenant instanceof TenantWithDatabase) {
                    $databases[] = $tenant->database()->getName();

                    continue;
                }

                $key = $tenant->getKey();

                if (is_string($key) || is_int($key)) {
                    $databases[] = Config::string('tenancy.database.prefix', 'tenant').$key;
                }
            }
        }

        $preserved = $this->preservedTenantDatabases();

        return array_values(array_filter(
            array_unique(array_filter($databases, is_string(...))),
            fn (string $database): bool => $database !== '' && ! in_array($database, $preserved, true),
        ));
    }

    /**
     * Drops on the maintenance connection, never the default one, and without
     * stancl's `TenantDeleted` listener.
     *
     * @param  list<string>  $databases
     */
    private function dropTenantDatabases(array $databases): void
    {
        if ($databases === []) {
            return;
        }

        $connection = $this->maintenanceConnection();

        try {
            foreach ($databases as $database) {
                $name = str_replace('`', '``', $database);

                $connection->statement("DROP DATABASE IF EXISTS `{$name}`");
            }
        } finally {
            DB::purge(self::MAINTENANCE_CONNECTION);
        }
    }

    /**
     * Closes every named connection's PDO object, so MySQL rolls back and
     * frees the locks held by sessions tenancy abandoned mid-test.
     */
    private function disconnectDatabaseConnections(): void
    {
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge((string) $name);
        }
    }

    private function maintenanceConnection(): Connection
    {
        /** @var array<string, mixed> $config */
        $config = Config::array('database.connections.'.$this->centralConnectionName());

        Config::set('database.connections.'.self::MAINTENANCE_CONNECTION, $config);

        DB::purge(self::MAINTENANCE_CONNECTION);

        return DB::connection(self::MAINTENANCE_CONNECTION);
    }

    /**
     * MySQL only. Returns whether the statement was accepted, so the caller
     * does not re-enable something it never disabled on another driver.
     */
    private function withoutForeignKeyChecks(Connection $connection, bool $enabled): bool
    {
        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        $connection->statement('SET FOREIGN_KEY_CHECKS = '.($enabled ? '1' : '0'));

        return true;
    }

    private function centralConnectionName(): string
    {
        return Config::string('tenancy.database.central_connection', 'central');
    }
}

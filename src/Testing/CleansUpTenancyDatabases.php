<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Testing;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Throwable;

/**
 * Undoes the two kinds of write `RefreshDatabase` cannot roll back in a
 * tenancy test suite: rows written through the `central` connection, and
 * physical tenant databases.
 *
 * `RefreshDatabase` transacts the default connection only. Every model using
 * stancl's `CentralConnection` trait (`Tenant`, `Domain`, `CentralUser`, and
 * any subclass of them) writes on a session it never opened a transaction on,
 * so those rows survive into the next test and collide on unique keys —
 * `users.email`, `tenants.id`, `subscriptions.stripe_id`. Creating a `Tenant`
 * with `QUEUE_CONNECTION=sync` also runs the `TenantCreated` pipeline inline,
 * which issues a real `CREATE DATABASE`; DDL is not transactional, so that
 * database outlives the rollback too.
 *
 * Compose this into your base test case and call
 * {@see self::setUpCleansUpTenancyDatabases()} from `setUp()`:
 *
 * ```php
 * protected function setUp(): void
 * {
 *     $this->setUpCleansUpTenancyDatabases();
 *
 *     parent::setUp();
 * }
 *
 * protected function tearDown(): void
 * {
 *     parent::tearDown();
 *
 *     $this->keepDatabaseSchema();
 * }
 * ```
 *
 * Under Orchestra Testbench the `setUp…()` call is optional — Testbench calls
 * `setUp{TraitName}` for every composed trait itself — and calling it anyway
 * is a no-op, so one base class can be copied between a Testbench harness and
 * a plain-Laravel host.
 *
 * **Cleanup does not depend on running after `RefreshDatabase`'s rollback**,
 * which is the part hosts get wrong: `beforeApplicationDestroyed()` appends on
 * `Illuminate\Foundation\Testing\TestCase` (last-registered runs last) and
 * `array_unshift`es on Testbench's `ApplicationTestingHooks` (last-registered
 * runs *first*), so the same registration lands on opposite sides of that
 * rollback depending on the base class. Instead of guessing, this trait ends
 * tenancy and releases the test's own transactions itself before deleting
 * anything — the two things being after the rollback would otherwise buy
 * (locks released, and the default connection no longer pointed at a tenant
 * database that is about to be dropped).
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
     * Stops `RefreshDatabase` from scheduling a `migrate:fresh` before the
     * next test. Call after `parent::tearDown()`.
     *
     * `RefreshDatabase` clears its migrated flag whenever a test's transaction
     * is gone by teardown, and every tenancy test trips that: stancl's
     * `DatabaseTenancyBootstrapper` purges the default connection when it
     * switches to a tenant database, so `getPdo()` hands back a fresh session
     * that was never in the transaction. Nothing in a normal suite issues DDL
     * against the central schema, so the rebuild only ever restores what is
     * already there — at seconds per test. Rows a lost transaction committed
     * are the real risk, and {@see self::deleteCentralWrites()} handles those.
     *
     * Override {@see self::shouldKeepDatabaseSchema()} to opt out.
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
     * knowledge — a test that fakes the queue and provisions by hand, or a
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
     * Tenant databases teardown must never drop — a template database a
     * clone-based speedup builds once per process, typically.
     *
     * @return list<string>
     */
    protected function preservedTenantDatabases(): array
    {
        return [];
    }

    /**
     * Each step gets its own `finally`, because the exception these guards
     * exist for (a lock-wait timeout on the central deletes) is thrown by an
     * early step — sharing one `try` would skip exactly the cleanup that
     * matters most, leaking a physical database per occurrence.
     *
     * Tenant database *names* are read before the central deletes rather than
     * after: `deleteCentralWrites()` empties `tenants` along with every other
     * table written on that connection, so a lookup afterwards finds nothing
     * and silently drops none of them.
     *
     * Protected rather than private so a test can invoke it deliberately, and
     * so a suite with its own teardown ordering can call it from there instead
     * of through {@see self::setUpCleansUpTenancyDatabases()}. Re-running it is
     * harmless.
     */
    protected function cleanUpTenancyDatabases(): void
    {
        if (! $this->app) {
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
     * switched. Reverting first is what lets the rest of teardown — and
     * `RefreshDatabase`'s rollback, whichever side of this it runs on — reach
     * the central database rather than one that is about to be dropped.
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
     * Rolls back and closes every open transaction this test left behind.
     *
     * The central deletes below block for the full `innodb_lock_wait_timeout`
     * while the test's own transaction still holds row locks on the same
     * tables — `central` and the default connection usually point at one
     * physical database. `RefreshDatabase`'s rollback does this too, but only
     * for the connections it transacts, and only if it happens to run first.
     * Rolling back to level 0 makes its own later `rollBack()` a no-op (it
     * returns early once the transaction level is 0) rather than a conflict.
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
     * Notes every table written on the `central` connection. The listener sits
     * on the event dispatcher rather than on the connection, so it survives
     * the `DB::purge()` calls tenancy makes mid-test.
     */
    private function recordCentralWrites(): void
    {
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
     * Transacting `central` instead of deleting is not an option: stancl's
     * database manager issues its `CREATE DATABASE` on that connection, and
     * MySQL implicitly commits on DDL, so any test creating a tenant would
     * lose the transaction mid-test anyway.
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
     * The `DROP` must not go through the default connection: MySQL implicitly
     * commits on DDL, so it would end the `RefreshDatabase` transaction and
     * commit everything the test wrote.
     *
     * Issued directly rather than through stancl's `TenantDeleted` ->
     * `DeleteDatabase` listener, because `Tenant::unsetEventDispatcher()` is
     * static: one test calling it silences model events for every later test
     * in the process, and their databases would then never be dropped.
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
     * Closes every named connection's PDO object at the end of each test.
     *
     * `DatabaseTenancyBootstrapper` purges the default connection's PDO object
     * whenever tenancy switches context, mid-test, with no COMMIT/ROLLBACK on
     * the connection being replaced. If that connection was inside
     * `RefreshDatabase`'s open transaction, the abandoned PDO object's MySQL
     * session is never closed by Laravel, so it never triggers the
     * server-side rollback a clean disconnect would — it sits idle holding
     * whatever locks its last statement took, for the rest of the process.
     * Dropping the last PHP reference to each PDO object closes the socket and
     * lets MySQL roll back and free those locks itself.
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

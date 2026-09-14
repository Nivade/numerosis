<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MySQL's default `lock_wait_timeout` (metadata/DDL locks) is 31536000
 * seconds — a year. `innodb_lock_wait_timeout` (ordinary row/FK locks, e.g. a
 * blocked `DELETE`) is a *separate* session variable with its own default —
 * 50 seconds, not a year, but still long enough to blow up a full run many
 * times over, and the two were not always set together: an earlier version
 * of this bound only set `lock_wait_timeout`, so every DML wait (which is
 * most of the suite's lock contention) silently rode MySQL's 50s default
 * instead of the intended 10s, and this test only ever asserted the metadata
 * half — the DML half had no coverage at all. Left uncovered, a statement
 * that collides with another session's lock does not fail, it hangs (or, for
 * the DML case, just takes five times longer than it should), and every
 * later request for that table queues behind it. In the suite that collision
 * is always a bug (a stranded RefreshDatabase transaction on a purged
 * connection, a second run against the same schema), and a hang is the worst
 * possible way to report it: nothing fails, nothing finishes, and the
 * container keeps the session open long after the run is killed.
 *
 * See `.ai/rules/testing.md` for the incidents this prevents.
 */
class LockWaitTimeoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{string, string}>
     */
    public static function connectionsAndVariables(): array
    {
        return [
            ['mysql', 'lock_wait_timeout'],
            ['mysql', 'innodb_lock_wait_timeout'],
            ['central', 'lock_wait_timeout'],
            ['central', 'innodb_lock_wait_timeout'],
        ];
    }

    #[DataProvider('connectionsAndVariables')]
    public function test_the_test_connections_bound_their_lock_wait(string $connection, string $variable): void
    {
        $row = DB::connection($connection)->selectOne("SELECT @@session.{$variable} AS timeout");
        $this->assertIsObject($row);
        $value = get_object_vars($row)['timeout'];
        $timeout = is_numeric($value) ? (int) $value : 0;

        $this->assertSame(
            Config::integer('database.lock_wait_timeout'),
            $timeout,
            "The {$connection} connection did not apply DB_LOCK_WAIT_TIMEOUT to {$variable}, so a "
            .'lock collision will wait far longer than intended instead of failing fast.'
        );
    }

    /**
     * The tenant connection is built by merging the central connection's
     * config (`DatabaseConfig::connection()`), so it inherits these options
     * rather than declaring them. That inheritance is the part worth pinning:
     * tenant databases are where the DDL that collided actually runs.
     */
    #[DataProvider('variables')]
    public function test_the_tenant_connection_inherits_the_bound(string $variable): void
    {
        $tenant = TestTenant::provisioned(['id' => 'lock-wait-'.uniqid()]);

        $tenant->run(function () use ($variable): void {
            $row = DB::connection('tenant')->selectOne("SELECT @@session.{$variable} AS timeout");
            $this->assertIsObject($row);
            $value = get_object_vars($row)['timeout'];
            $timeout = is_numeric($value) ? (int) $value : 0;

            $this->assertSame(Config::integer('database.lock_wait_timeout'), $timeout);
        });
    }

    /**
     * @return list<array{string}>
     */
    public static function variables(): array
    {
        return [['lock_wait_timeout'], ['innodb_lock_wait_timeout']];
    }

    /**
     * The bound is only useful if it is short enough that a human notices the
     * failure in the same sitting, rather than a "shorter than a year" that is
     * still longer than the run.
     */
    public function test_the_bound_is_short_enough_to_be_useful(): void
    {
        $timeout = Config::integer('database.lock_wait_timeout');

        $this->assertGreaterThan(0, $timeout);
        $this->assertLessThanOrEqual(60, $timeout);
    }
}

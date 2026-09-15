<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Decides whether a tenant's database still needs creating, which jobs create
 * it, and how a database is listed or dropped by name alone.
 *
 * Point `numerosis.tenancy.implementations` at your own class to provision onto
 * separate database servers or regions, or to use a database driver of your
 * own, without reaching into stancl/tenancy internals.
 */
interface TenantDatabaseManager
{
    public function databaseExists(TenantWithDatabase $tenant): bool;

    /**
     * Databases whose name starts with `$prefix`, including any with no
     * matching tenant row.
     *
     * @return list<string>
     */
    public function namesMatchingPrefix(string $prefix, ?string $connection = null): array;

    /**
     * `$connection` names the connection the statement is issued on. A caller
     * inside an open transaction must pass one of its own: MySQL commits
     * implicitly on DDL, so a drop on a connection another transaction holds
     * ends that transaction.
     */
    public function dropDatabase(string $name, ?string $connection = null): bool;
}

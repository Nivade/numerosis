<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class StanclTenantDatabaseManager implements TenantDatabaseManager
{
    public function databaseExists(TenantWithDatabase $tenant): bool
    {
        $database = $tenant->database()->getName();

        return $database !== null && $database !== '' && $tenant->database()->manager()->databaseExists($database);
    }

    /**
     * @return list<string>
     */
    public function namesMatchingPrefix(string $prefix, ?string $connection = null): array
    {
        $connection = $this->connection($connection);

        if ($connection->getDriverName() === 'sqlite') {
            return $this->fileNamesMatchingPrefix($prefix);
        }

        // pg_database, not information_schema.schemata: on PostgreSQL that
        // view lists schemas, so the query succeeds and finds no tenant
        // database at all.
        $sql = $connection->getDriverName() === 'pgsql'
            ? 'SELECT datname AS name FROM pg_database WHERE datname LIKE ?'
            : 'SELECT SCHEMA_NAME AS name FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE ?';

        $names = [];

        foreach ($connection->select($sql, [$prefix.'%']) as $row) {
            $name = ((array) $row)['name'] ?? null;

            // Anything unrecognised falls out: the caller drops what this
            // returns.
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function dropDatabase(string $name, ?string $connection = null): bool
    {
        if ($name === '') {
            return false;
        }

        $connection = $this->connection($connection);

        return match ($connection->getDriverName()) {
            'sqlite' => $this->unlinkDatabaseFile($name),
            'pgsql' => $connection->statement(
                // WITH (FORCE) terminates sessions still attached; without it
                // PostgreSQL refuses the drop while any connection remains.
                'DROP DATABASE IF EXISTS "'.str_replace('"', '""', $name).'" WITH (FORCE)'
            ),
            default => $connection->statement(
                'DROP DATABASE IF EXISTS `'.str_replace('`', '``', $name).'`'
            ),
        };
    }

    /**
     * @return list<string>
     */
    private function fileNamesMatchingPrefix(string $prefix): array
    {
        $matches = glob(database_path($prefix.'*'));

        if ($matches === false) {
            return [];
        }

        return array_values(array_map(
            basename(...),
            array_filter($matches, is_file(...)),
        ));
    }

    private function unlinkDatabaseFile(string $name): bool
    {
        $path = database_path(basename($name));

        return ! is_file($path) || unlink($path);
    }

    private function connection(?string $connection): Connection
    {
        $name = $connection ?? Config::string('tenancy.database.central_connection', 'central');

        return DB::connection($name);
    }
}

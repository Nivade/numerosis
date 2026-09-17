<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseDumper;
use Nvade\Numerosis\Enums\Tenancy\DatabaseDriver;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * PostgreSQL artefacts carry rows only, since only the other three drivers can
 * hand back their own `CREATE TABLE` statements. Point
 * `numerosis.tenancy.backup.dumpers` at `pg_dump` when schema is needed.
 */
class PortableTenantDatabaseDumper implements TenantDatabaseDumper
{
    private const int CHUNK = 500;

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function carriesSchema(): bool
    {
        return $this->driver() !== DatabaseDriver::Pgsql;
    }

    public function dump(TenantWithDatabase $tenant, string $file, int $chunk = self::CHUNK): void
    {
        $handle = fopen($file, 'wb');

        if ($handle === false) {
            throw TenantBackupFailed::unwritable($file);
        }

        try {
            $this->model($tenant)->runHere(function () use ($handle, $chunk): void {
                $connection = DB::connection();
                $tables = $this->tables($connection);

                $this->writeLine($handle, [
                    'version' => 1,
                    'driver' => $this->driver()->value,
                    'tables' => $tables,
                    'schema' => $this->carriesSchema() ? $this->schema($connection, $tables) : [],
                    'chunk' => $chunk,
                ]);

                foreach ($tables as $table) {
                    foreach ($connection->table($table)->cursor() as $row) {
                        $this->writeLine($handle, ['t' => $table, 'r' => (array) $row]);
                    }
                }
            });
        } finally {
            fclose($handle);
        }
    }

    public function restore(TenantWithDatabase $tenant, string $file): void
    {
        $lines = $this->read($file);
        $header = $lines->current();

        if (! is_array($header) || ! isset($header['tables'])) {
            throw TenantBackupFailed::unreadable($file);
        }

        /** @var list<string> $tables */
        $tables = array_values(array_filter((array) $header['tables'], is_string(...)));

        /** @var array<string, string> $schema */
        $schema = array_filter((array) ($header['schema'] ?? []), is_string(...));

        $chunk = is_int($header['chunk'] ?? null) ? $header['chunk'] : self::CHUNK;

        $this->model($tenant)->runHere(function () use ($lines, $tables, $schema, $chunk): void {
            $connection = DB::connection();

            $this->withoutForeignKeys($connection, function () use ($connection, $lines, $tables, $schema, $chunk): void {
                $this->prepareTables($connection, $tables, $schema);

                $buffer = [];

                // Stepped by hand rather than with foreach, which rewinds a
                // generator whose header line has already been read.
                for ($lines->next(); $lines->valid(); $lines->next()) {
                    $line = $lines->current();

                    if (! isset($line['t'], $line['r'])) {
                        continue;
                    }

                    $table = is_string($line['t']) ? $line['t'] : '';
                    $buffer[$table][] = (array) $line['r'];

                    if (count($buffer[$table]) >= $chunk) {
                        $connection->table($table)->insert($buffer[$table]);
                        $buffer[$table] = [];
                    }
                }

                foreach ($buffer as $table => $rows) {
                    if ($rows !== []) {
                        $connection->table($table)->insert($rows);
                    }
                }
            });
        });
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, string>  $schema
     */
    private function prepareTables(Connection $connection, array $tables, array $schema): void
    {
        foreach ($tables as $table) {
            if ($schema === []) {
                $connection->table($table)->delete();

                continue;
            }

            $connection->statement($this->dropStatement($table));
            $connection->statement($schema[$table] ?? throw TenantBackupFailed::missingSchema($table));
        }
    }

    private function dropStatement(string $table): string
    {
        return $this->driver() === DatabaseDriver::Sqlite
            ? 'drop table if exists "'.$table.'"'
            : 'drop table if exists `'.$table.'`';
    }

    private function withoutForeignKeys(Connection $connection, callable $work): void
    {
        $driver = $this->driver();

        match ($driver) {
            DatabaseDriver::Sqlite => $connection->statement('pragma foreign_keys = off'),
            DatabaseDriver::Pgsql => null,
            default => $connection->statement('set foreign_key_checks = 0'),
        };

        try {
            $work();
        } finally {
            match ($driver) {
                DatabaseDriver::Sqlite => $connection->statement('pragma foreign_keys = on'),
                DatabaseDriver::Pgsql => null,
                default => $connection->statement('set foreign_key_checks = 1'),
            };
        }
    }

    /**
     * @return list<string>
     */
    private function tables(Connection $connection): array
    {
        // Scoped to the tenant's own database: MySQL's schema builder lists
        // every schema the connection can see otherwise, central included.
        $names = array_map(
            static fn (array $table): string => (string) ($table['name'] ?? ''),
            $connection->getSchemaBuilder()->getTables($connection->getDatabaseName())
        );

        $names = array_values(array_filter($names, static fn (string $name): bool => $name !== ''));

        sort($names);

        return $names;
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, string>
     */
    private function schema(Connection $connection, array $tables): array
    {
        $statements = [];

        foreach ($tables as $table) {
            $statement = $this->driver() === DatabaseDriver::Sqlite
                ? $connection->scalar('select sql from sqlite_master where type = ? and name = ?', ['table', $table])
                : ((array) $connection->selectOne('show create table `'.$table.'`'))['Create Table'] ?? '';

            $statements[$table] = is_string($statement) ? $statement : '';
        }

        return $statements;
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $payload
     */
    private function writeLine($handle, array $payload): void
    {
        fwrite($handle, json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function read(string $file): Generator
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw TenantBackupFailed::unreadable($file);
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode(trim($line), true);

                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    yield $decoded;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function driver(): DatabaseDriver
    {
        return DatabaseDriver::from(Config::string('database.connections.tenant.driver', 'mysql'));
    }

    /**
     * The package's own model, which carries `runHere()`. stancl's own
     * `run()` leaves tenancy initialized when its callback throws.
     */
    private function model(TenantWithDatabase $tenant): Tenant
    {
        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        $model = Numerosis::model(Tenant::class)::query()->findOrFail($tenant->getTenantKey());

        return $model instanceof Tenant ? $model : throw TenantBackupFailed::artefactMissing((string) $tenant->getTenantKey());
    }
}

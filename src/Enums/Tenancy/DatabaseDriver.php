<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

enum DatabaseDriver: string
{
    case Mysql = 'mysql';
    case Mariadb = 'mariadb';
    case Pgsql = 'pgsql';
    case Sqlite = 'sqlite';

    /**
     * SQLite allows one writer per database file. Every tenant gets its own
     * file, but the central database does not split: it carries every
     * tenant's users, subscriptions, provision rows and the `jobs` table.
     */
    public function allowsConcurrentWriters(): bool
    {
        return $this !== self::Sqlite;
    }

    public function warning(): ?string
    {
        return $this->allowsConcurrentWriters()
            ? null
            : 'SQLite allows one writer per database file, and the central database is shared by every tenant, so concurrent signups serialize behind each other. Supported for local development and small deploys, not for production traffic.';
    }

    /** Only these two can hand back their own `CREATE TABLE` statements. */
    public function carriesSchema(): bool
    {
        return $this !== self::Pgsql;
    }

    /** What `getTables()` wants: MySQL names the database, the other two name a schema inside it. */
    public function schemaName(string $database): string
    {
        return match ($this) {
            self::Sqlite => 'main',
            self::Pgsql => 'public',
            default => $database,
        };
    }

    public function quotedIdentifier(string $name): string
    {
        return $this === self::Sqlite ? '"'.$name.'"' : '`'.$name.'`';
    }

    public function disableForeignKeysStatement(): ?string
    {
        return match ($this) {
            self::Sqlite => 'pragma foreign_keys = off',
            self::Pgsql => null,
            default => 'set foreign_key_checks = 0',
        };
    }

    public function enableForeignKeysStatement(): ?string
    {
        return match ($this) {
            self::Sqlite => 'pragma foreign_keys = on',
            self::Pgsql => null,
            default => 'set foreign_key_checks = 1',
        };
    }
}

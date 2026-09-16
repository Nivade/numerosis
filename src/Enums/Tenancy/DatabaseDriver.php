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
}

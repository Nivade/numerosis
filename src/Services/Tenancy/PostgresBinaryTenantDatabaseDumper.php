<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Illuminate\Support\Facades\Config;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * `pg_dump`/`psql`. The only dumper that carries a PostgreSQL schema: the
 * portable one reads rows through PDO and cannot reproduce PostgreSQL's DDL.
 */
class PostgresBinaryTenantDatabaseDumper extends BinaryTenantDatabaseDumper
{
    public function __construct(string $dumpBinary = 'pg_dump', string $restoreBinary = 'psql')
    {
        parent::__construct($dumpBinary, $restoreBinary);
    }

    public function dump(TenantWithDatabase $tenant, string $file, int $chunk = 500): void
    {
        $this->run([$this->dumpBinary, '--clean', '--if-exists', '--no-owner', $this->connectionString($tenant)], $file, write: true);
    }

    public function restore(TenantWithDatabase $tenant, string $file): void
    {
        $this->run([$this->restoreBinary, '--quiet', $this->connectionString($tenant)], $file, write: false);
    }

    /**
     * A URI keeps the password out of `ps`, which a `--password` flag would
     * not.
     */
    private function connectionString(TenantWithDatabase $tenant): string
    {
        return sprintf(
            'postgresql://%s:%s@%s:%s/%s',
            rawurlencode(Config::string('database.connections.tenant.username', 'postgres')),
            rawurlencode(Config::string('database.connections.tenant.password', '')),
            Config::string('database.connections.tenant.host', '127.0.0.1'),
            Config::string('database.connections.tenant.port', '5432'),
            (string) $tenant->database()->getName(),
        );
    }
}

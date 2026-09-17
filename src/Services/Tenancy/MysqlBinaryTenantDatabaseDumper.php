<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Illuminate\Support\Facades\Config;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * `mysqldump`/`mysql`, for an installation large enough that reading a tenant
 * database through PDO is too slow. The credentials go over a temporary
 * defaults file, since a command line is world-readable in `ps`.
 */
class MysqlBinaryTenantDatabaseDumper extends BinaryTenantDatabaseDumper
{
    public function __construct(string $dumpBinary = 'mysqldump', string $restoreBinary = 'mysql')
    {
        parent::__construct($dumpBinary, $restoreBinary);
    }

    public function dump(TenantWithDatabase $tenant, string $file, int $chunk = 500): void
    {
        $this->runWithDefaults(
            [$this->dumpBinary, '--single-transaction', '--quick', '--routines', $this->database($tenant)],
            $file,
            write: true,
        );
    }

    public function restore(TenantWithDatabase $tenant, string $file): void
    {
        $this->runWithDefaults([$this->restoreBinary, $this->database($tenant)], $file, write: false);
    }

    /**
     * @param  list<string>  $command
     */
    private function runWithDefaults(array $command, string $file, bool $write): void
    {
        $defaults = $this->defaultsFile();

        try {
            array_splice($command, 1, 0, ['--defaults-extra-file='.$defaults]);

            $this->run($command, $file, $write);
        } finally {
            @unlink($defaults);
        }
    }

    private function defaultsFile(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'numerosis-my');

        file_put_contents($path, implode("\n", [
            '[client]',
            'host='.Config::string('database.connections.tenant.host', '127.0.0.1'),
            'port='.Config::string('database.connections.tenant.port', '3306'),
            'user='.Config::string('database.connections.tenant.username', 'root'),
            'password='.Config::string('database.connections.tenant.password', ''),
            '',
        ]));

        chmod($path, 0600);

        return $path;
    }

    private function database(TenantWithDatabase $tenant): string
    {
        return (string) $tenant->database()->getName();
    }
}

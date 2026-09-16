<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseDumper;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Symfony\Component\Process\Process;

/**
 * `pg_dump`/`psql`. The only dumper that carries a PostgreSQL schema: the
 * portable one reads rows through PDO and has no way to reproduce
 * PostgreSQL's DDL, so a portable artefact restores into a migrated database
 * only.
 */
class PostgresBinaryTenantDatabaseDumper implements TenantDatabaseDumper
{
    public function __construct(
        private readonly string $dumpBinary = 'pg_dump',
        private readonly string $restoreBinary = 'psql',
    ) {}

    public function isAvailable(): bool
    {
        return $this->unavailableReason() === null;
    }

    public function unavailableReason(): ?string
    {
        foreach ([$this->dumpBinary, $this->restoreBinary] as $binary) {
            if (new Process(['sh', '-c', 'command -v '.$binary])->run() !== 0) {
                return "`{$binary}` is not on PATH.";
            }
        }

        return null;
    }

    public function carriesSchema(): bool
    {
        return true;
    }

    public function dump(TenantWithDatabase $tenant, string $file): void
    {
        $this->run([$this->dumpBinary, '--clean', '--if-exists', '--no-owner', $this->connectionString($tenant)], $file, write: true);
    }

    public function restore(TenantWithDatabase $tenant, string $file): void
    {
        $this->run([$this->restoreBinary, '--quiet', $this->connectionString($tenant)], $file, write: false);
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, string $file, bool $write): void
    {
        $process = Process::fromShellCommandline(
            implode(' ', array_map(escapeshellarg(...), $command)).($write ? ' > ' : ' < ').escapeshellarg($file)
        );

        $process->setTimeout(Config::integer('numerosis.tenancy.backup.timeout', 900))->run();

        if (! $process->isSuccessful()) {
            throw TenantBackupFailed::dumperUnavailable(trim($process->getErrorOutput()));
        }
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

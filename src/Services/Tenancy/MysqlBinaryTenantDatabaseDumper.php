<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseDumper;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Symfony\Component\Process\Process;

/**
 * `mysqldump`/`mysql`, for an installation large enough that reading a tenant
 * database through PDO is too slow. Opt in through
 * `numerosis.tenancy.backup.dumpers`; the credentials go over a temporary
 * defaults file rather than the command line, which is world-readable in `ps`.
 */
class MysqlBinaryTenantDatabaseDumper implements TenantDatabaseDumper
{
    public function __construct(
        private readonly string $dumpBinary = 'mysqldump',
        private readonly string $restoreBinary = 'mysql',
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

            $process = Process::fromShellCommandline(
                implode(' ', array_map(escapeshellarg(...), $command)).($write ? ' > ' : ' < ').escapeshellarg($file)
            );

            $process->setTimeout(Config::integer('numerosis.tenancy.backup.timeout', 900))->run();

            if (! $process->isSuccessful()) {
                throw TenantBackupFailed::dumperUnavailable(trim($process->getErrorOutput()));
            }
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

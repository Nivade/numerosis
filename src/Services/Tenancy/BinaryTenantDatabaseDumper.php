<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseDumper;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;
use Symfony\Component\Process\Process;

/**
 * Shared by the dumpers that shell out to a vendor binary: the PATH probe and
 * the redirect, whose quoting is the part worth getting right once.
 */
abstract class BinaryTenantDatabaseDumper implements TenantDatabaseDumper
{
    public function __construct(
        protected readonly string $dumpBinary,
        protected readonly string $restoreBinary,
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

    /**
     * @param  list<string>  $command
     */
    protected function run(array $command, string $file, bool $write): void
    {
        $process = Process::fromShellCommandline(
            implode(' ', array_map(escapeshellarg(...), $command)).($write ? ' > ' : ' < ').escapeshellarg($file)
        );

        $process->setTimeout(Config::integer('numerosis.tenancy.backup.timeout', 900))->run();

        if (! $process->isSuccessful()) {
            throw TenantBackupFailed::dumperUnavailable(trim($process->getErrorOutput()));
        }
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\EncryptsArtifacts;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseDumper;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * One physical snapshot of one tenant database, written to
 * `numerosis.tenancy.backup.disk` and encrypted unless the host turned that
 * off. Returns the artefact's path on that disk.
 *
 * @method static string run(Tenant $tenant, ?string $disk = null)
 */
class BackupTenant
{
    use AsAction;

    public function __construct(
        private readonly TenantDatabaseDumper $dumper,
        private readonly EncryptsArtifacts $cipher,
    ) {}

    public function handle(Tenant $tenant, ?string $disk = null): string
    {
        if (! $this->dumper->isAvailable()) {
            throw TenantBackupFailed::dumperUnavailable((string) $this->dumper->unavailableReason());
        }

        $working = (string) tempnam(sys_get_temp_dir(), 'numerosis-dump');

        try {
            $this->dumper->dump($tenant, $working);

            $path = $this->path($tenant);

            if (Config::boolean('numerosis.tenancy.backup.encrypt', true)) {
                $encrypted = $working.'.enc';

                try {
                    $this->cipher->encrypt($working, $encrypted);

                    $this->put($disk, $path, $encrypted);
                } finally {
                    @unlink($encrypted);
                }
            } else {
                $this->put($disk, $path, $working);
            }

            return $path;
        } finally {
            @unlink($working);
        }
    }

    /**
     * Streamed rather than read into a string: an artefact is the size of the
     * tenant's database.
     */
    private function put(?string $disk, string $path, string $file): void
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw TenantBackupFailed::unreadable($file);
        }

        try {
            Storage::disk($disk ?? Config::string('numerosis.tenancy.backup.disk', 'local'))->writeStream($path, $handle);
        } finally {
            fclose($handle);
        }
    }

    private function path(Tenant $tenant): string
    {
        return sprintf(
            '%s/%s/%s.%s',
            trim(Config::string('numerosis.tenancy.backup.path', 'tenant-backups'), '/'),
            $tenant->id,
            now()->format('Y-m-d-His'),
            Config::boolean('numerosis.tenancy.backup.encrypt', true) ? 'dump.enc' : 'dump',
        );
    }
}

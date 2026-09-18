<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\EncryptsArtifacts;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseDumper;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Puts an artefact back into a tenant database: the same tenant, or a
 * different one, which is how a production tenant is cloned into staging.
 *
 * @method static void run(Tenant $tenant, string $artefact, bool $force = false, ?string $disk = null)
 */
class RestoreTenantBackup
{
    use AsAction;

    public function __construct(
        private readonly TenantDatabaseDumper $dumper,
        private readonly EncryptsArtifacts $cipher,
    ) {}

    public function handle(Tenant $tenant, string $artefact, bool $force = false, ?string $disk = null): void
    {
        if (! $this->dumper->isAvailable()) {
            throw TenantBackupFailed::dumperUnavailable((string) $this->dumper->unavailableReason());
        }

        if (! $force && $this->holdsRows($tenant)) {
            throw TenantBackupFailed::targetNotEmpty($tenant->id);
        }

        $storage = Storage::disk($disk ?? Config::string('numerosis.tenancy.backup.disk', 'local'));

        if (! $storage->exists($artefact)) {
            throw TenantBackupFailed::artefactMissing($artefact);
        }

        $working = (string) tempnam(sys_get_temp_dir(), 'numerosis-restore');

        try {
            $stream = $storage->readStream($artefact);

            if (! is_resource($stream)) {
                throw TenantBackupFailed::unreadable($artefact);
            }

            $local = fopen($working, 'wb');

            if ($local === false) {
                throw TenantBackupFailed::unwritable($working);
            }

            stream_copy_to_stream($stream, $local);
            fclose($local);
            fclose($stream);

            $this->dumper->restore($tenant, $this->decrypted($artefact, $working));
        } finally {
            @unlink($working);
            @unlink($working.'.plain');
        }
    }

    private function decrypted(string $artefact, string $working): string
    {
        if (! str_ends_with($artefact, '.enc')) {
            return $working;
        }

        $this->cipher->decrypt($working, $working.'.plain');

        return $working.'.plain';
    }

    /**
     * Any row in any table the tenant owns. `users` alone is not enough: a
     * restore into a migrated-but-unseeded tenant is the normal case, and a
     * seeded one already holds rows nobody asked to lose.
     */
    private function holdsRows(Tenant $tenant): bool
    {
        /** @var bool $holdsRows */
        $holdsRows = $tenant->runHere(function (): bool {
            $connection = DB::connection();

            foreach ($connection->getSchemaBuilder()->getTables($connection->getDatabaseName()) as $table) {
                $name = (string) ($table['name'] ?? '');

                if ($name !== '' && ! in_array($name, ['migrations', 'jobs', 'failed_jobs'], true)
                    && $connection->table($name)->exists()) {
                    return true;
                }
            }

            return false;
        });

        return $holdsRows;
    }
}

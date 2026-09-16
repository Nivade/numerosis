<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Nvade\Numerosis\Actions\Tenancy\RestoreTenantBackup;
use Nvade\Numerosis\Actions\Tenancy\RewriteClonedTenantReferences;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

#[Description("Restore a tenant backup over its own database, or into another tenant's")]
#[Signature('tenancy:restore
                            {tenant : The tenant the artefact was taken from}
                            {--from= : Path of the artefact on the backup disk}
                            {--into= : Restore into this tenant instead, rewriting references to the source}
                            {--disk= : Filesystem disk to read from, defaulting to numerosis.tenancy.backup.disk}
                            {--force : Overwrite a target that already holds rows}')]
class RestoreTenantCommand extends Command
{
    public function handle(): int
    {
        $source = $this->tenant((string) $this->argument('tenant'));
        $target = $this->option('into') === null ? $source : $this->tenant((string) $this->option('into'));

        if (! $source instanceof Tenant || ! $target instanceof Tenant) {
            return self::FAILURE;
        }

        $artefact = (string) $this->option('from');

        if ($artefact === '') {
            $this->components->error('Pass --from with the artefact path on the backup disk.');

            return self::FAILURE;
        }

        $disk = $this->option('disk');
        $disk = is_string($disk) && $disk !== '' ? $disk : null;

        try {
            RestoreTenantBackup::run($target, $artefact, (bool) $this->option('force'), $disk);
        } catch (TenantBackupFailed $failure) {
            $this->components->error($failure->getMessage());

            return self::FAILURE;
        }

        if ($target->isNot($source)) {
            $rewritten = RewriteClonedTenantReferences::run($target, $source->id);

            $this->components->info("Rewrote references to [{$source->id}] in {$rewritten} column(s).");
        }

        $this->components->info("Restored [{$source->id}] into [{$target->id}].");

        return self::SUCCESS;
    }

    private function tenant(string $id): ?Tenant
    {
        $tenant = Numerosis::model(Tenant::class)::find($id);

        if (! $tenant instanceof Tenant) {
            $this->components->error("No tenant [{$id}].");

            return null;
        }

        return $tenant;
    }
}

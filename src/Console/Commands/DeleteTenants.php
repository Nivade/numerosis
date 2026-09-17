<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

#[Description('Delete tenants by id, or every tenant, dropping each database with the row')]
#[Signature('tenants:delete
                            {tenants?* : Tenant IDs to delete}
                            {--all : Delete all tenants}
                            {--force : Delete even a closed tenant still inside its recovery window}')]
class DeleteTenants extends Command
{
    public function handle(): int
    {
        $tenantClass = Numerosis::model(Tenant::class);
        $skipped = 0;

        if ($this->option('all')) {
            $tenantClass::each(function ($tenant) use (&$skipped): void {
                if ($tenant instanceof Tenant && ($purgeAt = $this->protectedUntil($tenant)) instanceof Carbon) {
                    $skipped++;
                    $this->warnSkipped($tenant, $purgeAt);

                    return;
                }

                $tenant->delete();
            });

            return $this->report($skipped);
        }

        /** @var list<string> $tenantIds */
        $tenantIds = (array) $this->argument('tenants');

        foreach ($tenantIds as $tenantId) {
            $tenant = $tenantClass::find($tenantId);

            if (! $tenant instanceof Tenant) {
                continue;
            }

            $purgeAt = $this->protectedUntil($tenant);

            if ($purgeAt instanceof Carbon) {
                $skipped++;
                $this->warnSkipped($tenant, $purgeAt);

                continue;
            }

            $tenant->delete();
        }

        return $this->report($skipped);
    }

    /**
     * A closed tenant was promised its data until this date, so deleting it
     * before then takes `--force`, or the recovery window is decorative.
     */
    private function protectedUntil(Tenant $tenant): ?Carbon
    {
        if ($this->option('force')) {
            return null;
        }

        $purgeAt = $tenant->purgeAt();

        return $purgeAt instanceof Carbon && $purgeAt->isFuture() ? $purgeAt : null;
    }

    private function warnSkipped(Tenant $tenant, Carbon $purgeAt): void
    {
        $this->warn("Skipped {$tenant->id}: closed, recoverable until {$purgeAt->toDateString()}. Pass --force to delete it anyway.");
    }

    private function report(int $skipped): int
    {
        return $skipped === 0 ? self::SUCCESS : self::FAILURE;
    }
}

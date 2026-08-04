<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Jobs;

use Nvade\Numerosis\Actions\Tenancy\MarkProvisionFailed;
use Nvade\Numerosis\Concerns\TagsSentryScopeWithTenant;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningFailed;
use Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Throwable;

class SeedTenantDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TagsSentryScopeWithTenant;

    public function __construct(protected TenantWithDatabase $tenant) {}

    public function handle(): void
    {
        $exitCode = Artisan::call('tenants:seed', [
            '--class' => TenantDatabaseSeeder::class,
            '--tenants' => $this->tenant->getTenantKey(),
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException("tenants:seed failed for tenant {$this->tenant->getTenantKey()}: ".Artisan::output());
        }
    }

    /**
     * An un-seeded tenant database has no roles or permissions, so leaving
     * this silent would strand the tenant on the "still provisioning"
     * spinner forever — see .claude/rules/tenant-provisioning.md.
     */
    public function failed(Throwable $e): void
    {
        $domain = (string) $this->tenant->getTenantKey();

        $this->tagSentryScopeWithTenant($domain);

        MarkProvisionFailed::run($domain, $e->getMessage());

        event(new TenantProvisioningFailed($domain, null));
    }
}

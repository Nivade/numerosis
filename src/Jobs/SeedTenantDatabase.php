<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Actions\Tenancy\MarkProvisionFailed;
use Nvade\Numerosis\Concerns\TagsSentryScopeWithTenant;
use Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningFailed;
use RuntimeException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Throwable;

class SeedTenantDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TagsSentryScopeWithTenant;

    public function __construct(protected TenantWithDatabase $tenant) {}

    /**
     * Invokes the tenant seeder directly rather than through `tenants:seed`,
     * which does not work: with stancl/tenancy installed, that command
     * registers under the wrong name and drops its own `--tenants` option.
     */
    public function handle(): void
    {
        tenancy()->initialize($this->tenant);

        try {
            Model::unguarded(function (): void {
                resolve(TenantDatabaseSeeder::class)
                    ->setContainer(app())
                    ->__invoke();
            });
        } catch (Throwable $e) {
            throw new RuntimeException("Seeding failed for tenant {$this->tenant->getTenantKey()}: {$e->getMessage()}", $e->getCode(), previous: $e);
        } finally {
            tenancy()->end();
        }
    }

    /**
     * Marks the provision failed. An unseeded tenant database has no roles or
     * permissions, and failing silently would leave the tenant on the "still
     * provisioning" spinner forever.
     */
    public function failed(Throwable $e): void
    {
        $domain = (string) $this->tenant->getTenantKey();

        $this->tagSentryScopeWithTenant($domain);

        MarkProvisionFailed::run($domain, $e->getMessage());

        event(new TenantProvisioningFailed($domain, null));
    }
}

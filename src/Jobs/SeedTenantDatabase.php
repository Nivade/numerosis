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
     * `Stancl\Tenancy\Commands\Seed` ("tenants:seed") is unusable: it
     * inherits `Illuminate\Database\Console\Seeds\SeedCommand`'s
     * `$signature` without overriding it, so
     * `Illuminate\Console\Command::__construct()` takes the fluent-signature
     * branch and calls `setName()` from that inherited signature — the
     * command actually registers as `db:seed`, not `tenants:seed`, and its
     * `--tenants` option (added by `HasATenantsOption`) never reaches the
     * input definition either, since that trait's own `__construct()` (the
     * one that calls `specifyParameters()`) is shadowed by `Commands\Seed`'s
     * own constructor override. `Artisan::call('tenants:seed', ...)` always
     * threw `CommandNotFoundException`, and `Artisan::call('db:seed', ...)`
     * (the name it actually registers under, because the console app
     * resolves the collision in this package's favour) throws
     * `InvalidOptionException` on `--tenants` the moment `Commands\Seed::handle()`
     * calls `$this->option('tenants')`. Every previous "0 failed" measurement
     * of this suite ran against a MySQL volume where `tenantphpunittemplate`
     * already existed from an older session, so `Tests\Support\CloneTenantSchema`
     * never actually rebuilt it and this path never ran — see
     * .claude/plans/package-extraction.md, step 5. Runs the seeder directly
     * instead, bypassing the broken command entirely; `Model::unguarded()`
     * and `setContainer()` replicate what `SeedCommand::handle()` does for a
     * seeder resolved this way.
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

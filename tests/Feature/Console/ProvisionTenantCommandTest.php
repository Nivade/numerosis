<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Console;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Central\TenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\PendingCommand;
use Nvade\Numerosis\Actions\Tenancy\LinkTenantSubscription;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Tests\TestCase;

class ProvisionTenantCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `artisan()` is typed `PendingCommand|int`, and the int branch has no
     * assertions on it.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /**
     * Left to the pipeline, an unsupported driver surfaces as the tenant
     * seeder dying on `unknown function: SUBSTRING_INDEX()` inside the fifth
     * queued job, naming neither the driver nor this command.
     */
    public function test_it_refuses_a_central_connection_that_is_not_mysql(): void
    {
        Bus::fake();

        // Restored before teardown: CleansUpTenancyDatabases issues its
        // central deletes on this connection and would fail on the driver
        // rather than on anything this test is about.
        $driver = Config::string('database.connections.central.driver');

        try {
            Config::set('database.connections.central.driver', 'sqlite');

            $this->command('tenancy:provision', ['slug' => 'sqliteco'])
                ->expectsOutputToContain('MySQL or MariaDB is required')
                ->assertFailed();
        } finally {
            Config::set('database.connections.central.driver', $driver);
        }

        Bus::assertNothingDispatched();
    }

    /**
     * QUEUE_CONNECTION=sync runs every chain link inline, so this proves a
     * tenant provisioned with no billing at all comes out usable rather than
     * merely that the command queued something.
     */
    public function test_it_provisions_a_tenant_with_no_billing(): void
    {
        $owner = CentralUser::factory()->create();

        $this->command('tenancy:provision', [
            'slug' => 'clitenant',
            '--owner' => $owner->global_id,
            '--name' => 'CLI Co',
        ])->assertSuccessful();

        $provision = TenantProvision::findOrFail('clitenant');

        $this->assertSame(TenantProvisionStatus::Completed, $provision->status);
        $this->assertSame('CLI Co', $provision->name);

        $tenant = Tenant::findOrFail('clitenant');

        $this->assertNotNull($tenant->provisioned_at);
        $this->assertTrue($owner->tenants()->where('tenants.id', 'clitenant')->exists());

        // Nothing contributed billing, so the billing step skips rather than
        // the command needing to know which steps are billing-dependent.
        $this->assertSame('skipped', $provision->step_records[LinkTenantSubscription::class]['outcome']);
    }

    public function test_it_defaults_the_name_to_the_slug(): void
    {
        $owner = CentralUser::factory()->create();

        $this->command('tenancy:provision', [
            'slug' => 'unnamed',
            '--owner' => $owner->global_id,
        ])->assertSuccessful();

        $this->assertSame('unnamed', TenantProvision::findOrFail('unnamed')->name);
    }

    /**
     * Left to the pipeline, this surfaces as a `firstOrFail` inside a queued
     * job with nobody watching.
     */
    public function test_it_refuses_an_owner_that_does_not_exist(): void
    {
        $this->command('tenancy:provision', [
            'slug' => 'noowner',
            '--owner' => 'nobody',
        ])->assertFailed();

        $this->assertNull(TenantProvision::find('noowner'));
    }

    public function test_it_refuses_a_slug_a_tenant_already_holds(): void
    {
        $owner = CentralUser::factory()->create();
        Tenant::factory()->create(['id' => 'taken']);

        $this->command('tenancy:provision', [
            'slug' => 'taken',
            '--owner' => $owner->global_id,
        ])->assertFailed();
    }

    /**
     * Validated like the slug is. `ReserveTenantDomain` checks both on the
     * checkout path, so the command checking only one was asymmetric.
     */
    public function test_it_refuses_a_custom_domain_another_tenant_holds(): void
    {
        $owner = CentralUser::factory()->create();
        $taken = Tenant::factory()->create(['id' => 'holder']);
        $taken->domains()->create(['id' => 'holder-domain', 'domain' => 'app.taken.test']);

        $this->command('tenancy:provision', [
            'slug' => 'wantsdomain',
            '--owner' => $owner->global_id,
            '--custom-domain' => 'app.taken.test',
        ])->assertFailed();

        $this->assertNull(TenantProvision::find('wantsdomain'));
    }

    /**
     * The tenant-exists guard runs first, so this is the only case that
     * reaches the domain policy for the slug -- and the policy throws
     * ValidationException, which is not a ShowsMessageToUser.
     */
    public function test_it_refuses_a_reserved_slug(): void
    {
        $owner = CentralUser::factory()->create();

        $this->command('tenancy:provision', [
            'slug' => 'www',
            '--owner' => $owner->global_id,
        ])->assertFailed();

        $this->assertNull(TenantProvision::find('www'));
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Console\Commands;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `unsetEventDispatcher()` keeps `TenantDeleted` from queueing stancl's
 * `DeleteDatabase`, so these tests are about the row and the guard rather
 * than about physical databases.
 */
class DeleteTenantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_a_closed_tenant_inside_its_recovery_window(): void
    {
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create(['closed_at' => now()->subDays(3)]);

        $this->deleteTenants(['tenants' => [$tenant->id]])
            ->expectsOutputToContain('Pass --force')
            ->assertExitCode(1);

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_force_deletes_a_closed_tenant_inside_its_window(): void
    {
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create(['closed_at' => now()->subDays(3)]);

        $this->deleteTenants(['tenants' => [$tenant->id], '--force' => true])
            ->assertExitCode(0);

        $this->assertNull(Tenant::find($tenant->id));
    }

    public function test_it_deletes_a_closed_tenant_past_its_window_without_force(): void
    {
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create(['closed_at' => now()->subDays(31)]);

        $this->deleteTenants(['tenants' => [$tenant->id]])->assertExitCode(0);

        $this->assertNull(Tenant::find($tenant->id));
    }

    public function test_all_skips_a_tenant_inside_its_recovery_window(): void
    {
        Tenant::unsetEventDispatcher();

        $closed = Tenant::factory()->create(['closed_at' => now()->subDays(3)]);
        $open = Tenant::factory()->create();

        $this->deleteTenants(['--all' => true])->assertExitCode(1);

        $this->assertNotNull(Tenant::find($closed->id));
        $this->assertNull(Tenant::find($open->id));
    }

    /**
     * `artisan()` is typed `PendingCommand|int` and the int branch carries no
     * expectation methods.
     *
     * @param  array<string, mixed>  $options
     */
    private function deleteTenants(array $options): PendingCommand
    {
        $command = $this->artisan('tenants:delete', $options);

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}

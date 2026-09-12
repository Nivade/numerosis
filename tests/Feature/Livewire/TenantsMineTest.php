<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Central\TenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Tests\TestCase;

class TenantsMineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_provisioned_tenant_is_listed_as_ready(): void
    {
        $user = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create(['provisioned_at' => now()]);
        $this->attach($user, $tenant);

        Livewire::actingAs($user)
            ->test('numerosis-pages::tenant.mine')
            ->assertSet('readyTenants', fn ($tenants) => $tenants->count() === 1)
            ->assertSet('provisioningTenants', fn ($tenants) => $tenants->isEmpty());
    }

    /**
     * The webhook path creates tenant rows with no pending row, so an
     * unprovisioned tenant must still be held back from the ready list.
     */
    public function test_an_unprovisioned_tenant_is_not_listed_as_ready(): void
    {
        $user = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create(['provisioned_at' => null]);
        $this->attach($user, $tenant);

        Livewire::actingAs($user)
            ->test('numerosis-pages::tenant.mine')
            ->assertSet('readyTenants', fn ($tenants) => $tenants->isEmpty())
            ->assertSet('provisioningTenants', fn ($tenants) => $tenants->count() === 1);
    }

    public function test_a_pending_provision_is_shown_before_the_tenant_row_exists(): void
    {
        $user = CentralUser::factory()->create();

        TenantProvision::factory()->provisioning()->create([
            'slug' => 'waiting',
            'name' => 'Waiting Co',
            'global_id' => $user->global_id,
        ]);

        Livewire::actingAs($user)
            ->test('numerosis-pages::tenant.mine')
            ->assertSet('pendingTenants', fn ($pending) => $pending->count() === 1)
            ->assertSee('Waiting Co');
    }

    public function test_a_failed_provision_offers_a_retry(): void
    {
        $user = CentralUser::factory()->create();

        TenantProvision::factory()->failed()->create([
            'slug' => 'brokenone',
            'name' => 'Broken Co',
            'global_id' => $user->global_id,
            'error' => 'seeding blew up',
        ]);

        Livewire::actingAs($user)
            ->test('numerosis-pages::tenant.mine')
            ->assertSee('seeding blew up')
            ->assertSee('Try again');
    }

    public function test_a_reserved_provision_offers_to_continue_checkout(): void
    {
        $user = CentralUser::factory()->create();

        TenantProvision::factory()->create([
            'slug' => 'reservedone',
            'name' => 'Reserved Co',
            'global_id' => $user->global_id,
        ]);

        Livewire::actingAs($user)
            ->test('numerosis-pages::tenant.mine')
            ->assertSee('Reserved Co')
            ->assertSee('Continue checkout')
            ->assertSeeHtml(route('checkout.resume', 'reservedone'));
    }

    public function test_it_does_not_show_another_users_pending_provision(): void
    {
        $user = CentralUser::factory()->create();
        $other = CentralUser::factory()->create();

        TenantProvision::factory()->provisioning()->create([
            'slug' => 'someoneelse',
            'name' => 'Someone Else Co',
            'global_id' => $other->global_id,
        ]);

        Livewire::actingAs($user)
            ->test('numerosis-pages::tenant.mine')
            ->assertSet('pendingTenants', fn ($pending) => $pending->isEmpty())
            ->assertDontSee('Someone Else Co');
    }

    /**
     * Once the tenant row exists the pending row is redundant; showing both
     * would render the same tenant twice while provisioning finishes.
     */
    public function test_a_pending_row_is_hidden_once_its_tenant_exists(): void
    {
        $user = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create(['provisioned_at' => null]);
        $this->attach($user, $tenant);

        TenantProvision::factory()->provisioning()->create([
            'slug' => $tenant->id,
            'name' => 'Duplicated Co',
            'global_id' => $user->global_id,
        ]);

        Livewire::actingAs($user)
            ->test('numerosis-pages::tenant.mine')
            ->assertSet('pendingTenants', fn ($pending) => $pending->isEmpty())
            ->assertSet('provisioningTenants', fn ($tenants) => $tenants->count() === 1);
    }

    public function test_cancelling_a_reserved_provision_deletes_it(): void
    {
        $user = CentralUser::factory()->create();

        TenantProvision::factory()->create([
            'slug' => 'cancelme',
            'name' => 'Cancel Co',
            'global_id' => $user->global_id,
        ]);

        Livewire::actingAs($user)
            ->test('numerosis-pages::tenant.mine')
            ->call('cancelProvision', 'cancelme')
            ->assertSet('pendingTenants', fn ($pending) => $pending->isEmpty());

        $this->assertNull(TenantProvision::find('cancelme'));
    }

    /**
     * $domain is a plain Livewire method argument — client-controlled the
     * same way a route parameter is — so ownership has to be re-checked
     * server-side, not trusted from which row rendered the button.
     */
    public function test_cancelling_another_users_provision_is_refused(): void
    {
        $user = CentralUser::factory()->create();
        $other = CentralUser::factory()->create();

        TenantProvision::factory()->create([
            'slug' => 'notyours',
            'name' => 'Not Yours Co',
            'global_id' => $other->global_id,
        ]);

        Livewire::actingAs($user)
            ->test('numerosis-pages::tenant.mine')
            ->call('cancelProvision', 'notyours');

        $this->assertNotNull(TenantProvision::find('notyours'));
    }

    private function attach(BaseCentralUser $user, BaseTenant $tenant): void
    {
        $user->tenants()->attach($tenant, ['role' => 'owner', 'joined_at' => now()]);
    }
}

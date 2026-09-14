<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Layout;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Queries\GetTenantsByGlobalId;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The header was a class-based Nvade\Numerosis\Livewire\Layout\Header and is now the
 * single-file component `resources/views/layouts/⚡header.blade.php`, rendered
 * as `<livewire:numerosis-layouts::header />` and addressed by that name here.
 */
class HeaderTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    private const string COMPONENT = 'numerosis-layouts::header';

    public function test_it_renders_successfully(): void
    {
        Livewire::test(self::COMPONENT)
            ->assertStatus(200);
    }

    /**
     * The sign-in link is gated on `Route::has('login')` — that route
     * belonged to nvade/numerosis-auth-ui's Livewire PasswordlessLogin,
     * deleted (not moved) when that package folded into core in Phase 3 of
     * `.claude/plans/archive/humming-nibbling-flame.md`. Phase 4 rebuilds it on
     * Fortify — reinstate this assertion then.
     */
    public function test_it_shows_sign_in_link_for_guests(): void
    {
        Livewire::test(self::COMPONENT)
            ->assertDontSee(__('Log Out'));
    }

    /**
     * The component reads the central guard explicitly rather than the ambient
     * default, so this must authenticate against that guard by name.
     */
    public function test_it_shows_user_menu_for_authenticated_users(): void
    {
        $user = CentralUser::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $this->actingAsCentralUser($user);

        Livewire::test(self::COMPONENT)
            ->assertSee('John Doe')
            ->assertSee(__('Log Out'))
            ->assertDontSee(__('Sign In'));
    }

    /**
     * The switcher reads the cached id list rather than the `tenants`
     * relation, which was a second query for the same set.
     */
    public function test_the_tenant_switcher_reads_the_cached_list(): void
    {
        $this->pinGlobalCache();

        $user = CentralUser::factory()->create();
        $tenant = Tenant::create(['id' => 'header-'.uniqid(), 'name' => 'Acme']);
        $tenant->users()->attach($user->global_id, ['role' => MembershipRole::Owner->value]);
        $tenant->domains()->create(['id' => $tenant->id, 'domain' => $this->tenantDomain($tenant->id)]);

        $this->actingAsCentralUser($user);

        GetTenantsByGlobalId::flushMemo();
        GetTenantsByGlobalId::run($user->global_id);

        $connection = $this->centralDatabase();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        Livewire::test(self::COMPONENT)->assertSee('Acme');

        $membershipQueries = array_filter(
            $connection->getQueryLog(),
            fn (array $query): bool => str_contains((string) $query['query'], 'memberships')
        );

        $this->assertEmpty($membershipQueries, 'The header re-queried the tenants relation.');
    }

    /** A tenant with no domain row has no link to render, and used to null-deref. */
    public function test_it_renders_a_tenant_without_a_domain(): void
    {
        $this->pinGlobalCache();

        $user = CentralUser::factory()->create(['name' => 'Jane Doe']);
        $tenant = Tenant::create(['id' => 'header-nodomain-'.uniqid(), 'name' => 'Domainless']);
        $tenant->users()->attach($user->global_id, ['role' => MembershipRole::Owner->value]);

        $this->actingAsCentralUser($user);

        GetTenantsByGlobalId::flushMemo();

        Livewire::test(self::COMPONENT)
            ->assertStatus(200)
            ->assertSee('Jane Doe');
    }
}

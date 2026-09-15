<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Queries;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Cache\ForgetUserTenants;
use Nvade\Numerosis\Actions\Queries\GetTenantsByGlobalId;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `Authenticate` reaches this through `User::canAccessTenant()` on every
 * authenticated tenant request. The global cache holds the id list; the models
 * themselves are always re-read, so no worker can serve another's plan edit.
 */
class GetTenantsByGlobalIdCacheTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    public function test_repeated_lookups_read_the_membership_table_once(): void
    {
        $this->pinGlobalCache();

        $user = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user->global_id, ['role' => 'owner']);

        GetTenantsByGlobalId::run($user->global_id);

        $connection = $this->centralDatabase();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        GetTenantsByGlobalId::run($user->global_id);

        $membershipQueries = array_filter(
            $connection->getQueryLog(),
            fn (array $query): bool => str_contains((string) $query['query'], 'memberships')
        );

        $this->assertEmpty($membershipQueries);
    }

    public function test_forgetting_the_cache_entry_reflects_a_new_membership(): void
    {
        $this->pinGlobalCache();

        $user = CentralUser::factory()->create();

        $this->assertCount(0, GetTenantsByGlobalId::run($user->global_id));

        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user->global_id, ['role' => 'owner']);

        ForgetUserTenants::run($user->global_id);

        $this->assertCount(1, GetTenantsByGlobalId::run($user->global_id));
    }

    /** The cached id list drives the order, so the switcher stays stable between reads. */
    public function test_it_returns_tenants_in_the_cached_id_order(): void
    {
        $this->pinGlobalCache();

        $user = CentralUser::factory()->create();
        $tenants = [Tenant::factory()->create(), Tenant::factory()->create()];

        foreach ($tenants as $tenant) {
            $tenant->users()->attach($user->global_id, ['role' => 'owner']);
        }

        $first = GetTenantsByGlobalId::run($user->global_id)->pluck('id')->all();
        $second = GetTenantsByGlobalId::run($user->global_id)->pluck('id')->all();

        $this->assertSame($first, $second);
        $this->assertCount(2, $first);
    }
}

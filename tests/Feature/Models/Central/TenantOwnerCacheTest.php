<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Models\Central;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\CentralUser as PackageCentralUser;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `owner()` is a `belongsToMany` with a pivot filter and a `first()`, read
 * from five call sites, several of them on request paths. Only the
 * `global_id` is cached; the row comes back through the already-cached
 * `FindUserByGlobalId`.
 */
class TenantOwnerCacheTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    public function test_a_second_call_issues_no_query(): void
    {
        $this->pinGlobalCache();

        $tenant = $this->tenantOwnedBy($owner = CentralUser::factory()->create());

        $this->assertSame($owner->global_id, $this->ownerGlobalId($tenant));

        $connection = $this->centralDatabase();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $this->assertSame($owner->global_id, $this->ownerGlobalId($tenant));

        $this->assertEmpty($connection->getQueryLog());
    }

    public function test_transferring_ownership_is_visible_on_the_next_read(): void
    {
        $this->pinGlobalCache();

        $tenant = $this->tenantOwnedBy($first = CentralUser::factory()->create());

        $this->assertSame($first->global_id, $this->ownerGlobalId($tenant));

        $second = CentralUser::factory()->create();

        $tenant->users()->updateExistingPivot($first->global_id, ['role' => MembershipRole::Member->value]);
        $tenant->users()->attach($second->global_id, ['role' => MembershipRole::Owner->value]);

        $this->assertSame($second->global_id, $this->ownerGlobalId($tenant));
    }

    public function test_removing_the_owner_is_visible_on_the_next_read(): void
    {
        $this->pinGlobalCache();

        $tenant = $this->tenantOwnedBy($owner = CentralUser::factory()->create());

        $this->assertSame($owner->global_id, $this->ownerGlobalId($tenant));

        $tenant->users()->detach($owner->global_id);

        $this->assertNull($tenant->owner());
    }

    private function ownerGlobalId(Tenant $tenant): ?string
    {
        return $tenant->owner()?->global_id;
    }

    private function tenantOwnedBy(PackageCentralUser $owner): Tenant
    {
        $tenant = Tenant::create(['id' => 'owner-cache-'.uniqid()]);

        $tenant->users()->attach($owner->global_id, ['role' => MembershipRole::Owner->value]);

        return $tenant;
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Queries;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Actions\Cache\ForgetUserTenants;
use Nvade\Numerosis\Actions\Queries\GetTenantsByGlobalId;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `Authenticate` reaches this through `User::canAccessTenant()` on every
 * authenticated tenant request, and it deserializes every one of the user's
 * tenant models to answer a `contains`. The hour-long global cache already
 * accepts that staleness; a request-lifetime memo adds none of its own.
 */
class GetTenantsByGlobalIdMemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_lookups_in_one_request_query_once(): void
    {
        $user = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user->global_id, ['role' => 'owner']);

        GetTenantsByGlobalId::run($user->global_id);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        GetTenantsByGlobalId::run($user->global_id);
        GetTenantsByGlobalId::run($user->global_id);

        $this->assertSame([], $queries);
    }

    /**
     * The memo is the reason this matters: forgetting the cache entry alone
     * would leave the reader answering from before the change.
     */
    public function test_forgetting_the_cache_entry_drops_the_memo(): void
    {
        $user = CentralUser::factory()->create();

        $this->assertCount(0, GetTenantsByGlobalId::run($user->global_id));

        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user->global_id, ['role' => 'owner']);

        ForgetUserTenants::run($user->global_id);

        $this->assertCount(1, GetTenantsByGlobalId::run($user->global_id));
    }
}

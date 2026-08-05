<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Queries;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Queries\FindUserByGlobalId;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

class FindUserByGlobalIdTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    /**
     * The tenant-context result is memoised in global_cache(), which is shared
     * by every tenant. A key that does not name the tenant hands tenant A's row
     * — a per-database primary key — back as the authenticated user in tenant B.
     */
    public function test_tenant_context_result_is_not_shared_between_tenants(): void
    {
        $this->pinGlobalCache();

        $globalId = 'shared-user-global-id';

        $first = $this->makeTenant('cache-first-'.uniqid());
        $second = $this->makeTenant('cache-second-'.uniqid());

        // Seed the user at a different primary key in each tenant database, so a
        // leaked cache entry resolves to the wrong row rather than merely the
        // wrong instance.
        $first->run(function () use ($globalId): void {
            TenantUser::forceCreate([
                'name' => 'Filler', 'email' => 'filler-'.uniqid().'@example.com', 'global_id' => 'filler-global-id',
            ]);
            TenantUser::forceCreate([
                'name' => 'Shared User', 'email' => 'shared-'.uniqid().'@example.com', 'global_id' => $globalId,
            ]);
        });

        $second->run(function () use ($globalId): void {
            TenantUser::forceCreate([
                'name' => 'Shared User', 'email' => 'shared-'.uniqid().'@example.com', 'global_id' => $globalId,
            ]);
        });

        /** @var User|null $firstUser */
        $firstUser = $first->run(fn () => FindUserByGlobalId::run($globalId));
        /** @var User|null $secondUser */
        $secondUser = $second->run(fn () => FindUserByGlobalId::run($globalId));

        $this->assertNotNull($firstUser);
        $this->assertNotNull($secondUser);
        $this->assertSame($globalId, $secondUser->global_id);
        $this->assertNotSame(
            $firstUser->getKey(),
            $secondUser->getKey(),
            'The second tenant was served the first tenant\'s cached user row.',
        );
    }

    /**
     * The cached entry must not outlive the row it describes: LoginUser
     * authenticates whatever FindUserByGlobalId returns, so a renamed or
     * otherwise updated user must not keep resolving to stale attributes.
     */
    public function test_central_context_result_is_invalidated_on_update(): void
    {
        $this->pinGlobalCache();

        $globalId = 'central-'.uniqid();

        $user = CentralUser::create([
            'global_id' => $globalId,
            'name' => 'Original Name',
            'email' => 'original-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $cached = FindUserByGlobalId::run($globalId, Context::Central);
        $this->assertNotNull($cached);
        $this->assertSame('Original Name', $cached->name);

        $user->update(['name' => 'Renamed']);

        $refreshed = FindUserByGlobalId::run($globalId, Context::Central);
        $this->assertNotNull($refreshed);
        $this->assertSame('Renamed', $refreshed->name);
    }

    public function test_tenant_context_result_is_invalidated_on_update(): void
    {
        $this->pinGlobalCache();

        $globalId = 'tenant-user-'.uniqid();
        $tenant = $this->makeTenant('cache-update-'.uniqid());

        $tenant->run(function () use ($globalId): void {
            TenantUser::forceCreate([
                'name' => 'Original Name',
                'email' => 'original-'.uniqid().'@example.com',
                'global_id' => $globalId,
            ]);
        });

        /** @var User|null $cached */
        $cached = $tenant->run(fn () => FindUserByGlobalId::run($globalId));
        $this->assertNotNull($cached);
        $this->assertSame('Original Name', $cached->name);

        $tenant->run(function () use ($globalId): void {
            TenantUser::where('global_id', $globalId)->firstOrFail()->update(['name' => 'Renamed']);
        });

        /** @var User|null $refreshed */
        $refreshed = $tenant->run(fn () => FindUserByGlobalId::run($globalId));
        $this->assertNotNull($refreshed);
        $this->assertSame('Renamed', $refreshed->name);
    }

    private function makeTenant(string $id): Tenant
    {
        $tenant = Tenant::create(['id' => $id]);

        $tenant->domains()->create([
            'id' => $id,
            'domain' => $this->tenantDomain($id),
        ]);

        return $tenant;
    }
}

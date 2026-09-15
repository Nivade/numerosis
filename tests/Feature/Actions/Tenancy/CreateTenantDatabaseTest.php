<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDatabase;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseManager;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Tests\Concerns\BuildsTenantProvisionData;
use Nvade\Numerosis\Tests\Support\FakeTenantDatabaseManager;
use Nvade\Numerosis\Tests\TestCase;
use Stancl\Tenancy\Events\CreatingDatabase;

/**
 * No MySQL tenant database is created here: the fake answers "exists", and
 * `create_database => false` stops stancl's job after the event it opens with.
 */
class CreateTenantDatabaseTest extends TestCase
{
    use BuildsTenantProvisionData;
    use RefreshDatabase;

    public function test_it_skips_stancls_job_when_the_database_already_exists(): void
    {
        Event::fake([CreatingDatabase::class]);
        $manager = $this->fakeManager(exists: true);
        $tenant = $this->tenantThatSkipsRealCreation();

        CreateTenantDatabase::make()->handle($this->provisionFor($tenant));

        $this->assertSame([$tenant->id], $manager->asked);
        Event::assertNotDispatched(CreatingDatabase::class);
    }

    public function test_it_runs_stancls_job_when_the_database_is_absent(): void
    {
        Event::fake([CreatingDatabase::class]);
        $this->fakeManager(exists: false);
        $tenant = $this->tenantThatSkipsRealCreation();

        CreateTenantDatabase::make()->handle($this->provisionFor($tenant));

        Event::assertDispatched(fn (CreatingDatabase $e): bool => $e->tenant->getTenantKey() === $tenant->id);
    }

    private function fakeManager(bool $exists): FakeTenantDatabaseManager
    {
        $manager = new FakeTenantDatabaseManager($exists);
        app()->instance(TenantDatabaseManager::class, $manager);

        return $manager;
    }

    private function tenantThatSkipsRealCreation(): BaseTenant
    {
        $tenant = Tenant::factory()->create();
        $tenant->setInternal('create_database', false)->save();

        return $tenant;
    }

    private function provisionFor(BaseTenant $tenant): TenantProvision
    {
        return $this->provisionRow(CentralUser::factory()->create(), (string) $tenant->getTenantKey());
    }
}

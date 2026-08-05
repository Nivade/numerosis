<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Users\UserResource;
use Nvade\Numerosis\Tests\TestCase;
use Spatie\Permission\Models\Permission;

class MembershipsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_resource_is_accessible_when_enabled(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->run(function () use ($tenant): void {
            $user = TenantUser::factory()->create();
            $user->givePermissionTo(Permission::firstOrCreate([
                'name' => 'viewAny users',
                'guard_name' => 'tenant',
            ]));
            $this->actingAsTenantPanelUser($tenant, $user);

            $this->assertTrue(UserResource::canAccess());
        });
    }
}

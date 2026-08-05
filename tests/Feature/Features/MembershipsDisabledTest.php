<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Features\Ui\TenantPanelFeature;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Users\UserResource;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Tests\TestCase;

class MembershipsDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // MembershipsFeature off, TenantPanelFeature on — otherwise
        // Filament::getPanel('tenantAdmin') in actingAsTenantPanelUser()
        // throws before the assertion this test is actually about.
        Features::forceForTesting([TenantPanelFeature::class]);

        parent::setUp();
    }

    public function test_the_resource_is_inaccessible_when_disabled(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->run(function () use ($tenant): void {
            $user = TenantUser::factory()->create();
            $this->actingAsTenantPanelUser($tenant, $user);

            $this->assertFalse(UserResource::canAccess());
        });
    }
}

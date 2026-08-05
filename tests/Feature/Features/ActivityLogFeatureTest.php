<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use AlizHarb\ActivityLog\ActivityLogPlugin;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities\ActivityResource;
use Nvade\Numerosis\Tests\TestCase;

class ActivityLogFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_plugin_is_registered_when_enabled(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->run(function () use ($tenant): void {
            $user = TenantUser::factory()->create();
            $this->actingAsTenantPanelUser($tenant, $user);

            $this->assertTrue(Filament::getPanel('tenantAdmin')->hasPlugin(ActivityLogPlugin::make()->getId()));
        });
    }

    public function test_the_resource_is_accessible_when_enabled(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->run(function () use ($tenant): void {
            $user = TenantUser::factory()->create();
            $this->actingAsTenantPanelUser($tenant, $user);

            $this->assertTrue(ActivityResource::canAccess());
        });
    }
}

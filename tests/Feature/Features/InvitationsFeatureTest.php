<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Nvade\Numerosis\Filament\TenantAdmin\Resources\Invitations\InvitationResource;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Tests\TestCase;

class InvitationsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_the_invitation_route_when_enabled(): void
    {
        $this->assertTrue(Route::has('invitation.show'));
    }

    public function test_the_resource_is_accessible_when_enabled(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->run(function () use ($tenant): void {
            $user = TenantUser::factory()->create();
            $this->actingAsTenantPanelUser($tenant, $user);

            $this->assertTrue(InvitationResource::canAccess());
        });
    }
}

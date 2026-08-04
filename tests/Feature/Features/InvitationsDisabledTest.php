<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Nvade\Numerosis\Filament\TenantAdmin\Resources\Invitations\InvitationResource;
use Nvade\Numerosis\Support\Features;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Tests\TestCase;

class InvitationsDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        Features::forceForTesting([]);

        parent::setUp();
    }

    public function test_it_registers_no_invitation_route_when_disabled(): void
    {
        $this->assertFalse(Route::has('invitation.show'));
    }

    public function test_the_resource_is_inaccessible_when_disabled(): void
    {
        $this->assertFalse(InvitationResource::canAccess());
    }
}

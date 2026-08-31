<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Tests\TestCase;
use Nvade\NumerosisFilament\TenantAdmin\Resources\Invitations\InvitationResource;

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

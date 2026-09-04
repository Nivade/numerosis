<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Support\Features;
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
        $this->assertFalse(Route::has('invitations.show'));
        $this->assertFalse(Route::has('invitations.accept'));
        $this->assertFalse(Route::has('team.invitations.index'));
        $this->assertFalse(Route::has('team.invitations.store'));
        $this->assertFalse(Route::has('team.invitations.destroy'));
    }
}

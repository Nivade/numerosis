<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Tests\TestCase;

class InvitationsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_the_invitation_route_when_enabled(): void
    {
        $this->assertTrue(Route::has('invitations.show'));
        $this->assertTrue(Route::has('invitations.accept'));
        $this->assertTrue(Route::has('team.invitations.index'));
        $this->assertTrue(Route::has('team.invitations.store'));
        $this->assertTrue(Route::has('team.invitations.destroy'));
    }
}

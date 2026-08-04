<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;

class SocialLoginButtonsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_renders_buttons_for_providers_configured_in_services(): void
    {
        // config/services.php only defines 'google' and 'discord' — github,
        // facebook and gitlab have no config block at all, so the grid must
        // not render a button for them (see grid.blade.php's filter).
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee(route('oauth', ['driver' => 'google']), false);
        $response->assertSee(route('oauth', ['driver' => 'discord']), false);
        $response->assertDontSee(route('oauth', ['driver' => 'github']), false);
        $response->assertDontSee(route('oauth', ['driver' => 'facebook']), false);
        $response->assertDontSee(route('oauth', ['driver' => 'gitlab']), false);
    }
}

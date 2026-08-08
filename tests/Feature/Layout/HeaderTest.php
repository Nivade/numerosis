<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Layout;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The header was a class-based Nvade\Numerosis\Livewire\Layout\Header and is now the
 * single-file component `resources/views/layouts/⚡header.blade.php`, rendered
 * as `<livewire:layouts::header />` and addressed by that name here.
 */
class HeaderTest extends TestCase
{
    use RefreshDatabase;

    private const string COMPONENT = 'layouts::header';

    public function test_it_renders_successfully(): void
    {
        Livewire::test(self::COMPONENT)
            ->assertStatus(200);
    }

    public function test_it_shows_sign_in_link_for_guests(): void
    {
        Livewire::test(self::COMPONENT)
            ->assertSee(__('Sign In'))
            ->assertDontSee(__('Log Out'));
    }

    /**
     * The component reads the central guard explicitly rather than the ambient
     * default, so this must authenticate against that guard by name.
     */
    public function test_it_shows_user_menu_for_authenticated_users(): void
    {
        $user = CentralUser::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $this->actingAs($user, Config::string('auth.defaults.guards.context.central'));

        Livewire::test(self::COMPONENT)
            ->assertSee('John Doe')
            ->assertSee(__('Log Out'))
            ->assertDontSee(__('Sign In'));
    }
}

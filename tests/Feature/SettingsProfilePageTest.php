<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Regression for the convention-registration audit
 * (.claude/plans/package-extraction.md, step 2): `settings/profile.blade.php`
 * embeds `<livewire:settings.delete-user-form />`, which Livewire's Finder
 * only resolves via `Livewire::addComponent()` since the class lives under
 * `Nvade\Numerosis\Livewire\Settings`, not the host's `App\Livewire`
 * default. Nothing rendered this page before, so the missing registration
 * (host resolved as `App\Livewire\Settings\DeleteUserForm`, which never
 * exists) went uncaught.
 */
class SettingsProfilePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_the_delete_account_form(): void
    {
        $user = CentralUser::factory()->create();

        $this->actingAs($user, Config::string('numerosis.auth.guards.central'));

        $this->get(route('settings.profile'))
            ->assertOk()
            ->assertSeeLivewire('settings.delete-user-form');
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Livewire\Settings\Password;
use Nvade\Numerosis\Tests\TestCase;

class PasswordChangeRevokesSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_the_password_deletes_the_users_other_stored_sessions(): void
    {
        Config::set('session.driver', 'database');
        Config::set('session.connection', 'central');
        Config::set('session.lifetime', 120);

        $user = CentralUser::factory()->create(['password' => Hash::make('Str0ng-Passw0rd!')]);
        $other = CentralUser::factory()->create();

        $this->actingAsCentralUser($user);

        $this->insertSession('stolen', $user->id);
        $this->insertSession('someone-else', $other->id);

        Livewire::test(Password::class)
            ->set('current_password', 'Str0ng-Passw0rd!')
            ->set('password', 'Different-Passw0rd!')
            ->set('password_confirmation', 'Different-Passw0rd!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertSame(['someone-else'], $this->sessionIds());
    }

    /**
     * The driver-independent half: `AuthenticateSession` compares the stamp
     * the session was issued with against the user's current password hash, so
     * a password changed from anywhere ends every session but the one that
     * changed it. Without that middleware on the route the second request
     * below is still authenticated.
     */
    public function test_a_password_changed_elsewhere_ends_this_session_on_the_next_request(): void
    {
        $user = CentralUser::factory()->create(['password' => Hash::make('Str0ng-Passw0rd!')]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'Str0ng-Passw0rd!',
        ])->assertRedirect();

        $this->get('/settings/profile')->assertOk();

        $user->forceFill(['password' => Hash::make('Changed-Elsewhere-1!')])->saveQuietly();

        // One app serves every request in a test, and the guard caches its
        // resolved user, so without this the second request compares the stamp
        // against the hash the first request loaded.
        Auth::forgetGuards();

        $this->get('/settings/profile')->assertRedirect('/login');
    }

    private function insertSession(string $id, int $userId): void
    {
        $key = 'login_'.Context::Central->guard().'_'.sha1(Auth::guard(Context::Central->guard())::class);

        DB::connection('central')->table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 Firefox/130.0',
            'payload' => base64_encode(serialize([$key => $userId])),
            'last_activity' => time(),
        ]);
    }

    /** @return list<string> */
    private function sessionIds(): array
    {
        /** @var list<string> $ids */
        $ids = DB::connection('central')->table('sessions')->orderBy('id')->pluck('id')->all();

        return $ids;
    }
}

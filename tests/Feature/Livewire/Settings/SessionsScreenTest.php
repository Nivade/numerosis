<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Settings;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Livewire\Settings\Sessions;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Tests\TestCase;

class SessionsScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('session.driver', 'database');
        Config::set('session.connection', 'central');
        Config::set('session.lifetime', 120);
    }

    public function test_it_lists_the_users_devices(): void
    {
        $user = $this->signedInUser();

        $this->insertSession('laptop', $user->id);

        Livewire::test(Sessions::class)
            ->assertStatus(200)
            ->assertSee('Firefox on macOS')
            ->assertSee('203.0.113.7');
    }

    public function test_it_revokes_one_session(): void
    {
        $user = $this->signedInUser();

        $this->insertSession('laptop', $user->id);
        $this->insertSession('phone', $user->id);

        Livewire::test(Sessions::class)->call('revoke', 'laptop');

        $this->assertSame(['phone'], $this->sessionIds());
    }

    public function test_it_rejects_a_wrong_password_before_revoking_anything(): void
    {
        $user = $this->signedInUser();

        $this->insertSession('laptop', $user->id);

        Livewire::test(Sessions::class)
            ->set('password', 'not-the-password')
            ->call('revokeOthers')
            ->assertHasErrors('password');

        $this->assertSame(['laptop'], $this->sessionIds());
    }

    public function test_it_revokes_every_other_session_and_rehashes_the_password_stamp(): void
    {
        $user = $this->signedInUser();
        $stamp = (string) $user->password;

        $this->insertSession('laptop', $user->id);
        $this->insertSession('phone', $user->id);

        Livewire::test(Sessions::class)
            ->set('password', 'Str0ng-Passw0rd!')
            ->call('revokeOthers')
            ->assertHasNoErrors()
            ->assertDispatched('sessions-revoked');

        $this->assertSame([], $this->sessionIds());

        // The stamp AuthenticateSession compares against, moved without the
        // password itself changing: what revokes a session on a driver the
        // registry cannot list.
        $this->assertNotSame($stamp, (string) $user->fresh()?->password);
    }

    /**
     * The screen has to answer honestly rather than render an empty list that
     * reads as "you are signed in nowhere else".
     */
    public function test_it_degrades_to_revoke_all_under_an_unlistable_driver(): void
    {
        $user = $this->signedInUser();

        Config::set('session.driver', 'file');

        $component = Livewire::test(Sessions::class);

        $component->assertStatus(200)->assertSee('does not store sessions in a way they can be listed');

        $component->set('password', 'Str0ng-Passw0rd!')
            ->call('revokeOthers')
            ->assertHasNoErrors();

        $this->assertNotSame('', (string) $user->fresh()?->password);
    }

    private function signedInUser(): BaseCentralUser
    {
        $user = CentralUser::factory()->create(['password' => Hash::make('Str0ng-Passw0rd!')]);

        $this->actingAsCentralUser($user);

        return $user;
    }

    private function insertSession(string $id, int $userId): void
    {
        $key = 'login_'.Context::Central->guard().'_'.sha1(Auth::guard(Context::Central->guard())::class);

        DB::connection('central')->table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Firefox/130.0',
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

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Invitations;

use Nvade\Numerosis\Contracts\Invitations\CreatesInvitedUser;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Livewire\Invitations\Accept;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use RyanChandler\LaravelCloudflareTurnstile\Facades\Turnstile;
use Nvade\Numerosis\Tests\TestCase;

class AcceptTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::forceCreate(['id' => 'test-'.uniqid()]);
        $this->tenant->domains()->create([
            'id' => $this->tenant->id,
            'domain' => $this->tenant->id.'.localhost',
        ]);
    }

    public function test_it_accepts_the_invitation_without_a_turnstile_token_when_disabled(): void
    {
        TurnstileFeature::forceForTesting(false);

        $this->tenant->run(function () {
            $invitation = Invitation::factory()->create([
                'role' => 'member',
                'expires_at' => now()->addDays(1),
                'accepted_at' => null,
            ]);

            Livewire::test(Accept::class, ['token' => $invitation->token])
                ->set('name', 'New User')
                ->set('password', 'password')
                ->set('password_confirmation', 'password')
                ->call('accept')
                ->assertHasNoErrors();
        });
    }

    public function test_it_rejects_acceptance_without_a_turnstile_token_when_enabled(): void
    {
        TurnstileFeature::forceForTesting(true);
        Turnstile::fake()->fail();

        $this->tenant->run(function () {
            $invitation = Invitation::factory()->create([
                'expires_at' => now()->addDays(1),
                'accepted_at' => null,
            ]);

            Livewire::test(Accept::class, ['token' => $invitation->token])
                ->set('name', 'New User')
                ->set('password', 'password')
                ->set('password_confirmation', 'password')
                ->call('accept')
                ->assertHasErrors(['turnstileResponse']);
        });
    }

    public function test_it_accepts_the_invitation_with_a_valid_turnstile_token_when_enabled(): void
    {
        TurnstileFeature::forceForTesting(true);
        Turnstile::fake();

        $this->tenant->run(function () {
            $invitation = Invitation::factory()->create([
                'role' => 'member',
                'expires_at' => now()->addDays(1),
                'accepted_at' => null,
            ]);

            Livewire::test(Accept::class, ['token' => $invitation->token])
                ->set('name', 'New User')
                ->set('password', 'password')
                ->set('password_confirmation', 'password')
                ->set('turnstileResponse', Turnstile::dummy())
                ->call('accept')
                ->assertHasNoErrors();
        });
    }

    public function test_it_accepts_without_a_password_for_a_central_user_new_to_this_tenant(): void
    {
        TurnstileFeature::forceForTesting(false);

        $centralUser = CentralUser::factory()->create();

        $this->tenant->run(function () use ($centralUser) {
            $invitation = Invitation::factory()->create([
                'email' => $centralUser->email,
                'role' => 'member',
                'expires_at' => now()->addDays(1),
                'accepted_at' => null,
            ]);

            Livewire::test(Accept::class, ['token' => $invitation->token])
                ->assertSet('existingUser', true)
                ->call('accept')
                ->assertHasNoErrors();

            $this->assertTrue(TenantUser::where('global_id', $centralUser->global_id)->exists());
        });
    }

    /**
     * A consumer rebinds this to change how an invited user with no existing
     * account is created, without forking Accept.
     */
    public function test_a_consumer_can_override_how_the_invited_user_is_created(): void
    {
        TurnstileFeature::forceForTesting(false);

        $this->app->singleton(CreatesInvitedUser::class, fn () => new class implements CreatesInvitedUser
        {
            public function create(Invitation $invitation, string $name, string $password): CentralUser
            {
                return CentralUser::create([
                    'name' => 'Overridden: '.$name,
                    'email' => $invitation->email,
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ]);
            }
        });

        $this->tenant->run(function () {
            $invitation = Invitation::factory()->create([
                'role' => 'member',
                'expires_at' => now()->addDays(1),
                'accepted_at' => null,
            ]);

            Livewire::test(Accept::class, ['token' => $invitation->token])
                ->set('name', 'New User')
                ->set('password', 'password')
                ->set('password_confirmation', 'password')
                ->call('accept')
                ->assertHasNoErrors();

            $this->assertDatabaseHas('users', [
                'email' => $invitation->email,
                'name' => 'Overridden: New User',
            ]);
        });
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth\Social;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Actions\Auth\Social\ResolveSocialUser;
use Nvade\Numerosis\Data\Auth\SocialUserData;
use Nvade\Numerosis\Enums\Auth\SocialProvider;
use Nvade\Numerosis\Events\Auth\SocialAccountLinked;
use Nvade\Numerosis\Events\Auth\SocialAccountUnlinked;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\SocialAccount;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `google` is the provider `TestCase::getEnvironmentSetUp()` configures a
 * client id for; everything else (`github`, `gitlab`, `facebook`) is
 * deliberately left unconfigured so the 404 test has something to hit.
 */
class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_identity_creates_a_central_user_and_logs_them_in(): void
    {
        $data = $this->socialData(providerId: 'google-new-'.uniqid(), email: 'newuser-'.uniqid().'@example.com', emailVerified: true);

        ResolveSocialUser::mock()->shouldReceive('handle')->andReturn($data);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect();

        $user = CentralUser::where('email', $data->email)->firstOrFail();
        $this->assertAuthenticatedAs($user, Config::string('numerosis.auth.guards.central'));
        $this->assertNotNull($user->email_verified_at);

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->getKey(),
            'provider' => SocialProvider::Google->value,
            'provider_id' => $data->providerId,
        ], 'central');
    }

    public function test_a_returning_identity_logs_in_without_creating_a_duplicate(): void
    {
        $providerId = 'google-returning-'.uniqid();
        $email = 'returning-'.uniqid().'@example.com';

        $user = CentralUser::factory()->create(['email' => $email]);
        SocialAccount::create([
            'user_id' => $user->getKey(),
            'provider' => SocialProvider::Google,
            'provider_id' => $providerId,
        ]);

        ResolveSocialUser::mock()->shouldReceive('handle')->andReturn(
            $this->socialData(providerId: $providerId, email: $email, emailVerified: true),
        );

        $this->get('/auth/google/callback')->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh(), Config::string('numerosis.auth.guards.central'));
        $this->assertSame(1, SocialAccount::where('provider_id', $providerId)->count());
    }

    /**
     * The account-takeover regression: an unverified provider email must
     * never silently attach to whoever already owns that address locally.
     */
    public function test_an_unverified_provider_email_refuses_to_link_and_redirects_to_login(): void
    {
        $email = 'victim-'.uniqid().'@example.com';

        CentralUser::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
        ]);

        ResolveSocialUser::mock()->shouldReceive('handle')->andReturn(
            $this->socialData(providerId: 'google-attacker-'.uniqid(), email: $email, emailVerified: false),
        );

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('login'));
        $this->assertGuest(Config::string('numerosis.auth.guards.central'));
        $this->assertSame(0, SocialAccount::where('email', $email)->count());
    }

    /**
     * A GitHub account with no verified primary address, or a Facebook user
     * who declined the email permission, reaches the callback with a null
     * address. `users.email` is nullable so this is an account rather than a
     * 500 on a NOT NULL insert.
     */
    public function test_a_provider_that_returns_no_email_creates_an_account_and_logs_in(): void
    {
        $providerId = 'google-noemail-'.uniqid();

        ResolveSocialUser::mock()->shouldReceive('handle')->andReturn(
            $this->socialData(providerId: $providerId, email: null, emailVerified: false),
        );

        $this->get('/auth/google/callback')->assertRedirect();

        $account = SocialAccount::where('provider_id', $providerId)->sole();
        $user = CentralUser::findOrFail($account->user_id);

        $this->assertAuthenticatedAs($user, Config::string('numerosis.auth.guards.central'));
        $this->assertNull($user->email);
        $this->assertNull($user->email_verified_at);
    }

    /**
     * Two such accounts coexist: the unique index on a nullable column
     * permits many NULLs, which is what makes the case above safe to repeat.
     */
    public function test_two_accounts_without_an_email_can_both_exist(): void
    {
        // One mock with two consecutive returns: a second `shouldReceive()`
        // on the same mock never matches, the first expectation answers both.
        ResolveSocialUser::mock()->shouldReceive('handle')->andReturn(
            $this->socialData(providerId: 'google-noemail-a-'.uniqid(), email: null, emailVerified: false),
            $this->socialData(providerId: 'google-noemail-b-'.uniqid(), email: null, emailVerified: false),
        );

        $this->get('/auth/google/callback')->assertRedirect();

        // Still signed in, the callback would take its connect-a-provider
        // branch instead of registering a second account.
        Auth::guard(Config::string('numerosis.auth.guards.central'))->logout();

        $this->get('/auth/google/callback')->assertRedirect();

        $this->assertSame(2, CentralUser::whereNull('email')->count());
    }

    /**
     * A provider that hands back an address on first login and omits it later
     * must not blank the stored one: `refreshTokens()` writes credentials, not
     * the profile.
     */
    public function test_a_later_callback_without_an_email_keeps_the_stored_address(): void
    {
        $providerId = 'google-refresh-'.uniqid();
        $email = 'refresh-'.uniqid().'@example.com';

        $user = CentralUser::factory()->create(['email' => $email]);
        SocialAccount::create([
            'user_id' => $user->getKey(),
            'provider' => SocialProvider::Google,
            'provider_id' => $providerId,
            'email' => $email,
            'name' => 'Original Name',
        ]);

        ResolveSocialUser::mock()->shouldReceive('handle')->andReturn(
            $this->socialData(providerId: $providerId, email: null, emailVerified: false),
        );

        $this->get('/auth/google/callback')->assertRedirect();

        $account = SocialAccount::where('provider_id', $providerId)->sole();

        $this->assertSame($email, $account->email);
        $this->assertSame($email, $user->fresh()?->email);
    }

    public function test_an_unconfigured_provider_404s_at_routing(): void
    {
        $this->get('/auth/github/redirect')->assertNotFound();
        $this->get('/auth/github/callback')->assertNotFound();
    }

    public function test_email_verified_at_is_set_only_when_the_provider_verified_it(): void
    {
        $data = $this->socialData(providerId: 'google-unverified-'.uniqid(), email: 'unverified-'.uniqid().'@example.com', emailVerified: false);

        ResolveSocialUser::mock()->shouldReceive('handle')->andReturn($data);

        $this->get('/auth/google/callback')->assertRedirect();

        $user = CentralUser::where('email', $data->email)->firstOrFail();
        $this->assertNull($user->email_verified_at);
    }

    public function test_unlinking_the_last_credential_with_no_password_is_refused(): void
    {
        $user = CentralUser::factory()->create(['password' => null]);
        $socialAccount = SocialAccount::create([
            'user_id' => $user->getKey(),
            'provider' => SocialProvider::Google,
            'provider_id' => 'google-only-'.uniqid(),
        ]);

        $this->actingAsCentralUser($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from('settings/profile')
            ->delete("/settings/social/{$socialAccount->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('social_accounts', ['id' => $socialAccount->id], 'central');
    }

    public function test_a_new_identity_dispatches_social_account_linked(): void
    {
        Event::fake([SocialAccountLinked::class]);

        $data = $this->socialData(providerId: 'google-linked-'.uniqid(), email: 'linked-'.uniqid().'@example.com', emailVerified: true);

        ResolveSocialUser::mock()->shouldReceive('handle')->andReturn($data);

        $this->get('/auth/google/callback')->assertRedirect();

        Event::assertDispatched(SocialAccountLinked::class, fn (SocialAccountLinked $event): bool => $event->provider === SocialProvider::Google->value);
    }

    public function test_unlinking_dispatches_social_account_unlinked(): void
    {
        Event::fake([SocialAccountUnlinked::class]);

        $user = CentralUser::factory()->create(['password' => bcrypt('password')]);
        $socialAccount = SocialAccount::create([
            'user_id' => $user->getKey(),
            'provider' => SocialProvider::Google,
            'provider_id' => 'google-unlink-'.uniqid(),
        ]);

        $this->actingAsCentralUser($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete("/settings/social/{$socialAccount->id}")
            ->assertRedirect();

        Event::assertDispatched(SocialAccountUnlinked::class, fn (SocialAccountUnlinked $event): bool => $event->globalUserId === $user->global_id);
    }

    public function test_unlinking_someone_elses_account_is_refused(): void
    {
        $owner = CentralUser::factory()->create();
        $attacker = CentralUser::factory()->create();

        $socialAccount = SocialAccount::create([
            'user_id' => $owner->getKey(),
            'provider' => SocialProvider::Google,
            'provider_id' => 'google-owned-'.uniqid(),
        ]);

        $this->actingAsCentralUser($attacker)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete("/settings/social/{$socialAccount->id}")
            ->assertForbidden();
    }

    /**
     * The positive half of the conditional email link. Both sides verified is
     * the only combination that may attach an OAuth identity to an account
     * found by address.
     */
    public function test_a_verified_provider_email_links_to_a_verified_local_account(): void
    {
        $email = 'verified-both-'.uniqid().'@example.com';

        $user = CentralUser::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
        ]);

        ResolveSocialUser::mock()->shouldReceive('handle')->andReturn(
            $this->socialData(providerId: 'google-link-'.uniqid(), email: $email, emailVerified: true),
        );

        $this->get('/auth/google/callback')->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh(), Config::string('numerosis.auth.guards.central'));
        $this->assertSame(1, SocialAccount::where('user_id', $user->getKey())->count());
    }

    public function test_an_authenticated_user_connects_a_provider(): void
    {
        $user = CentralUser::factory()->create(['password' => bcrypt('password')]);
        $providerId = 'google-connect-'.uniqid();

        ResolveSocialUser::mock()->shouldReceive('handle')->andReturn(
            $this->socialData(providerId: $providerId, email: $user->email, emailVerified: true),
        );

        $this->actingAsCentralUser($user)
            ->get('/auth/google/callback')
            ->assertRedirect(route('settings.connected-accounts'));

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->getKey(),
            'provider_id' => $providerId,
        ], 'central');
    }

    /**
     * `unique(provider, provider_id)` used to surface here as an uncaught
     * `QueryException`, so connecting an identity someone else had already
     * connected was a 500 instead of a message.
     */
    public function test_connecting_an_identity_owned_by_another_user_is_refused(): void
    {
        $providerId = 'google-contested-'.uniqid();

        $owner = CentralUser::factory()->create();
        SocialAccount::create([
            'user_id' => $owner->getKey(),
            'provider' => SocialProvider::Google,
            'provider_id' => $providerId,
        ]);

        $attacker = CentralUser::factory()->create(['password' => bcrypt('password')]);

        ResolveSocialUser::mock()->shouldReceive('handle')->andReturn(
            $this->socialData(providerId: $providerId, email: $attacker->email, emailVerified: true),
        );

        $this->actingAsCentralUser($attacker)
            ->get('/auth/google/callback')
            ->assertRedirect(route('settings.connected-accounts'))
            ->assertSessionHas('status', 'That Google account is already connected to another user.');

        $this->assertSame(
            $owner->getKey(),
            SocialAccount::where('provider_id', $providerId)->sole()->user_id,
        );
    }

    /**
     * Plain `password.confirm` on this route was unsatisfiable for an
     * OAuth-only account: `Hash::check($password, null)` never returns true,
     * so the user could not disconnect anything.
     * `Http\Middleware\RequirePasswordIfSet` passes them through, and
     * `SocialAccountPolicy` is what still refuses their last credential.
     */
    public function test_a_passwordless_user_can_unlink_a_spare_account_without_confirming(): void
    {
        $user = CentralUser::factory()->create(['password' => null]);

        $spare = SocialAccount::create([
            'user_id' => $user->getKey(),
            'provider' => SocialProvider::Google,
            'provider_id' => 'google-spare-'.uniqid(),
        ]);

        SocialAccount::create([
            'user_id' => $user->getKey(),
            'provider' => SocialProvider::GitHub,
            'provider_id' => 'github-keep-'.uniqid(),
        ]);

        $this->actingAsCentralUser($user)
            ->delete("/settings/social/{$spare->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('social_accounts', ['id' => $spare->id], 'central');
    }

    public function test_a_user_with_a_password_must_still_confirm_before_unlinking(): void
    {
        $user = CentralUser::factory()->create(['password' => bcrypt('password')]);

        $socialAccount = SocialAccount::create([
            'user_id' => $user->getKey(),
            'provider' => SocialProvider::Google,
            'provider_id' => 'google-confirm-'.uniqid(),
        ]);

        $this->actingAsCentralUser($user)
            ->delete("/settings/social/{$socialAccount->id}")
            ->assertRedirect(route('password.confirm'));

        $this->assertDatabaseHas('social_accounts', ['id' => $socialAccount->id], 'central');
    }

    public function test_the_social_limiter_refuses_an_eleventh_redirect_in_a_minute(): void
    {
        foreach (range(1, 10) as $ignored) {
            $this->get('/auth/google/redirect')->assertRedirect();
        }

        $this->get('/auth/google/redirect')->assertStatus(429);
    }

    /**
     * `<x-numerosis::auth.social-buttons />` carries the feature gate and the
     * divider itself, so the guest screens call it unconditionally. Both
     * halves are asserted here because a gate that never closes renders an
     * empty divider above the email form on every install with social off.
     */
    public function test_the_guest_screens_render_the_provider_buttons_and_the_divider(): void
    {
        foreach (['/login', '/register'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertSee('Google')
                ->assertSee('Or continue with email');
        }
    }

    public function test_the_guest_screens_render_neither_when_the_feature_is_off(): void
    {
        FeatureRegistry::forceForTesting([]);

        foreach (['/login', '/register'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertDontSee('Google')
                ->assertDontSee('Or continue with email');
        }
    }

    private function socialData(string $providerId, ?string $email, bool $emailVerified): SocialUserData
    {
        return new SocialUserData(
            provider: SocialProvider::Google,
            providerId: $providerId,
            name: 'Social User',
            email: $email,
            emailVerified: $emailVerified,
            avatarUrl: null,
            token: 'token',
            refreshToken: null,
            expiresAt: null,
        );
    }
}

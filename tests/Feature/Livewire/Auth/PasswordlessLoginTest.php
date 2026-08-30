<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Auth;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Contracts\Auth\AuthenticatesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\ResolvesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\ResolvesPostLoginRedirectUrl;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Tests\TestCase;
use Nvade\NumerosisAuthUi\Livewire\PasswordlessLogin;
use RyanChandler\LaravelCloudflareTurnstile\Facades\Turnstile;

class PasswordlessLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_a_code_without_a_turnstile_token_when_disabled(): void
    {
        TurnstileFeature::forceForTesting(false);

        $user = CentralUser::factory()->create();

        Livewire::test(PasswordlessLogin::class)
            ->set('email', $user->email)
            ->call('submitEmail')
            ->assertHasNoErrors();
    }

    public function test_it_rejects_the_request_without_a_turnstile_token_when_enabled(): void
    {
        TurnstileFeature::forceForTesting(true);
        Turnstile::fake()->fail();

        $user = CentralUser::factory()->create();

        Livewire::test(PasswordlessLogin::class)
            ->set('email', $user->email)
            ->call('submitEmail')
            ->assertHasErrors(['turnstileResponse']);
    }

    public function test_it_sends_a_code_with_a_valid_turnstile_token_when_enabled(): void
    {
        TurnstileFeature::forceForTesting(true);
        Turnstile::fake();

        $user = CentralUser::factory()->create();

        Livewire::test(PasswordlessLogin::class)
            ->set('email', $user->email)
            ->set('turnstileResponse', Turnstile::dummy())
            ->call('submitEmail')
            ->assertHasNoErrors();
    }

    /**
     * The regression that matters most on this component: submitOneTimePassword()
     * had its OneTimePasswordRule validation commented out, so it authenticated
     * on `email` alone. `email` is a plain public property and every public
     * method on a Livewire component is directly invokable by the client, so
     * this was reachable without ever calling submitEmail() — an account
     * takeover on every tenant subdomain, since TenantAdminPanelProvider
     * registers this class as the panel's login page.
     */
    public function test_it_refuses_to_authenticate_without_a_one_time_password(): void
    {
        TurnstileFeature::forceForTesting(false);

        $user = CentralUser::factory()->create();

        Livewire::test(PasswordlessLogin::class)
            ->set('email', $user->email)
            ->call('submitOneTimePassword')
            ->assertHasErrors('oneTimePassword');

        $this->assertGuest();
    }

    public function test_it_refuses_a_one_time_password_that_was_never_issued(): void
    {
        TurnstileFeature::forceForTesting(false);

        $user = CentralUser::factory()->create();

        Livewire::test(PasswordlessLogin::class)
            ->set('email', $user->email)
            ->set('oneTimePassword', '000000')
            ->call('submitOneTimePassword')
            ->assertHasErrors('oneTimePassword');

        $this->assertGuest();
    }

    public function test_it_authenticates_with_a_valid_one_time_password(): void
    {
        TurnstileFeature::forceForTesting(false);

        $user = CentralUser::factory()->create();
        $oneTimePassword = $user->createOneTimePassword();

        Livewire::test(PasswordlessLogin::class)
            ->set('email', $user->email)
            ->set('oneTimePassword', $oneTimePassword->password)
            ->call('submitOneTimePassword')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    /**
     * A six-digit code is guessable at request speed; the package's own
     * limiter only throttles code *sending*, never verification.
     */
    public function test_it_rate_limits_failed_one_time_password_attempts(): void
    {
        TurnstileFeature::forceForTesting(false);

        $user = CentralUser::factory()->create();

        foreach (range(1, 5) as $attempt) {
            Livewire::test(PasswordlessLogin::class)
                ->set('email', $user->email)
                ->set('oneTimePassword', '000000')
                ->call('submitOneTimePassword')
                ->assertHasErrors('oneTimePassword');
        }

        Livewire::test(PasswordlessLogin::class)
            ->set('email', $user->email)
            ->set('oneTimePassword', '000000')
            ->call('submitOneTimePassword')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    /**
     * This is the single-component consolidation's reason for existing: before
     * it, this class had no findUser() override and inherited the parent's
     * config('auth.providers.users.model') lookup — always CentralUser, even
     * inside a tenant. On an actual tenant subdomain a Tenant\User could never
     * log in through this component. Now it uses TenancyAwareUserModel, same
     * as the (now-deleted) central-only anonymous component already did.
     */
    public function test_it_authenticates_a_tenant_user_when_run_inside_tenant_context(): void
    {
        TurnstileFeature::forceForTesting(false);

        $tenant = Tenant::factory()->create();
        tenancy()->initialize($tenant);

        $user = TenantUser::factory()->create();
        $oneTimePassword = $user->createOneTimePassword();

        Livewire::test(PasswordlessLogin::class)
            ->set('email', $user->email)
            ->set('oneTimePassword', $oneTimePassword->password)
            ->call('submitOneTimePassword')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user, 'tenant');
    }

    /**
     * Central /login used to serve a separate anonymous page component
     * (resources/views/pages/auth/⚡passwordless-login.blade.php); it now
     * routes straight to this class, same as the tenant panel does.
     */
    public function test_central_login_route_serves_the_passwordless_login_component(): void
    {
        $response = $this->get('/login')->assertOk();

        // assertSeeLivewire() is a runtime macro Livewire registers on
        // TestResponse (SupportTesting.php) — invisible to static analysis
        // since it's added outside any analysed path.
        // @phpstan-ignore method.notFound
        $response->assertSeeLivewire(PasswordlessLogin::class);
    }

    /**
     * These three bindings are the whole point of the refactor: a consumer
     * rebinds the interface in their own service provider to change how the
     * login flow looks users up, authenticates, or redirects, without
     * forking PasswordlessLogin.
     */
    public function test_a_consumer_can_override_how_the_login_candidate_is_resolved(): void
    {
        TurnstileFeature::forceForTesting(false);

        $user = CentralUser::factory()->create();
        $decoy = CentralUser::factory()->create();

        $this->app->singleton(ResolvesLoginCandidate::class, fn () => new readonly class($decoy) implements ResolvesLoginCandidate
        {
            public function __construct(private Authenticatable $decoy) {}

            public function find(string $email): Authenticatable
            {
                return $this->decoy;
            }
        });

        $oneTimePassword = $decoy->createOneTimePassword();

        Livewire::test(PasswordlessLogin::class)
            ->set('email', $user->email)
            ->set('oneTimePassword', $oneTimePassword->password)
            ->call('submitOneTimePassword')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($decoy);
    }

    public function test_a_consumer_can_override_how_the_login_candidate_is_authenticated(): void
    {
        TurnstileFeature::forceForTesting(false);

        $user = CentralUser::factory()->create();
        $oneTimePassword = $user->createOneTimePassword();

        $this->app->singleton(AuthenticatesLoginCandidate::class, fn () => new class implements AuthenticatesLoginCandidate
        {
            public function authenticate(Authenticatable $user, bool $remember): void
            {
                // Deliberately does not establish a session, unlike the default.
            }
        });

        Livewire::test(PasswordlessLogin::class)
            ->set('email', $user->email)
            ->set('oneTimePassword', $oneTimePassword->password)
            ->call('submitOneTimePassword')
            ->assertHasNoErrors();

        $this->assertGuest();
    }

    public function test_a_consumer_can_override_the_post_login_redirect_url(): void
    {
        TurnstileFeature::forceForTesting(false);

        $user = CentralUser::factory()->create();
        $oneTimePassword = $user->createOneTimePassword();

        $this->app->singleton(ResolvesPostLoginRedirectUrl::class, fn () => new class implements ResolvesPostLoginRedirectUrl
        {
            public function url(): string
            {
                return '/custom-landing';
            }
        });

        Livewire::test(PasswordlessLogin::class)
            ->set('email', $user->email)
            ->set('oneTimePassword', $oneTimePassword->password)
            ->call('submitOneTimePassword')
            ->assertRedirect('/custom-landing');
    }
}

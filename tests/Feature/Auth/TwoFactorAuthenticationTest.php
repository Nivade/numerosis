<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'password';

    /**
     * `HostConfig::fortifyFeatures()` turns the feature on with
     * `confirm => true`, which is what makes an unconfirmed secret inert.
     */
    public function test_an_unconfirmed_secret_does_not_challenge_at_login(): void
    {
        $user = $this->user();
        resolve(EnableTwoFactorAuthentication::class)($user);

        $this->login($user)->assertRedirect();

        $this->assertAuthenticated(Context::Central->guard());
    }

    public function test_a_confirmed_factor_challenges_at_login(): void
    {
        $user = $this->withConfirmedTwoFactor($this->user());

        $this->login($user)->assertRedirect(route('two-factor.login'));

        $this->assertGuest(Context::Central->guard());
    }

    public function test_the_challenge_authenticates_with_a_current_code(): void
    {
        $user = $this->withConfirmedTwoFactor($this->user());

        $this->login($user);

        $this->post('/two-factor-challenge', ['code' => $this->currentTwoFactorCode($user)])
            ->assertRedirect();

        $this->assertAuthenticated(Context::Central->guard());
    }

    public function test_a_recovery_code_logs_in_once_and_is_then_dead(): void
    {
        $user = $this->withConfirmedTwoFactor($this->user());
        $code = $user->recoveryCodes()[0];

        $this->login($user);
        $this->post('/two-factor-challenge', ['recovery_code' => $code]);
        $this->assertAuthenticated(Context::Central->guard());

        Auth::guard(Context::Central->guard())->logout();
        $this->flushSession();

        $this->login($user);
        $this->post('/two-factor-challenge', ['recovery_code' => $code])
            ->assertSessionHasErrors();

        $this->assertGuest(Context::Central->guard());
    }

    /**
     * The limiter is keyed on the account the password step challenged, not on
     * the address: a lockout follows the pending login rather than the IP, and
     * a second account from the same address still gets its own bucket.
     * Deleting the `login.id` segment from
     * `NumerosisServiceProvider::twoFactorThrottleKey()` turns the second half
     * red.
     */
    public function test_the_challenge_limiter_locks_the_pending_login_and_not_the_address(): void
    {
        $user = $this->withConfirmedTwoFactor($this->user());

        $this->login($user);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/two-factor-challenge', ['code' => '000000']);
        }

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertTooManyRequests();

        $this->flushSession();
        $this->login($this->withConfirmedTwoFactor($this->user()));

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertFound();
    }

    public function test_the_management_routes_are_registered_on_the_central_domain_only(): void
    {
        $this->assertTrue(resolve(Router::class)->has('two-factor.enable'));

        $paths = collect(resolve(Router::class)->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => $route->getName() === 'two-factor.enable')
            ->map(fn ($route): ?string => $route->getDomain())
            ->all();

        $this->assertNotContains(null, $paths, 'A domain-less copy is the tenant group registering enrolment.');
    }

    private function user(): BaseCentralUser
    {
        /** @var BaseCentralUser $user */
        $user = CentralUser::factory()->create(['password' => Hash::make(self::PASSWORD)]);

        return $user;
    }

    /** @return TestResponse<Response> */
    private function login(BaseCentralUser $user): TestResponse
    {
        return $this->post('/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);
    }
}

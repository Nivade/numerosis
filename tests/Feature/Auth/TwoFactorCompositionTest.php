<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Features\Auth\OneTimePasswordFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Tests\TestCase;

/**
 * An emailed one-time password replaces the password step rather than adding a
 * factor after it, so layering the authenticator challenge on top would ask one
 * person for two codes. The tenant and staff gates still read the account's own
 * factor, which is what keeps email possession from satisfying a requirement
 * the tenant made for a device.
 */
class TwoFactorCompositionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        FeatureRegistry::register(OneTimePasswordFeature::class);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        FeatureRegistry::resetRegisteredForTesting();
    }

    public function test_an_emailed_code_is_not_followed_by_the_authenticator_challenge(): void
    {
        Notification::fake();

        $user = $this->withConfirmedTwoFactor($this->user());

        $this->post(route('login.store'), ['email' => $user->email])
            ->assertRedirect(route('one-time-password.login'));

        $this->post(route('one-time-password.login.store'), ['code' => $this->latestCodeFor($user)])
            ->assertRedirect();

        $this->assertAuthenticated();
    }

    /**
     * The address-only leg never reaches `RedirectIfTwoFactorAuthenticatable`,
     * whose own credential check would reject a request carrying no password.
     */
    public function test_the_emailed_leg_runs_ahead_of_the_authenticator_step(): void
    {
        Notification::fake();

        $user = $this->withConfirmedTwoFactor($this->user());

        $this->post(route('login.store'), ['email' => $user->email])
            ->assertSessionHasNoErrors();

        $this->assertSame($user->email, session('login.email'));
    }

    private function user(): BaseCentralUser
    {
        /** @var BaseCentralUser $user */
        $user = CentralUser::factory()->create();

        return $user;
    }

    private function latestCodeFor(BaseCentralUser $user): string
    {
        $code = $user->oneTimePasswords()->latest('id')->first()?->getAttribute('password');

        $this->assertIsString($code);

        return $code;
    }
}

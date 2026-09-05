<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use App\Models\Central\CentralUser;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Features\Auth\OneTimePasswordFeature;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Tests\TestCase;
use Spatie\OneTimePasswords\Notifications\OneTimePasswordNotification;

/**
 * Regression coverage for Phase 5 of
 * `.claude/plans/archive/humming-nibbling-flame.md`: `OneTimePasswordFeature`
 * replaces the password step of Fortify's `authenticateThrough()` pipeline
 * rather than adding a factor after it.
 *
 * The feature is added through `Features::register()` rather than
 * `forceForTesting()` so the app boots with the **default** feature list plus
 * this one. `forceForTesting([OneTimePasswordFeature::class])` would silently
 * turn every other feature off, and an OTP screen that only works when
 * nothing else is registered is not the thing being claimed here.
 *
 * Three properties the deleted `PasswordlessLogin` Livewire component did not
 * have, each mutation-tested against the code it guards rather than merely
 * observed to pass:
 *
 * - the code check cannot be bypassed by naming a victim in the request —
 *   the address comes from the session and nowhere else
 *   (`test_the_challenge_ignores_a_request_supplied_email`);
 * - a wrong code does not authenticate
 *   (`test_an_incorrect_code_does_not_authenticate`);
 * - the tenant leg works, which is the only thing that proves the tenant copy
 *   of the `one_time_passwords` migration is still doing its job
 *   (`test_a_tenant_user_authenticates_on_a_tenant_subdomain`). Its absence
 *   is `SQLSTATE 1146` at the moment a code is sent, and nothing else in the
 *   suite writes that table from tenant context — see `.ai/rules/auth-login.md`.
 */
class OneTimePasswordLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        Features::register(OneTimePasswordFeature::class);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Features::$registered is a plain static, so without this the
        // feature leaks into whichever test class runs next in this process.
        Features::resetRegisteredForTesting();
    }

    public function test_submitting_the_login_email_sends_a_code_and_redirects_to_the_challenge(): void
    {
        Notification::fake();

        $user = CentralUser::factory()->create();

        $response = $this->post(route('login.store'), ['email' => $user->email]);

        $response->assertRedirect(route('one-time-password.login'));
        $this->assertSame($user->email, session('login.email'));
        Notification::assertSentTo($user, OneTimePasswordNotification::class);
        $this->assertGuest();
    }

    public function test_the_login_screen_asks_for_a_code_rather_than_a_password(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();

        // The field's own `required` attribute — not the request rules — is
        // what makes an email-only submission impossible from a browser, so
        // dropping `password` from NumerosisLoginRequest without dropping it
        // from the form leaves the feature reachable only by hand-built POST.
        $response->assertDontSee('name="password"', escape: false);
    }

    public function test_the_correct_code_authenticates_the_user(): void
    {
        $user = CentralUser::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email]);

        $response = $this->post(route('one-time-password.login.store'), [
            'code' => $this->latestCodeFor($user),
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_incorrect_code_does_not_authenticate(): void
    {
        $user = CentralUser::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email]);

        $response = $this->post(route('one-time-password.login.store'), ['code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    /**
     * The takeover shape recorded in `.ai/rules/auth-login.md`: reaching the
     * verify step proves nothing about which step ran before it, so a caller
     * naming a victim directly must get nowhere — **holding a valid code for
     * that victim**, which is the only version of this test that distinguishes
     * "reads the session" from "reads the request". Posting no email at all
     * passes either way, because an empty address resolves to no user under
     * both readings.
     */
    public function test_the_challenge_ignores_a_request_supplied_email(): void
    {
        $victim = CentralUser::factory()->create();

        $this->post(route('login.store'), ['email' => $victim->email]);
        $code = $this->latestCodeFor($victim);

        // Everything the send leg put in the session is gone; only what the
        // attacker sends is left.
        $this->flushSession();

        $response = $this->post(route('one-time-password.login.store'), [
            'email' => $victim->email,
            'code' => $code,
        ]);

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_challenge_screen_redirects_to_login_without_a_pending_address(): void
    {
        $this->get(route('one-time-password.login'))->assertRedirect(route('login'));
    }

    /**
     * There is no password to check on this endpoint, so a per-outcome
     * response would make it an unauthenticated account-existence oracle.
     * Both legs have to stay indistinguishable: the send redirects either
     * way, and the verify fails on `code` either way rather than redirecting
     * only for an address nobody holds.
     */
    public function test_an_unknown_address_is_indistinguishable_from_a_known_one(): void
    {
        Notification::fake();

        $known = CentralUser::factory()->create();

        $knownResponse = $this->post(route('login.store'), ['email' => $known->email]);
        $this->flushSession();
        $unknownResponse = $this->post(route('login.store'), ['email' => 'nobody-'.uniqid().'@example.com']);

        $knownResponse->assertRedirect(route('one-time-password.login'));
        $unknownResponse->assertRedirect(route('one-time-password.login'));
        $unknownResponse->assertSessionHasNoErrors();

        $this->post(route('one-time-password.login.store'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    /**
     * `HasOneTimePasswords` writes through whatever connection is default,
     * which is `tenant` for the whole of a tenant-subdomain request. The
     * central `one_time_passwords` migration is not enough; the tenant copy
     * in `database/migrations/tenant/` is what this exercises, and its
     * absence is a 1146 at send time rather than anything the central-domain
     * tests above would notice.
     */
    public function test_a_tenant_user_authenticates_on_a_tenant_subdomain(): void
    {
        $id = 'otp-'.uniqid();
        $domain = $this->tenantDomain($id);
        $email = 'tenant-'.uniqid().'@example.com';

        $tenant = $this->createTenantWithDomain($id, 'OTP Tenant');
        $this->createTenantUser($tenant, ['email' => $email]);

        $this->post('http://'.$domain.'/login', ['email' => $email])
            ->assertRedirectContains('/one-time-password-challenge');

        $code = null;

        $tenant->run(function () use ($email, &$code): void {
            $user = TenantUser::firstWhere('email', $email);
            $code = $user?->oneTimePasswords()->latest('id')->first()?->getAttribute('password');
        });

        $this->assertIsString($code, 'No one-time password was written to the tenant database.');

        $this->post('http://'.$domain.'/one-time-password-challenge', ['code' => $code])
            ->assertRedirect();

        $tenant->run(function (): void {
            $this->assertTrue(Auth::guard('tenant')->check());
        });
    }

    /**
     * Queried through the relation rather than `OneTimePassword::query()`:
     * `HasOneTimePasswords`'s `oneTimePasswords()` inherits `CentralUser`'s
     * own `central` connection (Eloquent's `newRelatedInstance()` does this
     * whenever the related model has no connection of its own), while a bare
     * `OneTimePassword::query()` hits `config('database.default')` (`mysql`)
     * instead — a different PDO connection to the same schema.
     * `RefreshDatabase` only wraps the default connection in a transaction,
     * so under the default REPEATABLE READ isolation the `mysql` connection's
     * open transaction never sees a row committed through `central`.
     */
    private function latestCodeFor(CentralUser $user): string
    {
        $code = $user->oneTimePasswords()->latest('id')->first()?->getAttribute('password');

        $this->assertIsString($code);

        return $code;
    }
}

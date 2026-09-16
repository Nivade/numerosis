<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rules\Password;
use Nvade\Numerosis\Boot\HostConfig;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `HostConfig::passwordDefaults()` adds `uncompromised()` to
 * `Password::defaults()`, which every `Data\Auth\*` rule set reads.
 * `Tests\TestCase` turns the flag off for the rest of the suite so no other
 * test calls the range API, so each test here opts back in by hand.
 */
class CompromisedPasswordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The SHA-1 of `password`, split the way the range API answers: five
     * characters of prefix in the URL, the remaining 35 in the body.
     */
    private const string BREACHED_PASSWORD = 'password';

    private const string BREACHED_SUFFIX = '1E4C9B93F3F0682250B6CF8331B7EE68FD8';

    protected function tearDown(): void
    {
        Password::defaults(static fn (): Password => Password::min(8));

        parent::tearDown();
    }

    public function test_a_breached_password_is_rejected_at_registration(): void
    {
        $this->enableTheCheck();

        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response(self::BREACHED_SUFFIX.':38221'),
        ]);

        $this->from('/register')->post('/register', $this->registration())
            ->assertRedirect('/register')
            ->assertSessionHasErrors('password');
    }

    public function test_the_check_fails_open_when_the_api_is_unreachable(): void
    {
        $this->enableTheCheck();

        Http::fake(fn () => throw new ConnectionException('unreachable'));

        $this->post('/register', $this->registration())->assertSessionHasNoErrors();
    }

    public function test_the_flag_disables_the_check(): void
    {
        Config::set('numerosis.auth.check_compromised_passwords', false);
        HostConfig::apply();

        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response(self::BREACHED_SUFFIX.':38221'),
        ]);

        $this->post('/register', $this->registration())->assertSessionHasNoErrors();

        Http::assertNothingSent();
    }

    private function enableTheCheck(): void
    {
        Config::set('numerosis.auth.check_compromised_passwords', true);

        HostConfig::apply();
    }

    /**
     * @return array<string, string>
     */
    private function registration(): array
    {
        return [
            'name' => 'Breached User',
            'email' => 'breached-'.uniqid().'@example.com',
            'password' => self::BREACHED_PASSWORD,
            'password_confirmation' => self::BREACHED_PASSWORD,
        ];
    }
}

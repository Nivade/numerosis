<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Nvade\Numerosis\Features\Auth\EmailVerificationFeature;
use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\Numerosis\Features\Auth\SocialLoginFeature;
use Nvade\Numerosis\Features\Billing\BillingNotificationsFeature;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Tests\TestCase;

class RegistrationWizardDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        // Every feature except RegistrationWizardFeature.
        Features::forceForTesting([
            TurnstileFeature::class,
            SocialLoginFeature::class,
            InvitationsFeature::class,
            EmailVerificationFeature::class,
            BillingNotificationsFeature::class,
            PasswordResetFeature::class,
        ]);

        parent::setUp();
    }

    public function test_it_registers_no_route_when_disabled(): void
    {
        $this->assertFalse(Route::has('tenants.create'));
    }

    public function test_the_livewire_component_cannot_be_resolved_when_disabled(): void
    {
        $this->expectExceptionMessage('Unable to find component');

        Livewire::test('tenant-registration');
    }

    /**
     * Core's own views call `route('tenants.create')`, which throws
     * RouteNotFoundException the moment the wizard stops registering it. Every
     * call site is wrapped in the same feature check, so core's remaining
     * page must still render with the wizard off.
     *
     * Rendered directly rather than through `route('home')`: the panel's own
     * unscoped route wins over the domain-scoped `home` route for a bare test
     * request, which is unrelated to this feature.
     */
    public function test_the_home_view_still_renders(): void
    {
        $this->assertStringContainsString(
            Config::string('app.name'),
            view('numerosis::home')->render(),
        );
    }
}

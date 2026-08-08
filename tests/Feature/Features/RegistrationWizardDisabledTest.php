<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Nvade\Numerosis\Features\Auth\EmailVerificationFeature;
use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\Numerosis\Features\Billing\BillingNotificationsFeature;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Features\Modules\ModuleSystemFeature;
use Nvade\Numerosis\Features\Observability\ActivityLogFeature;
use Nvade\Numerosis\Features\Social\SocialLoginFeature;
use Nvade\Numerosis\Features\Tenancy\MembershipsFeature;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Features\Ui\AccountPagesFeature;
use Nvade\Numerosis\Features\Ui\AdminPanelFeature;
use Nvade\Numerosis\Features\Ui\MarketingPagesFeature;
use Nvade\Numerosis\Features\Ui\TenantPanelFeature;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Tests\TestCase;

class RegistrationWizardDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        // Every feature except RegistrationWizardFeature — forceForTesting([])
        // disabled MarketingPagesFeature too, which is what gates the
        // 'features' route test_the_features_page_still_renders() below
        // asserts still works with the wizard off.
        Features::forceForTesting([
            TurnstileFeature::class,
            SocialLoginFeature::class,
            ModuleSystemFeature::class,
            InvitationsFeature::class,
            EmailVerificationFeature::class,
            BillingNotificationsFeature::class,
            PasswordResetFeature::class,
            ActivityLogFeature::class,
            MarketingPagesFeature::class,
            AccountPagesFeature::class,
            AdminPanelFeature::class,
            TenantPanelFeature::class,
            MembershipsFeature::class,
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
     * welcome.blade.php, features.blade.php, and the footer all call
     * route('tenants.create') unconditionally in earlier revisions — that
     * throws RouteNotFoundException the moment the route stops being
     * registered. Each call site is now wrapped in the same feature check,
     * so this page must render regardless of this toggle.
     *
     * Not testing '/' here — StaticPagesTest deliberately excludes it too;
     * the panel's own unscoped route wins over the domain-scoped `home`
     * route for a bare test request, unrelated to this feature.
     */
    public function test_the_features_page_still_renders(): void
    {
        $this->get(route('features'))->assertOk();
    }
}

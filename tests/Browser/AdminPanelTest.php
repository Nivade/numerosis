<?php

declare(strict_types=1);

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Services\Billing\Checkout\LocalCheckoutGateway;
use Nvade\Numerosis\Tests\TestCase;
use Pest\Browser\Playwright\Playwright;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The staff side of the wizard: a tenant provisioned through the browser shows
 * up in the central admin panel, seen by a different user through a real
 * request.
 *
 * `RegistrationWizardTest` ends at `Tenant::find('acme')` — an assertion
 * against the database, which cannot tell a provisioned tenant from one the
 * panel then fails to render. This picks up there.
 *
 * **Permissions are seeded rather than bypassed with `Gate::before()`, and
 * that is the point of running this in a browser at all.** Filament evaluates
 * every registered resource's `viewAny` to decide navigation visibility on
 * every page render, and spatie throws `PermissionDoesNotExist` rather than
 * returning false, so a single missing permission context is a 500 across the
 * whole panel rather than a hidden nav item — `.claude/rules/auth-guards.md`
 * records that biting a real host. A `Gate::before(fn () => true)` test, the
 * pattern the Livewire resource tests use, cannot see any of that.
 *
 * The staff user is created **first** on purpose: `CentralUserObserver::created()`
 * runs `PromoteFirstCentralUserToAdmin`, which assigns the fully-permissioned
 * `admin` role to whichever user is the only row in the central `users` table.
 * The customer who drives the wizard is created second and gets no role, so
 * the two are genuinely different principals rather than one user wearing two
 * hats.
 */
it('shows a wizard-provisioned tenant in the central admin panel', function (): void {
    (new RoleAndPermissionSeeder)->run();

    $guard = Config::string('numerosis.auth.guards.central');

    $staff = CentralUser::factory()->create();
    $customer = CentralUser::factory()->create();

    $plan = PaymentPlan::factory()->create(['available' => true, 'name' => 'Growth']);

    Playwright::setHost('central.numerosistest.test');

    // Only the payment leg is stubbed; LocalCheckoutGateway queues the same
    // ProvisionTenant the paid flow does, and the harness runs queue.default
    // as sync. See RegistrationWizardTest for the wizard interaction notes —
    // every Tab, every wait and the two Flux controls are load-bearing.
    app()->bind(CheckoutGateway::class, LocalCheckoutGateway::class);

    Auth::guard($guard)->login($customer);

    visit('/get-started')
        ->type('company_name', 'Acme Industries')
        ->keys('company_name', 'Tab')
        ->wait(1)
        ->click('Continue')
        ->wait(2)
        ->type('domain', 'acme')
        ->keys('domain', 'Tab')
        ->wait(2)
        ->click('Continue')
        ->wait(3)
        ->click($plan->name)
        ->wait(1)
        ->keys('[role="checkbox"]', 'Space')
        ->wait(2)
        ->click('Continue to Payment')
        ->wait(8);

    expect(Tenant::find('acme'))->not->toBeNull();

    // The open question this test was written to answer: whether a second
    // login, after the browser already holds a session cookie from the first,
    // is visible to the next request. Every other browser test here logs in
    // once, before its first visit().
    //
    // Asserted on something *identity*-specific, which at the time this was
    // written was the only option: `TenantResource::getModel()` returns
    // `Numerosis::model(Tenant::class)`, a host subclass that resolved **no
    // policy** (PHP attributes are not inherited), and Filament's non-strict
    // authorization allows when no policy resolves — so the page rendered for
    // a central user with no roles at all, and "the tenants page rendered"
    // asserted nothing about who was looking. That hole is fixed by
    // `NumerosisServiceProvider::registerPolicies()`, and the test below now
    // pins the fix through a real request; the identity assertion stays
    // because it is the stronger of the two.
    Auth::guard($guard)->logout();
    Auth::guard($guard)->login($staff);

    $page = visit('/admin/tenants');

    $page->assertSee('Acme Industries');

    // Asserted against content() rather than assertSee(), the same way
    // PathModeTest reads the tenant id out of the markup. The signed-in user's
    // name renders inside the panel's user-menu dropdown, which is collapsed,
    // and Filament's own JS is not built in this harness — the page reports
    // `filamentDropdown is not defined` — so it can never become *visible* and
    // assertSee() fails on a page that contains the right thing.
    $content = (string) $page->content();

    expect($content)->toContain((string) $staff->name);
    expect($content)->not->toContain((string) $customer->name);
});

/**
 * The policy fix, through a real request rather than a `Gate::getPolicyFor()`
 * assertion.
 *
 * Before `NumerosisServiceProvider::registerPolicies()`, this page rendered
 * the tenant listing to any authenticated central user, because
 * `TenantResource::getModel()` resolves to a host **subclass** and PHP
 * attributes are not inherited, so no policy resolved and Filament's
 * non-strict authorization allowed. `CentralModelPolicyResolutionTest` pins
 * the resolution itself; this pins what a browser actually gets, which is the
 * half that made the defect invisible in the first place.
 */
it('denies the tenants resource to a central user with no roles', function (): void {
    (new RoleAndPermissionSeeder)->run();

    $guard = Config::string('numerosis.auth.guards.central');

    // The first CentralUser takes the admin role via
    // PromoteFirstCentralUserToAdmin, which is what makes the second one
    // genuinely roleless.
    $staff = CentralUser::factory()->create();
    $nobody = CentralUser::factory()->create();

    $tenant = Tenant::factory()->create();

    Playwright::setHost('central.numerosistest.test');

    // Both halves in one test, against the same page, on purpose. "The
    // roleless user cannot see the tenant" is only a claim about authorization
    // if the admin can — otherwise it passes just as well when the name never
    // renders for anyone, or when the factory did not set the value being
    // searched for. Asserting the positive first makes the negative mean
    // something.
    Auth::guard($guard)->login($staff);

    expect((string) visit('/admin/tenants')->content())
        ->toContain((string) $tenant->name);

    Auth::guard($guard)->logout();
    Auth::guard($guard)->login($nobody);

    expect((string) visit('/admin/tenants')->content())
        ->not->toContain((string) $tenant->name);
});

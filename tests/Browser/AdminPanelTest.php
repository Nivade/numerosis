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
    // It has to be asserted on something *identity*-specific, and "the tenants
    // page rendered" is not. `TenantResource::getModel()` returns
    // `Numerosis::model(Tenant::class)` — a host subclass that resolves **no
    // policy**, because PHP attributes are not inherited and the subclass
    // re-declares no `#[UsePolicy]`. Filament's non-strict authorization then
    // defaults to allow, so that page renders for a central user with no roles
    // at all. Confirmed by deleting these two lines: it still passed.
    // `CentralModelPolicyResolutionTest` guards the package's own classes
    // against precisely this and cannot see one level down. The panel's user
    // menu is identity-specific; the tenant listing is not.
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

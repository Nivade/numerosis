<?php

declare(strict_types=1);

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\PendingTenantProvision;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Services\Billing\LocalCheckoutGateway;
use Nvade\Numerosis\Tests\TestCase;
use Pest\Browser\Playwright\Playwright;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Drives the registration wizard itself, rather than creating a tenant with
 * the factory and asserting on the panel it produces (what `PathModeTest`,
 * `SubdomainModeTest` and `CustomDomainModeTest` do).
 *
 * The failure this exists for is documented on
 * `Nvade\Numerosis\Livewire\Tenant\Registration::getCurrentStepState()`:
 * a `nextStep()` whose dispatched event targets a component name nothing is
 * embedded under is a **silent no-op** — no exception, no validation error,
 * the request round-trips successfully and `currentStepName` simply never
 * changes. A Livewire component test calls `nextStep()` on the wizard object
 * directly and cannot see it; only a real browser clicking a real button
 * through real Livewire JS can.
 *
 * Three interaction facts this cost time to establish, all of which make the
 * *test* hang forever rather than fail — Playwright waits for actionability
 * with no deadline here, so a wrong selector is a timeout, not a message:
 *
 * - **`type()` alone never enables a Continue button.** Both step 1 and
 *   step 2 bind `wire:model.blur.live` and render the button
 *   `:disabled="!$field"`. `type()` is a Playwright `fill()`, which fires no
 *   blur, so the value never reaches the server and the button stays
 *   disabled. Every field is followed by `keys(field, 'Tab')`.
 * - **The plan radio cannot be `check()`ed.** It is `sr-only` under a
 *   `<label>` that intercepts the pointer. Click the plan's *name* instead;
 *   the label does the rest.
 * - **`flux:checkbox` renders no `<input>` at all** — it is a `<ui-checkbox>`
 *   custom element with `role="checkbox"` and `tabindex="0"`, so `check()`
 *   matches nothing. Space on the element is what toggles it.
 */
it('advances through the wizard steps and reserves the domain', function (): void {
    PaymentPlan::factory()->create(['available' => true, 'name' => 'Growth']);

    Auth::guard(Config::string('numerosis.auth.guards.central'))
        ->login(CentralUser::factory()->create());

    Playwright::setHost('central.numerosistest.test');

    $page = visit('/get-started');

    $page->assertSee('Company Information');

    $page->type('company_name', 'Acme Industries')
        ->keys('company_name', 'Tab')
        ->wait(1)
        ->click('Continue')
        ->wait(2)
        ->assertSee('Technical Setup');

    $page->type('domain', 'acme')
        ->keys('domain', 'Tab')
        ->wait(2)
        ->click('Continue')
        ->wait(3)
        ->assertSee('Choose Your Plan');

    // TechnicalSetup::continue() reserves the domain before the plan is
    // picked, so a domain someone else claims mid-wizard is caught before the
    // user starts paying.
    $pending = PendingTenantProvision::find('acme');

    expect($pending)->not->toBeNull();
    expect($pending?->company_name)->toBe('Acme Industries');
});

/**
 * The whole path, ending in a real provisioned tenant.
 *
 * `LocalCheckoutGateway` is the Stripe stub: it is a real `CheckoutGateway`
 * this package already ships (it backs the local-only dev checkout route),
 * and it queues the same `ProvisionTenant` action the paid flow does, so
 * nothing about provisioning is faked — only the payment leg is skipped.
 * The harness runs `queue.default` as `sync`, so the tenant database is
 * really created inside the browser request.
 */
it('provisions a tenant end to end through the wizard', function (): void {
    $plan = PaymentPlan::factory()->create(['available' => true, 'name' => 'Growth']);

    Auth::guard(Config::string('numerosis.auth.guards.central'))
        ->login(CentralUser::factory()->create());

    Playwright::setHost('central.numerosistest.test');

    app()->bind(CheckoutGateway::class, LocalCheckoutGateway::class);

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

    $tenant = Tenant::find('acme');

    expect($tenant)->not->toBeNull();
    expect((string) $tenant?->name)->toBe('Acme Industries');
});

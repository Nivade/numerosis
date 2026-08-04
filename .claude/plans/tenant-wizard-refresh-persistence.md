# Plan: tenant registration wizard survives a page refresh

**Status: ✅ Executed.** Part 1 (`embedded` flag on `App\Livewire\Billing\Checkout`,
`Payment.php` stripped to a thin wrapper) and Part 2 (`#[Url]` step name,
`Registration::initialState()`) both confirmed in code.

This plan is written to be executed mechanically, step by step, in the exact
order below. Each step names the exact file, shows the exact code, and says
exactly what to run to check it. Do not skip the "Check" after each step.

## Context

Refreshing the browser anywhere in `/get-started` (the tenant registration
wizard, `App\Livewire\Tenant\Registration\Registration`) currently throws the
user back to step 1 with every field blank.

Root cause: `Spatie\LivewireWizard\Support\State` / `WizardComponent` hold
`allStepState` and `currentStepName` as plain public Livewire properties.
Livewire's snapshot round-trips them across AJAX requests within one page
load, but a hard refresh creates a brand-new component instance with nothing
to hydrate from — `mountMountsWizard()` (in
`vendor/spatie/laravel-livewire-wizard/src/Components/Concerns/MountsWizard.php`)
always falls back to `$this->stepNames()->first()` and empty step state.

The Payment step (step 4) has a second, worse version of the same problem:
the Stripe `checkoutClientSecret`/`checkoutPublishableKey` it needs to mount
a Payment Element only ever lived in that in-memory wizard state.
`App\Livewire\Tenant\Registration\Steps\Payment::mount()` already knows this
— it detects the blank secret and bounces back to the Plan step, discarding
an in-progress checkout.

This was already half-fixed once: `.claude/plans/module-marketplace.md`
("Reusable checkout" section) extracted `App\Livewire\Billing\Checkout` — a
resumable component reachable at `/checkout/{domain}`, backed by
`App\Actions\Billing\Checkout\ResumeCheckout`, which reloads the SetupIntent
from the `pending_tenant_provisions` row on every mount — specifically so it
could be **embedded into the wizard's Payment step**. That embed was never
actually wired in. `Payment.php` still has its own separate, duplicate
`subscribe()`/`confirmed()`/`settle()` implementation that does not benefit
from any of that resumability.

This plan has two parts, done in order:

- **Part 1** finishes the `Checkout` embed and deletes the duplicate logic
  from `Payment.php`. This alone fixes the Payment step.
- **Part 2** persists the earlier steps' field values (company name, domain,
  plan, billing cycle, terms accepted) and the current step name across a
  hard refresh, using two extension points the wizard package already
  provides (`initialState()` and the `showStep()` override point) plus
  Livewire's `#[Url]` attribute for the step name.

Do Part 1 first — it is self-contained and has its own tests. Part 2 depends
on nothing from Part 1 but is easier to verify once Part 1 is in place
(the Payment step's own `mount()` guard, changed in Part 1, is what Part 2's
step-4-survives-refresh test exercises).

---

## Part 1 — finish the Checkout embed, delete the duplicate

### Step 1.1 — add an `embedded` flag to `App\Livewire\Billing\Checkout`

File: `app/Livewire/Billing/Checkout.php`

Current `mount()`:

```php
    public function mount(string $domain): void
    {
        $this->pendingDomain = $domain;
        $this->checkoutPublishableKey = Config::string('cashier.key');

        $billable = GetAuthenticatedUser::run();
        $this->customerEmail = $billable instanceof CentralUser ? $billable->email : null;

        try {
            $resumed = ResumeCheckout::run($domain);
        } catch (ShowsMessageToUser $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        if ($resumed->alreadySucceeded) {
            $this->settleFromPendingSubscription();

            return;
        }

        $this->checkoutClientSecret = $resumed->clientSecret;
    }
```

Add a new public property right above `#[Locked] public string $pendingDomain;`:

```php
    public bool $embedded = false;
```

Change the `mount()` signature's first line only, everything else in the
method body stays identical:

```php
    public function mount(string $domain, bool $embedded = false): void
    {
        $this->pendingDomain = $domain;
        $this->embedded = $embedded;
        $this->checkoutPublishableKey = Config::string('cashier.key');
```

Then, inside `private function settle(Subscription $subscription): void`,
add one line right before `$this->redirectRoute('tenants.mine');`:

```php
        SettleCheckout::run($pending, $subscription, $billable->stripe_id, (string) $billable->id);

        // The registration wizard's session-persisted step state (see
        // Registration::showStep() in Part 2 of this plan) is only useful
        // while a registration is in progress. Clearing it here is a no-op
        // when Checkout was reached standalone (nothing set the key).
        session()->forget('registration.wizard_state');

        $this->redirectRoute('tenants.mine');
```

**Check:** `vendor/bin/sail artisan tinker --execute 'echo class_exists(App\Livewire\Billing\Checkout::class) ? "ok" : "fail";'`

**Cross-reference — `.claude/plans/ideal-checkout-webhook-fix.md`**: that
plan fixes a separate bug (iDEAL/Bancontact checkouts crashing on
PaymentMethod-attach) by adding logic to `CompleteRedirectCheckout`, the
redirect-return route — a different entry point from this `settle()`. It
adds the identical `session()->forget('registration.wizard_state')` call to
`CompleteRedirectCheckout`'s own successful-completion branch, because that
route can also reach "registration finished" (for a redirect-flavoured
payment method) without ever calling this `settle()` method — without the
same cleanup there, a redirect-flavoured checkout done through the embedded
wizard leaks stale wizard state into the next registration attempt. If
these two plans are executed in either order, both `session()->forget(...)`
call sites need to end up present — check `CompleteRedirectCheckout.php` has
it if that plan hasn't landed yet when this one is being done, and vice
versa. That plan also documents one residual gap this interaction doesn't
close: when completion is deferred all the way to the
`setup_intent.succeeded` webhook, that request has no browser session to
clear at all, so the stale key can survive until the next registration
attempt in that narrow case. Not fixed by either plan.

### Step 1.2 — hide the standalone heading when embedded

File: `resources/views/livewire/billing/checkout.blade.php`

Current full file:

```blade
<div class="max-w-3xl mx-auto py-12 px-4 space-y-8">
    <div class="text-center space-y-2">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Complete Your Subscription</h1>
        <p class="text-zinc-500 dark:text-zinc-400">Add a payment method to activate your workspace.</p>
    </div>

    <x-billing.payment-error :message="$paymentError" />

    @if($checkoutClientSecret && $checkoutPublishableKey)
        <div
            x-data="stripeCheckout(@js($checkoutClientSecret), @js($checkoutPublishableKey), @js(route('checkout.subscription.return')), @js(__('billing.decline_codes')), @js($customerEmail))"
            x-init="init()"
            class="space-y-8"
        >
            <x-billing.payment-element />
            <x-billing.address-element />

            <div class="flex justify-end pt-4 border-t border-gray-200 dark:border-zinc-700">
                <flux:button
                    type="button"
                    x-on:click="submit()"
                    x-bind:disabled="isSubmitting || !elementReady || !addressElementReady"
                    variant="primary"
                >
                    <span x-show="!isSubmitting">{{ __('billing.checkout.subscribe') }}</span>
                    <span x-show="isSubmitting" x-cloak>{{ __('billing.checkout.processing') }}</span>
                </flux:button>
            </div>
        </div>
    @endif
</div>
```

Replace the whole file with:

```blade
<div class="{{ $embedded ? '' : 'max-w-3xl mx-auto py-12 px-4' }} space-y-8">
    @unless($embedded)
        <div class="text-center space-y-2">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Complete Your Subscription</h1>
            <p class="text-zinc-500 dark:text-zinc-400">Add a payment method to activate your workspace.</p>
        </div>
    @endunless

    <x-billing.payment-error :message="$paymentError" />

    @if($checkoutClientSecret && $checkoutPublishableKey)
        <div
            x-data="stripeCheckout(@js($checkoutClientSecret), @js($checkoutPublishableKey), @js(route('checkout.subscription.return')), @js(__('billing.decline_codes')), @js($customerEmail))"
            x-init="init()"
            class="space-y-8"
        >
            <x-billing.payment-element />
            <x-billing.address-element />

            <div class="flex justify-end pt-4 border-t border-gray-200 dark:border-zinc-700">
                <flux:button
                    type="button"
                    x-on:click="submit()"
                    x-bind:disabled="isSubmitting || !elementReady || !addressElementReady"
                    variant="primary"
                >
                    <span x-show="!isSubmitting">{{ __('billing.checkout.subscribe') }}</span>
                    <span x-show="isSubmitting" x-cloak>{{ __('billing.checkout.processing') }}</span>
                </flux:button>
            </div>
        </div>
    @endif
</div>
```

(Only the outer `<div>`'s class and the new `@unless($embedded) ... @endunless`
block around the heading changed. Everything else is byte-for-byte the same.)

**Check:** visit `/checkout/{any-known-domain}` directly in the browser
(standalone route, `App\Livewire\Billing\Checkout` still mounted with
`embedded` defaulting to `false`) and confirm the heading still renders —
this route is unchanged by this step.

### Step 1.3 — shrink `Payment.php` to wizard chrome

File: `app/Livewire/Tenant/Registration/Steps/Payment.php`

Replace the entire file with:

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Registration\Steps;

use App\Enums\BillingCycle;
use App\Models\Central\PaymentPlan;
use Illuminate\View\View;
use Spatie\LivewireWizard\Components\StepComponent;

class Payment extends StepComponent
{
    public function mount(): void
    {
        // A refresh or a direct visit used to lose Plan's in-memory
        // properties entirely (the wizard state persists across steps, not
        // across a hard reload). Registration::initialState() (Part 2 of
        // this plan) now restores `domain` from the session, so the only
        // remaining reason to bounce back to Plan is a domain that was
        // genuinely never reserved — the embedded Checkout below resolves
        // its own Stripe state via ResumeCheckout, so no in-memory secret
        // is needed here at all.
        if (blank($this->state()->get('domain'))) {
            $this->showStep('plan');
        }
    }

    public function back(): void
    {
        $this->previousStep();
    }

    public function render(): View
    {
        $paymentPlanSlug = $this->state()->get('payment_plan');
        $billingCycle = $this->state()->get('billing_cycle');

        return view('livewire.tenant.registration.wizard.steps.payment', [
            'plan' => is_string($paymentPlanSlug) ? PaymentPlan::available()->where('slug', $paymentPlanSlug)->first() : null,
            'billingCycle' => is_string($billingCycle) ? BillingCycle::from($billingCycle) : BillingCycle::Monthly,
            'domain' => $this->state()->get('domain'),
        ]);
    }
}
```

Everything that used to live here — `ConfirmsPayments`, `subscribe()`,
`confirmed()`, `settle()`, `settleFromPendingSubscription()`,
`checkoutClientSecret`, `checkoutPublishableKey`, `vatNumber`,
`#[Locked] pendingDomain`, `isSubmitting`, and the imports for
`ResolveSetupIntent`, `CreateInlineSubscription`, `SyncBillingAddress`,
`SettleCheckout`, `CheckoutAlreadyCompleted`, `IncompletePayment`,
`GetAuthenticatedUser`, `CentralUser` — is deleted. That logic now lives only
in `App\Livewire\Billing\Checkout`, unchanged by this step.

**Check:** `vendor/bin/sail bin pint --dirty --format agent` should report no
errors on this file (unused-import warnings would mean something wasn't
fully removed).

### Step 1.4 — embed `Checkout` in the Payment step's blade

File: `resources/views/livewire/tenant/registration/wizard/steps/payment.blade.php`

Replace the entire file with:

```blade
<div class="space-y-8">
    <x-registration.header
        icon="shield-check"
        title="Complete Your Subscription"
        description="One last step — add a payment method to activate your workspace."
    />

    @if($plan && $domain)
        <div class="grid gap-6 lg:grid-cols-5 items-start">
            <x-billing.order-summary :plan="$plan" :billing-cycle="$billingCycle" :domain="$domain" class="lg:col-span-2" />

            <div class="lg:col-span-3">
                <livewire:billing.checkout :domain="$domain" :embedded="true" :key="'checkout-'.$domain" />
            </div>
        </div>

        <div class="flex justify-start pt-8 border-t border-gray-200 dark:border-zinc-700">
            <flux:button wire:click="back" variant="outline" icon="chevron-left">
                Back
            </flux:button>
        </div>
    @else
        <div class="flex justify-start pt-4 border-t border-gray-200 dark:border-zinc-700">
            <flux:button wire:click="back" variant="outline" icon="chevron-left">
                Back
            </flux:button>
        </div>
    @endif
</div>
```

What changed vs. the original: the `x-data="stripeCheckout(...)"` wrapper,
`x-billing.payment-error`, `x-billing.payment-element`,
`x-billing.address-element`, and the Subscribe button are gone from this
file — they now render inside the embedded `<livewire:billing.checkout>`
component (Step 1.2's blade already has all of them). The condition changed
from `$plan && $checkoutClientSecret && $checkoutPublishableKey` to
`$plan && $domain`, matching what `Payment::render()` now actually passes.

**Check:** `resources/js/stripe-checkout.js` needs no changes — confirm by
grepping for `paymentElement` / `addressElement` refs are still only inside
`resources/views/components/billing/payment-element.blade.php` and
`address-element.blade.php` (both untouched), which is what the Alpine
component in `stripe-checkout.js` mounts against via `$refs`.

### Step 1.5 — migrate the tests

File: `tests/Feature/Livewire/Tenant/Registration/Steps/PaymentTest.php`

The four existing tests call `Payment::subscribe()`, `Payment::confirmed()`,
and set `Payment::pendingDomain` — none of those exist on `Payment` anymore
after Step 1.3. Replace the whole file with:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Tenant\Registration\Steps;

use App\Livewire\Tenant\Registration\Registration;
use App\Livewire\Tenant\Registration\Steps\Payment;
use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Support\State\RegistrationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_bounces_back_to_plan_when_no_domain_was_reserved(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        $this->paymentStep(domain: null)
            ->assertDispatched('showStep', toStepName: 'plan');
    }

    public function test_it_renders_the_embedded_checkout_when_a_domain_is_known(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        $plan = PaymentPlan::factory()->create(['slug' => 'starter']);

        $this->paymentStep(domain: 'payment-embed-test', paymentPlan: $plan->slug)
            ->assertDontDispatched('showStep')
            ->assertSeeLivewire(\App\Livewire\Billing\Checkout::class);
    }

    /**
     * @return \Livewire\Features\SupportTesting\Testable<Payment>
     */
    private function paymentStep(?string $domain, ?string $paymentPlan = null)
    {
        $paymentAlias = app('livewire.finder')->normalizeName(Payment::class);

        return Livewire::test(Payment::class, [
            'wizardClassName' => Registration::class,
            'stateClassName' => RegistrationState::class,
            'allStepNames' => ['company-info', 'technical-setup', 'plan', $paymentAlias],
            'allStepsState' => [
                'technical-setup' => ['domain' => $domain],
                'plan' => ['payment_plan' => $paymentPlan, 'billing_cycle' => 'monthly'],
            ],
        ]);
    }
}
```

`assertSeeLivewire()` and `assertDontDispatched()` are Livewire testing
helpers — if either name doesn't match the installed Livewire version when
you run this, check
`vendor/livewire/livewire/src/Features/SupportTesting/Testable.php` for the
closest equivalent (e.g. `assertSee` on a distinctive string from
`checkout.blade.php`'s embedded output, or `assertNotDispatched`) and adjust.

The Stripe-flow behavior the old four tests covered (locked `pendingDomain`,
refusing to confirm without a pending domain, refusing to confirm an
unverifiable subscription, not settling an unrelated subscription after a
failed subscribe) now belongs to `App\Livewire\Billing\Checkout`, since that
component owns `subscribe()`/`confirmed()`/`settle()`. Port the two cases
`tests/Feature/Livewire/Billing/CheckoutTest.php` does not already cover
into that file:

Add to `tests/Feature/Livewire/Billing/CheckoutTest.php` (after the existing
`use` statements, add `use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;`):

```php
    public function test_the_pending_domain_cannot_be_set_by_the_client(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Checkout::class, ['domain' => 'locked-domain-test'])
            ->set('pendingDomain', 'someone-elses-domain');
    }

    public function test_it_refuses_to_confirm_without_a_resumable_pending_row(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        Livewire::test(Checkout::class, ['domain' => 'no-such-pending-row'])
            ->call('confirmed')
            ->assertSet('paymentError', __('billing.checkout.confirmation_failed'))
            ->assertNoRedirect();
    }
```

Note `test_it_refuses_to_confirm_without_a_resumable_pending_row` mounts
`Checkout` against a domain with no `PendingTenantProvision` at all —
`mount()` will already set `paymentError` from `ResumeCheckout`'s
`CheckoutSessionExpired` before `confirmed()` is even called, which
`confirmed()` then overwrites with the same class of refusal — assert
whichever message actually comes back once you run it
(`billing.checkout.session_expired` from mount, or
`billing.checkout.confirmation_failed` from confirmed — check
`ResumeCheckout::handle()` in `app/Actions/Billing/Checkout/ResumeCheckout.php`
against `Checkout::confirmed()` → `settleFromPendingSubscription()` to see
which one fires first for this fixture).

**Check:**
```
vendor/bin/sail artisan test --compact --filter=PaymentTest
vendor/bin/sail artisan test --compact --filter=CheckoutTest
```
Both must pass before moving to Part 2.

---

## Part 2 — persist steps 1–3 across a refresh

### Step 2.1 — rewrite `Registration.php`

File: `app/Livewire/Tenant/Registration/Registration.php`

Replace the entire file with:

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Registration;

use App\Livewire\Tenant\Registration\Steps\CompanyInfo;
use App\Livewire\Tenant\Registration\Steps\Payment;
use App\Livewire\Tenant\Registration\Steps\Plan;
use App\Livewire\Tenant\Registration\Steps\TechnicalSetup;
use App\Support\State\RegistrationState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Spatie\LivewireWizard\Components\WizardComponent;

class Registration extends WizardComponent
{
    /**
     * Redeclared here (not just inherited from WizardComponent) so #[Url]
     * can be attached to it: Livewire hydrates #[Url] properties from the
     * query string during property hydration, before mountMountsWizard()
     * (in the vendor MountsWizard trait) resolves which step to show — so a
     * hard refresh lands back on the step the URL already names, with no
     * dependency on trait-vs-class mount() call ordering.
     */
    #[Url(as: 'step', history: false)]
    public ?string $currentStepName = null;

    #[Layout('layouts.app.none')]
    public function render(): View
    {
        return view('livewire.tenant.registration.wizard.index', [
            'currentStepState' => $this->getCurrentStepState(),
            'currentStepName' => $this->currentStepName,
        ]);
    }

    public function register(): void {}

    /**
     * @return list<class-string<\Livewire\Component>>
     */
    public function steps(): array
    {
        return [
            CompanyInfo::class,
            TechnicalSetup::class,
            Plan::class,
            Payment::class,
        ];
    }

    public function stateClass(): string
    {
        return RegistrationState::class;
    }

    /**
     * Session-backed so a hard refresh restores everything filled in on
     * steps already left via showStep()/nextStep()/previousStep() — see the
     * matching write in showStep() below, which is the only place that
     * writes this key. Does not cover the step currently open and not yet
     * submitted — a refresh before clicking Continue on the open step still
     * loses that step's edits, same as before this change.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function initialState(): ?array
    {
        /** @var array<string, array<string, mixed>>|null $state */
        $state = session('registration.wizard_state');

        return $state;
    }

    /**
     * The single choke point every step transition passes through
     * (nextStep(), previousStep(), and the 'showStep' Livewire event Plan
     * uses for its validation redirects) — so persisting here, once, covers
     * all of them with no change to any individual step component.
     *
     * #[On('showStep')] has to be repeated on this override: PHP attributes
     * on a method are not inherited when a child class overrides that
     * method, and StepComponent::showStep() (called from Plan/Payment)
     * reaches this via a dispatched 'showStep' Livewire event, not a direct
     * method call — see vendor/spatie/laravel-livewire-wizard/src/Components/StepComponent.php.
     * Without the attribute here, that event stops being handled and
     * Plan's showStep('company-info') / showStep('technical-setup')
     * validation redirects silently break.
     */
    #[On('showStep')]
    public function showStep($toStepName, array $currentStepState = [])
    {
        parent::showStep($toStepName, $currentStepState);

        session()->put('registration.wizard_state', $this->stateToPersist());
    }

    /**
     * Everything in allStepState except Plan's Stripe secrets and
     * request-local UI flags. Those never belong in the session: after
     * Part 1 of this plan, the embedded Checkout component re-derives them
     * from the pending_tenant_provisions row via ResumeCheckout, so a
     * session-stored client secret would only ever be stale. Matches the
     * "no session carrier means no tamper surface" principle in
     * .claude/plans/custom-checkout.md.
     *
     * @return array<string, array<string, mixed>>
     */
    private function stateToPersist(): array
    {
        $state = $this->allStepState;

        $planAlias = $this->componentName(Plan::class);

        if ($planAlias !== null && isset($state[$planAlias]) && is_array($state[$planAlias])) {
            unset(
                $state[$planAlias]['checkoutClientSecret'],
                $state[$planAlias]['checkoutPublishableKey'],
                $state[$planAlias]['isSubmitting'],
                $state[$planAlias]['checkoutError'],
                $state[$planAlias]['wizardCompleted'],
            );
        }

        return $state;
    }

    public function getFormalCurrentStepName(): string
    {
        // Try to resolve the current step's 1-based index from the wizard's step names
        $index = $this->stepNames()->search(function (string $step) {
            return $step === $this->currentStepName;
        });

        if ($index !== false) {
            // Convert zero-based index to 1-based step number and delegate
            return $this->getFormalStepNameFor(((int) $index) + 1);
        }

        // Fallback: format the current step name directly if not found in the steps list
        $name = str_replace('-', ' ', (string) $this->currentStepName);

        return ucwords($name);
    }

    public function getFormalStepNameFor(int $stepNumber): string
    {
        $steps = $this->steps();

        // Treat a given step number as 1-based for usability
        $index = $stepNumber - 1;

        if ($index < 0 || $index >= count($steps)) {
            return '';
        }

        $stepClass = $steps[$index];

        // Derive the step slug from the class name, mirroring Livewire Wizard's naming
        $slug = Str::kebab(class_basename($stepClass));

        $name = str_replace('-', ' ', $slug);

        return ucwords($name);
    }

    public function getCurrentStepNumber(): int
    {
        $index = $this->stepNames()->search(function (string $step) {
            return $step === $this->currentStepName;
        });

        return $index !== false ? ((int) $index) + 1 : 1;
    }
}
```

Only three things changed from the original file: the new `#[Url]`-decorated
`$currentStepName` property declaration, the new `initialState()` override,
and the new `showStep()` override plus its private `stateToPersist()`
helper. `getFormalCurrentStepName()`, `getFormalStepNameFor()`,
`getCurrentStepNumber()`, `render()`, `register()`, `steps()`, and
`stateClass()` are unchanged — copied verbatim so nothing else regresses.

**Check:** `vendor/bin/sail bin pint --dirty --format agent`, then
`vendor/bin/sail exec -T laravel.test bash -lc "vendor/bin/phpstan analyse --memory-limit=1G app/Livewire/Tenant/Registration/Registration.php"`
(phpstan level 9 applies here per `.claude/rules/static-analysis.md`; the
`componentName()` call returns `?string` from the vendor trait, hence the
null check before indexing `$state[$planAlias]`).

### Step 2.2 — write a refresh-survival test

New file: `tests/Feature/Livewire/Tenant/Registration/RegistrationRefreshTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Tenant\Registration;

use App\Livewire\Tenant\Registration\Registration;
use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_values_and_current_step_survive_a_fresh_component_instance(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        $wizard = Livewire::test(Registration::class)
            ->set('company_name', 'Acme Corp')
            ->call('continue')
            ->set('domain', 'acme-refresh-test')
            ->call('continue');

        $wizard->assertSet('currentStepName', 'plan');

        // A hard refresh is a brand-new component instance with nothing
        // carried over except the session and the query string — construct
        // one exactly that way instead of reusing $wizard.
        $resumed = Livewire::test(Registration::class);

        $resumed->assertSet('currentStepName', 'plan');
        $this->assertSame('Acme Corp', $resumed->instance()->getCurrentStepState('company-info')['company_name'] ?? null);
        $this->assertSame('acme-refresh-test', $resumed->instance()->getCurrentStepState('technical-setup')['domain'] ?? null);
    }

    public function test_plan_step_stripe_fields_are_never_written_to_the_session(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        Livewire::test(Registration::class)
            ->set('company_name', 'Acme Corp')
            ->call('continue')
            ->set('domain', 'acme-session-test')
            ->call('continue');

        $planState = session('registration.wizard_state')['plan'] ?? [];

        $this->assertArrayNotHasKey('checkoutClientSecret', $planState);
        $this->assertArrayNotHasKey('checkoutPublishableKey', $planState);
    }
}
```

`->set('company_name', ...)` and `->call('continue')` on a `Registration`
instance directly (rather than on the nested `CompanyInfo` step component)
work because Livewire Wizard steps render as part of the same request cycle
— if this doesn't work as written (the wizard may require testing through
the actual step component's own Livewire name, e.g.
`Livewire::test(Registration::class)->call('$refresh')` doesn't reach into a
nested step's public properties the same way a top-level component's do),
fall back to the pattern `PaymentTest.php` already uses: construct the
`CompanyInfo`/`TechnicalSetup` step components directly via
`Livewire::test(CompanyInfo::class, ['wizardClassName' => Registration::class, ...])`
the same way, driving `continue()` on each, then assert against
`session('registration.wizard_state')` directly rather than through a second
`Registration` instance's `getCurrentStepState()`. Whichever form compiles
and passes, keep the assertions: (a) field values survive a fresh instance,
(b) Stripe secrets never appear in the session.

**Check:**
```
vendor/bin/sail artisan test --compact --filter=RegistrationRefreshTest
```

### Step 2.3 — full check

```
vendor/bin/sail artisan test --compact --filter=Registration
vendor/bin/sail artisan test --compact --filter=Payment
vendor/bin/sail artisan test --compact --filter=Checkout
vendor/bin/sail bin pint --dirty --format agent
```

All must pass/report clean before considering this done.

## Manual verification (do this last, after all automated checks pass)

1. `vendor/bin/sail npm run build` if any blade/JS changed and the dev
   server isn't already watching (`vendor/bin/sail npm run dev` /
   `vendor/bin/sail composer run dev`) — see `CLAUDE.md`, "Frontend
   Bundling".
2. Visit `/get-started`, fill in company name, continue; fill in a domain,
   continue; pick a plan, accept terms, continue to Payment.
3. Refresh the browser on the Payment step. Confirm: still on Payment (URL
   shows `?step=payment` or similar), order summary still shows the right
   plan, the Payment Element re-mounts with a working card field (no console
   errors — check via `mcp__laravel-boost__browser-logs`), and clicking Back
   lands on Plan with the plan/terms still selected.
4. Go back further to Technical Setup, refresh, confirm the domain field is
   still filled in.
5. Complete a full test-mode checkout end to end and confirm
   `session('registration.wizard_state')` is gone afterward (e.g. via
   `vendor/bin/sail artisan tinker --execute 'dd(session()->all());'` is not
   usable across requests — instead confirm indirectly: start a second,
   unrelated registration afterward and check company name/domain fields are
   blank again, not prefilled from the finished one).

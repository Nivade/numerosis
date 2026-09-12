---
paths:
  - 'src/Livewire/Tenant/Registration.php'
  - 'src/Livewire/Tenant/Registration/**'
---
# Tenant Registration Wizard

> **Header note, 2026-09-04. Read this before anything below.** This file was
> written twice against layouts that no longer exist, and the second header
> said the opposite of what is true today. Current facts:
>
> - The wizard is **in core**: `Nvade\Numerosis\Livewire\Tenant\Registration`
>   (`src/Livewire/Tenant/Registration.php`), steps under
>   `src/Livewire/Tenant/Registration/Steps/*`. It moved out to
>   `packages/onboarding` on 2026-08-31 and folded straight back in Phase 3
>   (2026-09-03) — there is no `NumerosisOnboarding` namespace.
> - There is no `Support\Tenancy\SelfServeRegistration`. Its two constants live
>   on `Features\Tenancy\RegistrationWizardFeature` now
>   (`::FEATURE`, `::SESSION_KEY = 'registration.wizard_state'`), read by
>   `Livewire\Billing\Checkout` and `Actions\Billing\Checkout\CompleteRedirectCheckout`.
> - `RegistrationState` is
>   `Livewire\Tenant\Registration\RegistrationState`, moved there 2026-09-11
>   from `Support\Tenancy\`.
> - `/get-started` is a plain core route —
>   `Route::livewire('/get-started', Registration::class)->name('tenants.create')`
>   in `routes/web.php:49` — not a host contribution. The
>   component alias `tenant-registration` is registered by
>   `RegistrationWizardFeature` (`src/Features/Tenancy/RegistrationWizardFeature.php:90`),
>   because Livewire cannot discover a package's classes.
> - **Every mention of Filament below is history.** `packages/filament`, both
>   panels, the `RegisterTenant` page and `numerosis.panels.*` were deleted in
>   Phase 1 (2026-09-03). `Contracts\Tenancy\ProvidesTenantIdentity` survives.
>
> The Livewire mechanics below are still correct and still bite — only the
> hosting surface changed.

- **A page whose entire view is a single `@livewire()` directive can lose that
  child's own component boundary — Livewire flattens the two together, and the
  child silently stops being independently addressable.** Found on the
  since-deleted Filament `RegisterTenant` page, whose view
  (`resources/views/filament/admin/pages/register-tenant.blade.php`) was
  exactly one line, `@livewire('tenant-registration')`. Confirmed via the
  rendered page's raw `wire:id`/`wire:snapshot` attributes and the actual
  network payload sent on interaction: only **two** Livewire components ever
  existed in the DOM — the host page itself, and whichever wizard step was
  current — never a third for the wizard component in between. Fixed by
  wrapping the directive in a `<div>`:

  ```blade
  <div>
      @livewire('tenant-registration')
  </div>
  ```

  That alone was enough to stop the flattening. Core no longer embeds the
  wizard this way — `/get-started` routes at it directly — but **any view that
  is nothing but one `@livewire()` call needs the wrapper**, and a host
  embedding the wizard in its own page is exactly the case that reintroduces
  this. Don't assume a bare single-directive view is safe because it renders;
  a 200 proves nothing about whether the embedded component kept its identity.

- **The shipped step aliases are written out by hand, in
  `Enums\Tenancy\WizardStep::alias()`, and must stay that way** (moved off
  `RegistrationWizardFeature::SHIPPED_STEP_ALIASES` in the
  enum-vocabulary-sweep). Deriving each alias as
  `Str::kebab(class_basename($step))` in a loop reintroduces the collision the
  method exists to avoid: `WizardStep::Payment->alias()` returns `null`
  because its natural alias (`payment`) collides with Cashier's published
  `resources/views/vendor/cashier/payment.blade.php`, so that step is
  deliberately skipped and keeps resolving by its full FQCN, which Livewire
  supports with no `addComponent()` call at all. A host-supplied step — one
  added to `numerosis.tenancy.registration.steps` with no matching
  `WizardStep` case — is not auto-registered either, for the same reason:
  `WizardStep::fromComponentClass()` returns `null` for it, and the package
  only knows the view path for steps it ships. Register your own component
  for it before adding it to that config key.

- **`spatie/laravel-livewire-wizard`'s `StepComponent::nextStep()`/
  `previousStep()`/`showStep()` target their transition event
  `->to($this->wizardClassName)`, and `WizardComponent::getCurrentStepState()`
  (vendor) sets that value to `static::class` — the wizard's raw FQCN, not
  however it's actually registered with Livewire.** That's silently wrong
  the moment a consumer registers the wizard under a custom alias instead of
  relying on Livewire's auto-derived class-name convention.
  `RegistrationWizardFeature` registers `Registration` as `tenant-registration`
  (`Livewire::addComponent`) — the same short-alias pattern every sibling
  step already uses (`company-info`, `technical-setup`, `plan`) — so the
  vendor default mistargeted every transition. Fixed with an override:

  ```php
  #[Override]
  public function getCurrentStepState(?string $step = null): array
  {
      return [
          ...parent::getCurrentStepState($step),
          'wizardClassName' => resolve('livewire.finder')->normalizeName(static::class),
      ];
  }
  ```

  Same resolution pattern `Registration::stateToPersist()` already used for
  `Plan`'s own alias — see `.ai/rules/billing-checkout.md`'s note on
  `Payment`'s alias collision with Cashier's published view name for the
  precedent this should have generalized from sooner.

- **Both bugs above were required together — fixing either alone still left
  the wizard stuck.** Verified in isolation before combining: the
  `wizardClassName` fix alone still had no live `tenant-registration`
  component to receive the (now correctly-named) event; the `<div>` wrapper
  alone gave the wizard a real component boundary, but every dispatched
  event still targeted the wrong (raw-FQCN) name. Don't stop investigating
  a "stuck wizard" symptom after finding one plausible cause — check that
  the *other* half of the round trip (a receiver actually exists **and** is
  addressed by the right name) is fixed too.

- **The failure mode this produces is maximally silent, and that's the real
  danger, not the mechanism.** No console error, no server-side exception,
  no validation error, the Livewire AJAX request round-trips with a 200 and
  the submitted field genuinely persists (`company_name` was really saved)
  — the wizard just never advances. A user sees a "Continue" button that
  appears to do nothing. Nothing in Laravel's log, nothing in the browser
  console, points at the cause; the only tell is inspecting the actual
  network request payload's `components` array and confirming the wizard
  component is (or isn't) one of them, or checking `document.querySelectorAll('[wire\\:id]')`
  against how many components you expect on the page.

- **Every isolated `Livewire::test(StepClass::class, [...])` test for this
  wizard hand-built its own mount params and hardcoded
  `'wizardClassName' => Registration::class` — reproducing the exact bug as
  if it were correct input, one line below where the same file correctly
  resolved every *step's* own alias through `livewire.finder`.**
  `RegistrationRefreshTest`, `PaymentTest`, `TechnicalSetupTest`, and
  `RegistrationCheckoutHandoffTest` all had this. Since each test only
  asserted on session state or validation errors — never on whether the
  dispatched transition event actually reached anything — they passed
  whether or not the real targeting worked, a vacuous-pass in the same
  family `.ai/rules/testing.md` already documents for `assertDontSee`
  on an unregistered Blade component. All four now resolve the alias the
  same way their sibling steps do. **A hand-assembled Livewire component
  mount in a test is exactly the kind of place a hardcoded value silently
  drifts from the real registration — prefer resolving through
  `livewire.finder` even in test setup, not just production code.**
  Regression coverage for the real bug (not just the corrected test input)
  lives in `RegisterTenantTest::test_the_wizard_gets_its_own_component_boundary_separate_from_the_page`
  (asserts the rendered page's `wire:id` count directly, since the DOM
  boundary is what actually broke and no component-level unit test can see
  it) and `RegistrationRefreshTest::test_it_dispatches_step_transitions_to_the_wizards_registered_alias`
  (`assertDispatchedTo()` against the resolved alias).

## Suggested better approach

Both root causes are the same shape: **a value with two potential sources —
"how a component is actually registered" vs. "its class name" — used the
wrong one, silently.** `WizardComponent`/`StepComponent` are vendor code and
not ours to patch, but the pattern is worth generalizing defensively: any
future package or internal API that takes a Livewire "component name" as a
string should resolve it through `livewire.finder`'s `normalizeName()`
rather than accept `SomeClass::class` at the call site, the same way this
codebase already does for every step alias. If a second multi-step wizard
is ever added, wire it through the same `getCurrentStepState()` override
pattern from the start rather than rediscovering this.

## Three inheritance traps in `Registration`, all silent

- **`#[On('showStep')]` has to be repeated on the override.** PHP attributes on
  a method are not inherited when a child overrides it, and
  `StepComponent::showStep()` reaches the wizard through a dispatched
  `showStep` Livewire event rather than a direct call. Drop the attribute from
  the override and `Plan`'s `showStep('company-info')` /
  `showStep('technical-setup')` validation redirects stop being handled, with
  no error. That override is also the single choke point every transition
  passes through (`nextStep()`, `previousStep()`, and the event), which is why
  wizard state is persisted there once rather than per step component.

- **`$currentStepName` is redeclared on the class so `#[Url]` can attach to
  it.** Livewire hydrates `#[Url]` properties before `mountMountsWizard()` (in
  the vendor `MountsWizard` trait) resolves which step to show, so a hard
  refresh lands on the step the query string names without depending on
  trait-versus-class `mount()` ordering. Inheriting the property from
  `WizardComponent` gives it no attribute and loses that.

- **`initialState()` restores steps already left, never the open one.** It
  reads `registration.wizard_state`, written only by the `showStep` override
  above, so a refresh before the open step is submitted still loses that step's
  edits.

# Tenant Registration Wizard

- **A Filament page whose entire view is a single `@livewire()` directive
  can lose that child's own component boundary — Livewire flattens the two
  together, and the child silently stops being independently addressable.**
  `resources/views/filament/admin/pages/register-tenant.blade.php` used to
  be exactly one line, `@livewire('tenant-registration')`. Confirmed via
  the rendered page's raw `wire:id`/`wire:snapshot` attributes and the
  actual network payload sent on interaction: only **two** Livewire
  components ever existed in the DOM — the Filament page itself, and
  whichever wizard step was current — never a third for the wizard
  component (`Nvade\Numerosis\Livewire\Tenant\Registration\Registration`,
  registered under the alias `tenant-registration`) in between. Fixed by
  wrapping the directive in a `<div>`:

  ```blade
  <div>
      @livewire('tenant-registration')
  </div>
  ```

  That alone was enough to stop the flattening. **Any future Filament page
  whose view is nothing but one `@livewire()` call needs the same wrapper**
  — don't assume a bare single-directive view is safe just because it
  renders successfully; a 200 response proves nothing about whether the
  embedded component kept its own identity.

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
  `Plan`'s own alias — see `.claude/rules/billing-checkout.md`'s note on
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
  family `.claude/rules/testing.md` already documents for `assertDontSee`
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

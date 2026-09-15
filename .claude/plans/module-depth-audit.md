# Module depth audit — four builds and three docs fixes

**Status: not executed.** Written 2026-09-15. Findings come from a
`codebase-design` audit of three areas: `src/Contracts/**` + `src/Services/**`,
the host bootstrap seam, and `src/Actions/**` call sites. Every decision below
was put to the repo owner and answered; nothing here is an open question.

Seven items, ordered. **One commit per item, stop after each for review.**
Items 1–4 are code, items 5–7 are docs. Run `composer lint` once before every
commit (Pint alone never runs Rector) and keep unrelated Rector rewrites out of
the diff.

## Why these seven and not others

The audit measured *depth* — how much behaviour sits behind how much interface
— not line counts. Three findings drove everything:

1. `src/Contracts/` holds two populations that look like one. 16 contracts pair
   with a `Services/` implementation and are swappable through
   `numerosis.{billing,tenancy}.implementations`. The other 19 are marker or
   value interfaces with nothing to mirror (`Subscribable`, `Plan`,
   `ProvisioningStep` and its three refinements, `ProvisionContribution`,
   the four model contracts, `Feature`/`NamedFeature`, and so on). The
   35-vs-18 file ratio reads as over-abstraction only because
   `architecture-conventions.md`'s flat-mirror rule sounds like it covers all
   35. Item 5 fixes that.
2. A swap seam with no adapter crossing it is not a seam. Two of the 16 had
   none — not a second core implementation, not a host, not a test double.
   Items 1 and 2.
3. `Numerosis`'s real interface is much larger than its 25 method signatures,
   because four of them run before `RegisterFacades` and `LoadConfiguration`.
   Items 3 and 6.

---

## Item 1 — Fold `MoneyFormatter` into `BillingService`

**Finding.** One method, a 21-line implementation that is
`Cashier::formatAmount()` plus a config default, exactly one caller
(`BillingService`'s constructor), and zero test references. Callers already
reach it through `BillingService::formatAmount()` / `Billing::formatAmount()`,
so the contract is a seam sitting behind another seam. Delete it and complexity
reappears in one constructor.

**Do:**

- Move `CashierMoneyFormatter::format()`'s body into
  `BillingService::formatAmount()`. It needs `Cashier::formatAmount()` with
  `options: ['min_fraction_digits' => 2]` and the same
  `$currency ?? Config::string('cashier.currency', 'usd')` default that
  `BillingService::currency()` already reads — use `$this->currency()` for that
  half rather than repeating the config read.
- Drop the `MoneyFormatter $money` constructor parameter.
- Delete `src/Contracts/Billing/MoneyFormatter.php` and
  `src/Services/Billing/CashierMoneyFormatter.php`.
- Delete the `MoneyFormatter::class => CashierMoneyFormatter::class` row from
  `config/numerosis.php` (`numerosis.billing.implementations`, around line 320)
  and its two `use` statements at the top of that file.

**Leave alone.** `BillingService` is the named exception in
`ArchTest`'s "everything in Services implements one of our contracts" test —
that list does not change. The three Blade call sites
(`resources/views/components/billing/order-summary.blade.php:14,17`,
`resources/views/livewire/tenant/registration/wizard/steps/plan.blade.php:24`)
go through the `Billing` facade and need no edit.

**Test.** `vendor/bin/pest --filter=Billing`. Add a case asserting
`Billing::formatAmount(1000)` returns the cashier-formatted string and that
`formatAmount(1000, 'eur')` honours the argument — there is no coverage of this
path today, which is part of why the seam went unexercised.

**Also.** `docs/extending.md`'s swap table lists
`numerosis.billing.implementations` generically rather than per-contract, so
check whether `MoneyFormatter` is named anywhere in `docs/` before committing:
`grep -rn MoneyFormatter docs/ README.md`.

## Item 2 — Give `TenantDatabaseManager` the fake it was built for

**Finding.** An 18-line wrapper over stancl's own `databaseExists()`, one
caller (`Actions/Tenancy/CreateTenantDatabase`), and the only test file naming
it is `ArchTest`. The stated payoff of the seam is being able to test
`CreateTenantDatabase` without a real MySQL database, and nothing collects it.
Decision was to make the seam real rather than inline it.

**Do:**

- Add a fake adapter under `tests/Support/` (sibling to `PatientHostStep.php`,
  which is the existing precedent for a test-only adapter standing in for a
  host). It implements `Contracts\Tenancy\TenantDatabaseManager` and returns a
  value the test sets.
- Add a test for `CreateTenantDatabase` that binds the fake and asserts both
  branches of the action: database already exists, so stancl's `CreateDatabase`
  job never runs; database absent, so it does. The early-return branch is the
  one that matters — its comment says it exists so a half-built database stays
  recoverable, and nothing proves it.
- Asserting the second branch without MySQL means asserting the job was
  dispatched or invoked rather than that a schema appeared. Check how the other
  provisioning-step tests handle this before inventing an approach —
  `tests/Feature/Jobs/RunProvisioningStepTest.php` is the closest neighbour.

**Note for whoever runs this.** The suite's MySQL lives in a docker container;
`docker start numerosis-mysql-1` first or everything fails on connection
refused. The point of this item is that the *new* test should not need it.

## Item 3 — `HostConfig::set()` segment assertion

**Highest-value item of the seven.** The diagnosis is already written up in
`.ai/rules/package-host-bootstrap.md` under the `HostConfig::apply()`
register-vs-booting bullet, including a correction recording that an earlier
proposed fix (read-modify-write of the parent array) does not work and why.
**Read that bullet before starting.** What follows implements what it
concludes, and nothing more.

**Finding.** Writing a key three or more segments deep into a namespace another
package's `mergeConfigFrom()` still needs to populate silently truncates that
namespace: `Arr::set()` replaces a missing intermediate segment wholesale, and
the later `mergeConfigFrom()`'s one-level-deep `array_merge()` then keeps the
truncated value over its own complete default. It surfaced once, months later,
on one route. Nine of the twenty `self::set()` calls in `HostConfig` qualify.

**Do:**

- In `src/Boot/HostConfig.php:62`, before `Config::set()`, walk the key's
  intermediate segments (everything but the last). If any is missing, or is
  present but not an array, throw naming the full key and the offending
  segment.
- Scope the guard to keys outside `numerosis.*`. This package's own
  `mergeConfigFrom` populates that namespace before anything in `HostConfig`
  runs, so those keys can legitimately be written into a fresh tree.
- Throw, do not warn — the decision was explicit. A warning reintroduces the
  quiet-failure mode the guard exists to kill.
- Use a `DomainException` subclass under `src/Exceptions/` if one fits the
  existing split; read `.ai/rules/exception-handling.md` first for the
  `DomainException` / `ShowsMessageToUser` distinction. This is a developer
  error at boot, never user-facing.

**Expected outcome:** on a correctly-ordered boot, all nine pass and nothing
changes. The test suite going green is the signal, not an absence of effect.

**Test.** Drive `HostConfig::apply()` from a blank slate the way
`HostConfigDoctorCoverageTest` already does, and add a case that unsets a
vendor parent key (`tenancy.database`, say) and asserts the throw names it.
`vendor/bin/pest --filter=HostConfig`.

**Watch for:** `HostConfigTest::rebootPackage()` is deliberately not shared
with `NumerosisServiceProviderDefaultsTest` — the register-vs-booting split is
why. Do not merge them while in here.

## Item 4 — Checkout cluster

**Largest item, and it touches a live payment path.** `src/Livewire/Billing/Checkout.php`
is 408 lines with 13 action invocations, the highest count in the repo, and it
bypasses the `Billing` facade that exists to hide exactly this.

**Finding, part one — `mount()`.** Six actions in a fixed order, five of them
consuming the previous one's output: `ResolveCheckoutRegion` →
`GetAuthenticatedUser` → `FetchStripeCustomer` → `FetchSavedBillingDetails` →
`FetchReusablePaymentMethods` → `ResumeCheckout`. The component has to know the
order, which results are nullable, and which throw.

**Finding, part two — the settle paths.** Three public methods (`subscribe`,
`subscribeWithSavedPaymentMethod`, `confirmed`) each re-derive a different
subset of `AssertPendingReservationIsFresh`, `AssertReservationIsOwned`,
`ResolveSetupIntent`, `ResolveSavedPaymentMethod`, `SyncBillingAddress`,
`CreateInlineSubscription`, `SettleCheckout`. A branch that forgets an assert
fails open, silently.

**Do:**

- Add `Actions/Billing/Checkout/LoadCheckoutContext` with
  `handle(string $domain, Request $request): CheckoutContext`, absorbing all six
  `mount()` calls. `CheckoutContext` is a `spatie/laravel-data` object under
  `src/Data/Billing/Checkout/` — that directory already exists and is where the
  2026-09-11 move put the checkout value objects.
- Push the assert pair into the settle path so all three payment branches end
  at `SettleCheckout::run(...)` and none of them chooses its own asserts.
- `Checkout.php` should shrink substantially. It is not a goal in itself; the
  goal is that the component learns one call and one type instead of an
  ordering.

**Read first:** `.ai/rules/billing-checkout.md`. It names four live traps in
this exact area, including `subscribable_id` being the owner's primary key
rather than `global_id`, and the wizard bypassing `StartCheckoutRequest`. At
least one of them constrains what `CheckoutContext` can carry.

**Do not** fold `Http/Controllers/Billing/WebhookController.php` into this. It
has 9 invocations but each handler is one webhook event with 1–3 actions and no
shared order; flat is correct there. Wrapping it would be layering, not
deepening. This was decided, not overlooked.

**Test.** The existing checkout tests must pass unchanged — that is the main
safety net, so run them before touching anything and note which are green.
`vendor/bin/pest tests/Feature/Actions/Billing/Checkout` plus whatever covers
the Livewire component. Add a test that each of the three settle paths runs the
asserts, since that is the failure this item prevents.

## Item 5 — Name the two contract populations

One paragraph in `.ai/rules/architecture-conventions.md`, in the first bullet
(the flat-mirror rule). State that the rule governs the ~16 contracts that pair
with a `Services/` implementation and are swappable through
`numerosis.{billing,tenancy}.implementations`, and that the rest of
`src/Contracts/` is marker and value interfaces — `ProvisioningStep` and its
three refinements, `ProvisionContribution`/`PersistsToProvisionColumns`, the
four model contracts, `Subscribable`, `Plan`, `Feature`/`NamedFeature` — which
have no implementation to mirror and are not evidence of over-abstraction.

Keep it short and keep the file's existing voice ("describes the house pattern,
not a recommendation"). Recount the two populations against the tree rather
than trusting the numbers in this plan — items 1 and 2 change one of them.

## Item 6 — Group `Numerosis`'s boot-phase methods

`configure()`, `routes()`, `middleware()` and `exceptions()` carry a different
contract from the other 21 statics on `src/Numerosis.php`: they run while
`ApplicationBuilder` is being built, before `RegisterFacades` and before
`LoadConfiguration` finishes. Consequences, all already recorded in
`.ai/rules/package-host-bootstrap.md`: they must never be called through the
`Facades\Numerosis` facade; anything they touch that reads `Config` needs a
facade-root guard; and calling order relative to the host's own
`bootstrap/app.php` matters. Today they sit alphabetically adjacent to
`model()` and `assetTags()` and read as ordinary calls.

Separate them visually in the class, and in `docs/extending.md`'s seam table.
This is presentation only — **no signature changes, no new class, no moves.**
Respect the repo's comment budget: `.ai/rules/general.md` caps docblock prose
at 5 lines and `//` comments at 3, with no exemption for public seams, and
forbids citing `.ai/rules` or `docs/` from source.

## Item 7 — Record the audit as a rule

Use the `codebase-learnings` skill (which calls `record-rule`). Two facts worth
inheriting:

- `src/Contracts/` holds two populations — swap seams and markers — and only
  the first mirrors `src/Services/`. (Same fact as item 5; item 5 puts it where
  someone editing those folders will hit it, this puts it where someone
  auditing the architecture will.)
- A swap seam with no adapter crossing it is not a seam. The test is whether
  anything — a second core implementation, a documented host override, or a
  test double — actually crosses. Being listed in
  `numerosis.{billing,tenancy}.implementations` is not sufficient on its own;
  `MoneyFormatter` and `TenantDatabaseManager` were both listed and neither was
  crossed.

**`record-rule` drops every note from `.ai/rules/index.md` on each call.**
Restore the file from git afterwards and diff it; `tests/Feature/RulesIndexTest.php`
fails if you forget. Check whether `.ai/rules/` picked up unrelated changes
while this plan sat — parallel sessions write that directory, and the right
response is to merge, never to revert.

---

## Closing out

Move this file to `.claude/plans/archive/` and update the `## Live` table in
`.claude/plans/README.md` in the same pass that finishes the last item —
the README's live list is hand-copied and goes stale otherwise.

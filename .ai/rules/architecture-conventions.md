---
paths:
  - 'src/Contracts/**'
  - 'src/Services/**'
  - 'src/Actions/**'
  - 'src/Data/**'
---
# Architecture Conventions

Recorded by `/infer-conventions`. Describes the house pattern, not a
recommendation — match it, don't improve on it.

- **Data-access and cross-cutting capabilities go behind a `Contracts`
  interface, bound to a `Services` implementation in
  `NumerosisServiceProvider::register()`.** Both live under the same
  `<Domain>/` subfolder, and **the two folders mirror each other flatly** —
  `src/Contracts/Billing/*` and `src/Services/Billing/*` are both flat, so a
  contract and its implementation are findable from each other. Do not
  reintroduce a sub-grouping on one side only; `Services/Billing/` carried
  `Checkout/`, `Plans/`, `Subscriptions/` *and* `Resolvers/` until
  2026-09-11, i.e. two grouping axes at once, and only two of `Resolvers/`'s
  five were resolvers. Interfaces are named by capability (`Resolves*`,
  `Creates*`, `Notifies*`, `*Repository`, `*Gateway`, `*Policy`); concrete
  classes are named by mechanism (`Eloquent*`, `Config*`, `Local*`,
  `Stancl*`, `*Directly`). Consumers type-hint the interface and resolve
  through the container — never reference the concrete class directly.

  **This governs one of the two populations in `src/Contracts/`, not all of
  it** (recounted 2026-09-15: 35 files). Twelve are swap seams with a
  `Services/` implementation — the eight billing ones, `TenantDomainPolicy`,
  `TenantDatabaseManager`, `NotifiesTenantOwner`, `ProvidesExceptionContext` —
  and those are what the mirror rule is about. Four more are swappable through
  `numerosis.{billing,tenancy}.implementations` but implemented by an action
  (`ProvisionsTenant`, and the three Fortify-facing `Auth` ones), so they have
  nothing in `Services/` to mirror by design. The remaining nineteen are marker
  and value interfaces a host or a model implements — `ProvisioningStep` and its
  three refinements, `ProvisionContribution`/`PersistsToProvisionColumns`/
  `ContributesProvisionData`, the model contracts, `Subscribable`, `Plan`,
  `BillableUser`, `Feature`/`NamedFeature`, the `Has*` traits' interfaces. A
  contract-to-implementation ratio counted across all 35 reads as
  over-abstraction and is measuring the wrong thing.

- **`Services/` is only for that.** The rule above used to read as an
  absolute while holding for 12 of 24 files. Four value objects and
  `Responsable` wrappers lived under `Services/Billing/Checkout/` and moved
  to `Data/Billing/Checkout/` and `Http/Responses/Billing/` on 2026-09-11.
  One resident implements nothing and is deliberate: `BillingService` (the
  `Billing` facade's root). `TaxIdType` moved to `Enums\Billing\TaxIdType`
  2026-09-12 (enum-vocabulary-sweep phase 6) — it was a lookup table over a
  closed set of Stripe tax id types, which is exactly what an enum is for.
  `tests/Feature/ArchTest.php` carries the named-exception list — `BillingService`
  plus `PreservingPathTenantResolver` and the three tenancy bootstrappers,
  which are stancl adapters rather than contract-shaped classes — and fails on
  anything not on it, so adding one is an edit to that list, not a drive-by.
  It also asserts the layout around `Services/`: `Boot/` reads no live tenancy
  state and holds only `final` classes, `Routing/` holds only route classes,
  and `Services/` and `Contracts/` stay one domain folder deep. Classes that validate
  or normalize the *host application* are not services either — they live in
  `src/Boot/`.

- **Business logic goes in `AsAction` classes under `src/Actions/**`,
  invoked via `handle()`.** No `__invoke()`/`execute()` alternates. This
  matches `lorisleiva/laravel-actions`'s own default, so it's mostly a
  reminder not to introduce a second style.

- **Cross-boundary data (config-to-service, action input/output) is a
  `spatie/laravel-data` `Data` object under `src/Data/**`, not a plain
  readonly class or associative array.** Also mostly the installed
  package's own default — noted so a plain readonly DTO doesn't get
  introduced as a second style.

- **Reach for an existing action before writing logic inline.** Before
  putting logic in a Livewire component, controller, listener, command or
  another action, look for one that already does it:
  `grep -ril '<verb>' src/Actions/` — 67 classes across six domains
  (`Auth`, `Billing`, `Cache`, `Invitations`, `Queries`, `Tenancy`), so the
  odds are real. If one exists, call it: `Foo::run(...)`. If none exists and
  the logic is more than a couple of lines of framework glue, write a new
  action rather than inlining, so the next caller finds it. Inline is right
  only for transport concerns (validation wiring, redirects, response
  shaping) and single-use, single-entrypoint glue. Actions call other
  actions directly (`Foo::run()`), not through the container. The payoff is
  testability: an extracted action lets the caller's branches be tested with
  `Foo::shouldRun()` / `Foo::shouldNotRun()` instead of hitting the side
  effect.

## A swap seam nothing crosses is not a seam (2026-09-15)

Before adding a contract to the swap-seam population above, ask what actually
crosses it: a second core implementation, a documented host override, or a test
double. Being listed in `numerosis.{billing,tenancy}.implementations` is not
sufficient on its own — the config row is the intent, not the evidence.

`MoneyFormatter` and `TenantDatabaseManager` were both listed and neither was
crossed. The first was a 21-line wrapper over `Cashier::formatAmount()` behind
`BillingService`, itself behind the `Billing` facade, with one caller and no
test reference; it was folded into `BillingService`. The second is a real seam
whose payoff — testing `CreateTenantDatabase` without a MySQL database —
nothing collected, so it got `tests/Support/FakeTenantDatabaseManager` instead
of being inlined. Which of the two applies is a judgement about whether the
seam is worth having, and the test double is what settles it either way.

## A contract hint that reads a constant off the implementation is not a seam
Type-hinting an interface and then reading a constant off the concrete class hands a host that swapped the binding the concrete class anyway, and nothing goes red: `SeatLimitPlanPolicy` hinted `Contracts\Billing\Entitlements` and read `PlanEntitlements::SEATS`. Capability constants live on the interface now. Keep them strings, not an enum, so a host can extend the vocabulary.

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

- **`Services/` is only for that.** The rule above used to read as an
  absolute while holding for 12 of 24 files. Four value objects and
  `Responsable` wrappers lived under `Services/Billing/Checkout/` and moved
  to `Data/Billing/Checkout/` and `Http/Responses/Billing/` on 2026-09-11.
  One resident implements nothing and is deliberate: `BillingService` (the
  `Billing` facade's root). `TaxIdType` moved to `Enums\Billing\TaxIdType`
  2026-09-12 (enum-vocabulary-sweep phase 6) — it was a lookup table over a
  closed set of Stripe tax id types, which is exactly what an enum is for.
  `tests/Feature/ArchTest.php` names the one remaining exception and fails on
  a second, so adding one is an edit to that list, not a drive-by. Classes that validate
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

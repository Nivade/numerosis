# Plan: delete `src/Support/`, tighten `src/Services/`, split the front door

## Status — executed 2026-09-11, all seven phases

`a4b806c` `2f23090` `cd6038e` `c29cec4` `deef2fa` `e381874` `b770fec`.

Green at every phase: Pint clean, cold PHPStan `[OK] No errors`, baseline
unchanged at 5 entries. Suite **654 passed / 6 skipped** at the start,
**658 / 6** at the end (+4 new arch tests). `composer serve` returns **200**
on `/` — the 500 that `pr-review-remediation.md` 8.4 recorded at `3c3de4e`
does not reproduce.

### Two deviations from the plan as approved

- **Phase 5 shipped `@use`, not a `numerosis_route()` helper.** The helper
  would have fixed route names only, at the cost of three global functions, a
  `files` autoload entry, stringly-typed keys and a test keeping a `match` in
  sync. `@use` is standard Blade, costs nothing, keeps `RouteNames::home()`
  type-safe, and generalizes: all ~50 inline FQCNs across 22 views now go
  through it, not just the five route names. The coupling the helper would
  have removed is covered instead by `BladeClassReferencesTest`, which
  asserts every class any view names actually exists — a wider net than the
  helper offered, since it also covers the enums, models and feature classes
  views reference.
- **The planned "Boot/ may not reference Models" arch test was dropped.**
  `ModelResolver` and `UserModels` both name `Models\User` in their return
  types, legitimately. The rule would have been wrong rather than strict.

### Not done here

`numerosis-thin-app` imports `Numerosis` and `HostConfig` and needs its own
pass. Out of scope by the plan; still outstanding.

### Two traps worth knowing before the next move of this shape

Both are recorded in `.ai/rules/package-host-bootstrap.md`:

- Same-namespace resolution hides a missing import. `HostConfig` called
  `FeatureRegistry::enabled()` with no `use` line. Cold PHPStan caught it;
  no test would have.
- A test reading a class's source by hardcoded path is invisible to an FQCN
  grep. `HostRequirementsTest` read `'/src/Support/HostConfig.php'`.

And one about the toolchain, already in `.ai/rules/static-analysis.md`: a
`composer dump-autoload` regenerates Testbench's package-discovery cache and
flips Larastan's host-subclass narrowing, producing a burst of
`App\Models\Central\*` errors in `tests/` that vanish on the next cold run.
Hit twice here (52 errors, then 21). Re-run cold before believing it.

---

## Context

`refactor/src-reorg` fixed the *obvious* contradictions in `src/` (two homes
for console commands, two things named Features, flat folders next to
domain-subfoldered siblings). It stopped short of the two folders that are
still grab bags, and it shipped no enforcement — which is how
`comment-destyle.md`'s finished sweep regressed underneath the same reorg
(`pr-review-remediation.md` phases 5.5/6.1/6.2).

Three problems, measured at `a47acb1`:

**1. `src/Support/` is named after nothing.** Every other top-level folder
says what the thing *is*. `Support` is the residue, and it holds the
highest-traffic classes in the package:

| File | Call sites | What it actually is |
|---|---|---|
| `HostConfig.php` | 26 | writes host config |
| `Domains.php` | 13 | reads host config |
| `Assets.php` | 10 | host asset seam |
| `ModelResolver.php` | 8 | resolves host model overrides — consumed **only** by `src/Numerosis.php` |
| `FeatureRegistry.php` | 18 imports | feature query API; `src/Features/` already exists |
| `Routes/RouteNames.php` | 16 imports | single-file folder |
| `Cache/{CacheKeys,GlobalCache}.php` | 11 / 10 | cache layer |
| `Billing/TaxIdType.php` | 2 | billing domain |
| `Tenancy/RegistrationState.php` | 6 | `extends Spatie\LivewireWizard\Support\State`, used only by `src/Livewire/Tenant/Registration*` |

`7197177` hoisted `Numerosis.php` out of `Support/` for exactly this reason,
then stopped after one file.

**2. `src/Services/` states an absolute it doesn't keep.**
`.ai/rules/architecture-conventions.md` says contract in `Contracts/<Domain>`,
implementation in `Services/<Domain>`. True for 12 of 24 files. Leaks:

- Four non-services in `Services/Billing/Checkout/`: `ResolvedSetupIntent` and
  `ResumedCheckout` are `final readonly` value objects; `CheckoutIntentResponse`
  and `RedirectResponsable` are `Responsable` wrappers. `src/Data/Billing/` and
  `src/Http/Responses/Auth/` both already exist as the right homes.
- `Services/Billing/Resolvers/` groups by name-suffix while its siblings
  (`Plans/`, `Subscriptions/`, `Checkout/`) group by subdomain — and only 2 of
  its 5 are resolvers (`SeatLimitPlanPolicy` implements `PlanPolicy`,
  `DefaultUnpaidTenantQuota` implements `UnpaidTenantQuota`,
  `CashierMoneyFormatter` implements `MoneyFormatter`). `5862566` claims it
  dropped `Resolvers/`; it dropped tenancy's only.
- Five un-contracted residents: `BillingService` (facade root),
  `ConfiguredSteps` (pure config validation), `UserModelResolver`,
  `Tenancy/Bootstrappers/*` (stancl's interface), `PreservingPathTenantResolver`
  (stancl's). Fine that they exist; not fine that the rule reads as absolute.

**3. `src/Numerosis.php` is a 589-line / 27-method grab bag** — the class
`README.md:74` tells a host to import. It is the front door *and* the
route-loading implementation (~140 lines), the middleware alias/group builders,
and the exception wiring.

Plus one cross-folder collision: `Support\ModelResolver` and
`Services\Tenancy\UserModelResolver` are both static "which class for this
model", in different folders, on different config keys, neither referencing the
other.

### Intended outcome

No folder in `src/` named for the absence of a category. `Services/` means one
thing. `Numerosis.php` readable in one screen. Arch tests that fail when any of
it drifts back.

Breaking changes are free — no installs beyond thin-app. No shims, no
deprecation paths.

---

## Phase 1 — `src/Boot/`: the host seam

New folder. One class per thing the package reads from, writes to, or validates
in the host application. `src/Numerosis.php` stays at root as the only class a
host imports; `Boot/` is its backstage.

| From | To |
|---|---|
| `Support/HostConfig.php` | `Boot/HostConfig.php` |
| `Support/Domains.php` | `Boot/Domains.php` |
| `Support/Assets.php` | `Boot/Assets.php` |
| `Support/ModelResolver.php` | `Boot/ModelResolver.php` |
| `Services/Tenancy/ConfiguredSteps.php` | `Boot/ConfiguredSteps.php` |
| `Services/Tenancy/UserModelResolver.php` | `Boot/UserModels.php` |

`UserModelResolver` → `UserModels` ends the `ModelResolver` name collision:
`ModelResolver` answers "which class for this *package model*",
`UserModels` answers "which user model on this *guard*". While renaming, fold
the duplicated ternary at `src/Concerns/Tenancy/TenancyAwareUserModel.php:14`
and `src/Actions/Queries/FindUserByGlobalId.php:32` into a third method,
`UserModels::current()`.

`ConfiguredSteps` moves because it is boot-time validation of
`numerosis.tenancy.{provisioning,registration}.steps`, not a service — its own
docblock says callers run it on console boots only.

## Phase 2 — the rest of `Support/` goes home, folder deleted

| From | To |
|---|---|
| `Support/FeatureRegistry.php` | `Features/FeatureRegistry.php` |
| `Support/Cache/{CacheKeys,GlobalCache}.php` | `Cache/{CacheKeys,GlobalCache}.php` |
| `Support/Routes/RouteNames.php` | `Routing/RouteNames.php` |
| `Support/Billing/TaxIdType.php` | `Services/Billing/TaxIdType.php` |
| `Support/Tenancy/RegistrationState.php` | `Livewire/Tenant/Registration/RegistrationState.php` |

`src/Support/` no longer exists after this phase.

## Phase 3 — `src/Services/` means one thing

| From | To |
|---|---|
| `Services/Billing/Checkout/ResolvedSetupIntent.php` | `Data/Billing/Checkout/ResolvedSetupIntent.php` |
| `Services/Billing/Checkout/ResumedCheckout.php` | `Data/Billing/Checkout/ResumedCheckout.php` |
| `Services/Billing/Checkout/CheckoutIntentResponse.php` | `Http/Responses/Billing/CheckoutIntentResponse.php` |
| `Services/Billing/Checkout/RedirectResponsable.php` | `Http/Responses/Billing/RedirectResponsable.php` |
| `Services/Billing/Resolvers/*` (5 files) | `Services/Billing/*` |

`Services/Billing/Checkout/` keeps `InlineCheckoutGateway` and
`LocalCheckoutGateway` only — both implement `Contracts\Billing\CheckoutGateway`.

The two `Data/Billing/Checkout/` arrivals are plain `final readonly` classes
today, not `spatie/laravel-data` objects. Convert them only if they cross a
serialization boundary; `ResolvedSetupIntent` carries a `Stripe\PaymentMethod`,
so it probably should not. Note the exception in the rule file rather than
forcing it.

## Phase 4 — split the front door

`src/Numerosis.php` becomes one-line delegations. Extractions:

| New class | Absorbs from `Numerosis.php` |
|---|---|
| `Routing/RouteLoader.php` | `routes()`, `loadAuthRoutes()`, `loadFortifyRoutes()`, `fortifyRoutesPath()`, `loadOneTimePasswordRoutes()`, `routesRegistered()`, `registerRoutesUsing()` + the `$routesRegistered` static |
| `Boot/MiddlewareRegistrar.php` | `middlewareAliases()`, `middlewareGroups()`, `middleware()`, `csrfExceptions()`, `middlewareRegistered()`, `resetMiddlewareRegisteredForTesting()`, `registerMiddlewareUsing()` + the `$middlewareRegistered` static |
| `Boot/ExceptionRegistrar.php` | `exceptions()`, `registerExceptionsUsing()` |

`Numerosis.php` keeps every existing public method signature, delegating. No
host-facing API change.

**Two hazards, both load-bearing:**

1. `middlewareAliases()`/`middlewareGroups()` must stay **pure class-string
   literals with no `Config` or `Facade` read** — `Numerosis::middleware()`
   runs from `bootstrap/app.php` before `RegisterFacades`. The docblock at
   `src/Numerosis.php:326` states this; it travels verbatim to
   `MiddlewareRegistrar`. Regression test already exists:
   `NumerosisSeamTest` — "registers mode-agnostic middleware aliases with no
   facade application bound".
2. `.ai/rules/middleware-registration.md` records two alias registries that
   already drift. This must not create a third: `MiddlewareRegistrar` is the
   one definition, `Numerosis::middlewareAliases()` delegates to it, and
   `TenancyServiceProvider::makeTenancyMiddlewareHighestPriority()` keeps
   reading the same source.

`src/Facades/Numerosis.php`'s docblock still says `bootstrap/app.php` imports
`Support\Numerosis` — stale since `7197177`. Fix in this phase.

## Phase 5 — Blade route ergonomics

Nine Blade files reach route names through a 50-char inline FQCN
(`\Nvade\Numerosis\Support\Routes\RouteNames::tenantsMine()`), 12 lines total.
PHPStan does not analyse Blade, so a bad move here fails at render time only.

Add `src/helpers.php`, autoloaded via a `files` entry in `composer.json`:

```php
numerosis_route(string $key, array $parameters = []): string   // returns the URL
numerosis_route_exists(string $key): bool                      // Route::has()
```

Both resolve `$key` through a `match` that calls `Routing\RouteNames`, so there
is one source of truth and PHPStan checks the arms. Trade-off accepted: the
string key loses call-site type-safety that `RouteNames::home()` had. Mitigated
by a test asserting the `match` arms and the `numerosis.routes.names.*` keys are
the same set, and by the view-render tests that already cover these layouts.

PHP callers keep the typed static methods — the helper is Blade-only ergonomics.

Files to update: `resources/views/{home.blade.php, components/footer.blade.php,
layouts/auth/{card,simple,split}.blade.php, layouts/⚡header.blade.php,
pages/tenant/⚡suspended.blade.php}`. The two `BillingService` resolves in
`components/billing/order-summary.blade.php` and
`livewire/tenant/registration/wizard/steps/plan.blade.php` become the `Billing`
facade instead of `resolve(BillingService::class)`.

## Phase 6 — enforcement, so this does not regress

`tests/Feature/ArchTest.php` already carries Finder-based structural tests.
Add, in that style:

- No class under `Nvade\Numerosis\Services` that is not (a) bound in a service
  provider, (b) a facade root, or (c) implementing a `Nvade\Numerosis\Contracts`
  or vendor interface. Allow-list the five known exceptions **by name**, so
  adding a sixth is a deliberate edit.
- No `Nvade\Numerosis\Support` namespace exists. One line, permanent.
- `src/Boot/**` contains no `Nvade\Numerosis\Models` reference (same shape as
  the existing "billing contracts do not depend on app models" arch test).
- `Boot\MiddlewareRegistrar::middlewareAliases()`/`middlewareGroups()` source
  contains no `Config`/`Facade`/`config(` token.
- No `.blade.php` under `resources/` or `packages/*/resources/` contains
  `Nvade\Numerosis\` — forces the helper and kills the silent-breakage class.

## Phase 7 — docs and rules

`.ai/rules/` files naming the moved paths, all of which need repointing:
`architecture-conventions.md` (rewrite the `Services/` bullet to the honest
rule + the five exceptions), `package-boundaries.md`, `package-host-bootstrap.md`,
`tenant-caching.md`, `tenant-registration-wizard.md`, `billing-checkout.md`,
`middleware-registration.md`, `auth-guards.md`, `index.md` (its glob table).

Keep every `paths:` frontmatter block intact and update the globs —
`record-rule` regenerates `index.md` from them and silently drops any file
without one.

Also: `docs/architecture.md`, `docs/extending.md`, `docs/host-requirements.md`,
`README.md:74`, and `config/numerosis.php` (11 `use` lines at `:45–56`, plus the
`Support\Cache\CacheKeys` prose at `:214`). `config/numerosis.php` is the
published host config — this is the one deliberate host-facing break.

Test directories mirror `src/` and move with it: `tests/Feature/Support/*` splits
between a new `tests/Feature/Boot/` and the domain folders,
`tests/Feature/Services/Billing/Resolvers/` flattens, and the stale
`tests/Feature/Resolvers/` (left behind by `5862566`) folds into
`tests/Feature/Services/Tenancy/`.

---

## Scope

121 files reference `Nvade\Numerosis\{Support,Services}` today. Phases 4–5 add
`src/Numerosis.php`, 3 new classes, `composer.json`, and 9 Blade files.

Mechanical throughout — namespace and import churn, no behavior change. The one
real risk is Blade, which Phase 5 removes by construction and Phase 6 locks.

## Verification

Per phase, not once at the end:

1. `vendor/bin/pint --dirty --format agent` — before tests, per
   `feedback_pint_before_tests`.
2. `rm -rf build/phpstan && composer analyse` — **cold**, per
   `.ai/rules/static-analysis.md`; a warm result cache hides errors after a
   move. Baseline must stay at 5 entries.
3. `docker start numerosis-mysql-1`, then `composer test`. Baseline to beat:
   **649 passed / 6 skipped / 0 failed** at `3c3de4e`.

End-to-end, after Phase 5 — the suite does not render every affected layout:

4. `composer serve`, then load the workbench app. Note `pr-review-remediation`
   phase 8.4: `composer serve` currently opens a **500** on `/` at `3c3de4e`,
   pre-existing. Confirm it is the same 500, not a new one.
5. Grep gate, must return zero: `grep -rn 'Nvade\\Numerosis\\' --include='*.blade.php' resources packages`
6. Arch tests from Phase 6 must be **confirmed failing** against the pre-move
   tree before being accepted — per `.ai/rules/testing.md`, make a repaired
   assertion fail first.

Not verified here: thin-app. It imports `Numerosis` and `HostConfig` and will
need its own pass. Out of scope for this plan; flag it at the end so the host
checkout gets updated deliberately.

## Commit shape

One commit per phase, `refactor(structure):` prefixed, matching the existing
run (`0c3c5b6`, `7197177`, `bc1c664`, `5862566`, `a1bb447`). Phase 7 as
`docs:`. Each commit green on Pint + cold PHPStan + full suite before the next
starts.

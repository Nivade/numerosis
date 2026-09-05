---
paths:
  - 'src/Http/Middleware/**'
  - 'routes/**'
---
# Middleware Registration

Extracted 2026-09-04 from `.ai/rules/index.md`'s preamble, which `record-rule`
regenerates and discards. The facts are the same; only the home changed.

- **Deleting a package deletes its middleware registrations, and nothing goes
  red.** `packages/filament`'s tenant panel was the only registration site for
  four core middleware. A unit test that instantiates a middleware directly and
  asserts on its `handle()` stays green when *nothing applies it to a route* —
  so Phase 1 (2026-09-03) shipped with `EnsureTenantSubscriptionActive`, the
  entire suspension enforcement, silently switched off. A route-level test
  caught it afterwards, not the middleware's own tests.

  **When a package that owned a middleware stack goes, enumerate that stack
  before deleting it and give every core entry a new home or an explicit
  grave.** Of the four: `EnsureTenantSubscriptionActive` got a new home,
  `UpdateUserLastSeenMiddleware` and the whole `last_seen_at` leg got the
  grave.

  The test shape that would have caught it is a route-level assertion — hit the
  route as a suspended tenant and assert the redirect — not another unit test
  on the class.

- **`tenancy.subscription` is deliberately *not* on the `tenant` group.** It
  redirects to `tenant.suspended`, which is itself a tenant route, so a
  group-wide registration loops until the request dies. It sits on a nested
  `Route::middleware('tenancy.subscription')->group(...)` inside
  `routes/tenant.php` (~line 80), with `account-suspended` and the routes a
  suspended user still needs (billing, logout) left outside it. Adding a route
  that a suspended tenant must reach means adding it *outside* that inner
  group, not adding an exclusion. It reads `tenant()` and never the user, so
  auth is not a precondition; it sits inside the authenticated group because
  that is the surface worth gating, not because it needs a guard.

- **There are two alias registries and they must not drift.**
  `NumerosisServiceProvider::registerMiddlewareAliases()`
  (`src/NumerosisServiceProvider.php:467-486`) calls `Route::aliasMiddleware()`
  for the real boot; `Numerosis::middleware()` / `::middlewareAliases()`
  (`src/Support/Numerosis.php:401-435`) return the same names for a host
  wiring them into its own `bootstrap/app.php`. A middleware added to one and
  not the other works in this repo and fails in a host, or the reverse. See
  `.ai/rules/package-host-bootstrap.md` for why `Numerosis::middleware()` is
  fatal on a real non-Testbench boot.

- **`tenancy.identification` and `tenancy.route` resolve through
  `TenancyServiceProvider`, not to a class literal.** Both aliases are bound to
  `TenancyServiceProvider::identificationMiddleware()` /
  `::tenancyRouteMiddleware()`, which pick the class from the configured
  identification mode. Grepping for a middleware class name will therefore miss
  its registration — see `.ai/rules/identification-modes.md`.

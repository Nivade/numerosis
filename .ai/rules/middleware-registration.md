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

- **One alias registry, and it is structured that way on purpose.**
  `Numerosis::middlewareAliases()` / `::middlewareGroups()` are the single
  source: `NumerosisServiceProvider::registerMiddleware()` iterates them for
  the real boot, and `Numerosis::middleware()` iterates the same two for a host
  wiring them into its own `bootstrap/app.php`. There is no second copy.

  **Keep it that way.** These were two hand-written lists once, and a
  middleware added to one and not the other worked in this repo and failed in
  a host, or the reverse — with nothing red either way, since neither surface
  is exercised by the other's tests. A new alias belongs in
  `middlewareAliases()` and nowhere else. See
  `.ai/rules/package-host-bootstrap.md` for why `Numerosis::middleware()` is
  fatal on a real non-Testbench boot.

- **`tenancy.identification` and `tenancy.route` resolve through
  `TenancyServiceProvider`, not to a class literal.** Both aliases are bound to
  `TenancyServiceProvider::identificationMiddleware()` /
  `::tenancyRouteMiddleware()`, which pick the class from the configured
  identification mode. Grepping for a middleware class name will therefore miss
  its registration — see `.ai/rules/identification-modes.md`.

- **`NumerosisServiceProvider::registerMiddleware()` stands down once `Numerosis::middleware()` has run.** Until 2026-09-05 it re-applied every alias, `TrustProxies::at('*')` and `prependMiddleware(TrustHosts::class)` unconditionally from `packageBooted()` — which runs *after* a host's `withMiddleware()` closure, so anything the host changed in that closure was reverted before the first request, silently. `Numerosis::middleware()` now sets a process-lifetime `$middlewareRegistered` flag (mirroring `$routesRegistered`), read back through `Numerosis::middlewareRegistered()`, and the provider returns early when it is set. The self-heal still runs for a host that never calls it at all.

  Two consequences. **Order inside the closure is now load-bearing**: call `Numerosis::middleware($middleware)` first, then your own configuration, because nothing re-applies the package's afterwards. And **the flag leaks between tests** — one test calling `Numerosis::middleware()` would otherwise disable the provider's registration for every later test in the same worker, so `Tests\TestCase::setUp()` calls `Numerosis::resetMiddlewareRegisteredForTesting()` before `parent::setUp()`. `tests/Feature/Support/HostMiddlewareNotRevertedTest.php` holds both halves down.

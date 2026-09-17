---
paths:
  - 'src/Http/Middleware/**'
  - 'src/Boot/MiddlewareRegistrar.php'
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

- **`tenancy.subscription` gates two states, and the closed one answers
  first** (added 2026-09-16). `EnsureTenantSubscriptionActive` reads
  `closed_at` before `suspended_at`: a closure the owner asked for outranks a
  payment that failed, and the two screens say opposite things ("here is how
  to undo this" against "fix your card"). Both targets — `tenant.closed` and
  `tenant.suspended` — sit outside the inner group for the loop reason above,
  and so does `tenant.reopen`, since the owner undoing a closure has to reach
  it while the closure is still in force.

  **Both branches call `to_route()`, which throws in path identification
  mode.** `route('tenant.closed')` needs the `{tenant}` parameter no
  `URL::defaults()` supplies (see `central-rows-on-tenant-routes.md`), so the
  gate is `UrlGenerationException` rather than a redirect in that mode. The
  suspended branch has always had this; the closed one copies it. A test
  cannot see it in the default mode either: `Tests\TestCase` forces a central
  root URL, so the redirect resolves to the *central* host and a bare
  `assertRedirect()` still passes. `TenantClosureTest` asserts the path with
  `assertRedirectContains()` for that reason.

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

- **`middlewareAliases()` may return class-string literals only, and that is
  load-bearing.** It is evaluated inside a host's `bootstrap/app.php`, where
  `ApplicationBuilder::withMiddleware()` runs it through `afterResolving(HttpKernel)`
  — before `RegisterFacades`. Any `Config` or facade read there either fatals
  or, worse, silently returns a default: `tenancy.identification` and
  `tenancy.route` used to call `IdentificationMode::current()` here, whose
  facade-null guard returned `Subdomain`, so a host configured for `path` booted
  with subdomain middleware and every test in this repo stayed green.

  Both aliases are literals now — `Http\Middleware\InitializeTenancy` and
  `TenantRouteGuard` — which read the mode per request and delegate. Two
  consequences. Grepping for a mode's real middleware class will miss its
  registration (see `identification-modes.md`). And
  `TenancyServiceProvider::makeTenancyMiddlewareHighestPriority()` must name
  both delegating classes: Laravel's priority sort matches the alias's literal
  target, not what it delegates to, and missing this broke tenant-session
  ordering in three auth tests.

- **`NumerosisServiceProvider::registerMiddleware()` stands down once `Numerosis::middleware()` has run.** Until 2026-09-05 it re-applied every alias, `TrustProxies::at('*')` and `prependMiddleware(TrustHosts::class)` unconditionally from `packageBooted()` — which runs *after* a host's `withMiddleware()` closure, so anything the host changed in that closure was reverted before the first request, silently. `Numerosis::middleware()` now sets a process-lifetime `$middlewareRegistered` flag (mirroring `$routesRegistered`), read back through `Numerosis::middlewareRegistered()`, and the provider returns early when it is set. The self-heal still runs for a host that never calls it at all.

  Two consequences. **Order inside the closure is now load-bearing**: call `Numerosis::middleware($middleware)` first, then your own configuration, because nothing re-applies the package's afterwards. And **the flag leaks between tests** — one test calling `Numerosis::middleware()` would otherwise disable the provider's registration for every later test in the same worker, so `Tests\TestCase::setUp()` calls `Numerosis::resetMiddlewareRegisteredForTesting()` before `parent::setUp()`. `tests/Feature/Boot/HostMiddlewareNotRevertedTest.php` holds both halves down.

- **`TrustProxies::at('*')` was an unsafe default, fixed 2026-09-12.** Both
  `MiddlewareRegistrar::apply()` (the documented `Numerosis::middleware()`
  path) and the self-heal branch above used to trust every request's
  `X-Forwarded-*` headers unconditionally — meaning `$request->ip()` was
  spoofable by anyone who could reach the app directly, which silently
  defeats every IP-keyed rate limiter (`NumerosisServiceProvider`'s login,
  OTP and social limiters included) and falsifies audit logs. `apply()` no
  longer calls `trustProxies()` at all — Laravel's own default (trust
  nobody) is the safe one, and a host on that path adds
  `$middleware->trustProxies(at: [...])` itself, same as any bare Laravel
  app. The self-heal branch reads `numerosis.trusted_proxies`
  (`MiddlewareRegistrar::trustedProxies()`), default empty, so a host that
  never wires `bootstrap/app.php` at all still has to opt in — see
  `docs/host-requirements.md`'s "Trusted proxies" section for the
  reachable-only-through-the-proxy-vs-directly-reachable distinction that
  decides `'*'` vs a real IP/CIDR list. **`apply()` itself still cannot read
  config or call a facade** (same constraint as `aliases()`/`groups()`,
  documented at the top of `MiddlewareRegistrar` — it runs from a host's
  `withMiddleware()` closure, before `RegisterFacades`), which is why it was
  simplest to drop the call there rather than make it config-driven; only the
  self-heal branch, which runs from `packageBooted()`, reads config.

## Which gates can sit on the tenant group, and why the route group is not a substitute
A gate that redirects to a *central* route is safe on the `tenant` group; one that redirects to a tenant route loops, which is why `tenancy.subscription` stays off it. `EnsureTwoFactorEnrolled` was kept off on the looping argument and does not loop, so a tenant requiring a second factor let an unenrolled member reach every route a host added. Gating only the routes this package registers leaves every host route ungated and no test here sees that: gate on the group and opt individual routes out.

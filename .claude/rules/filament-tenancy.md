---
topic: filament-tenancy
updated: 2026-07-28
---
# Filament Tenancy vs stancl Tenancy

> **Everything Filament-side here lives in `packages/filament`
> (`nvade/numerosis-filament`) as of 2026-08-30**, and two names below are
> from before that: the panel opt-in is `NumerosisTenantPlugin::panel()`
> (`packages/filament/src/NumerosisTenantPlugin.php:140-141`), not a host
> `TenantAdminPanelProvider` — the providers are
> `packages/filament/src/Providers/Numerosis{Admin,Tenant}PanelProvider.php`
> and register themselves. `actingAsTenantPanelUser()` is on
> `Nvade\NumerosisFilament\Testing\InteractsWithTenantPanel`. Core names
> `Filament\` in 11 files, all lazily; see `.claude/rules/package-boundaries.md`.
> Every claim below still holds — the seam between the two tenancy systems is
> unchanged by the move.

- **Two tenancy systems run side by side and share no state.** stancl owns the
  database, cache prefix, queue payload, auth guard and Spatie permission
  registrar; Filament owns its own "current tenant", a plain property on
  `FilamentManager` that `Filament::setTenant()` assigns.
  `TenantAdminPanelProvider` opts into Filament's system with
  `->tenantDomain('{tenant}.nvade.dev')` and `->tenant(Tenant::class, 'id')`,
  which puts a `{tenant}` parameter on *every* route in that panel, filled from
  `Filament::getTenant()` at URL-generation time.

  `$tenant->run(...)` therefore does **not** put Filament in tenant context. It
  switches everything stancl owns and leaves Filament's tenant null, so the
  first `route()` call in a rendered page throws

  ```
  Missing required parameter for [Route: filament.tenantAdmin.profile.pages.delete-account]
  ```

  A `FilamentManager::getTenantName(): string ... null returned` TypeError is
  the same cause arriving somewhere typed. Both read like routing or panel
  bugs. Neither is.

- **Only tests hit this.** A real request runs Filament's own middleware, which
  resolves the tenant from the domain segment. `Livewire::test()` issues no
  request, so nothing sets it. Enter a tenant panel in tests through
  `Tests\TestCase::actingAsTenantPanelUser($tenant, $user)`, which does the
  three things a request does — authenticate on the tenant guard, set the
  current panel, set Filament's tenant. Setting two of the three and forgetting
  the third is the whole trap; that is why it is one method and not three lines
  copied into each test. 14 failures across four files were this.

  ## Suggested better approach

  A tenancy bootstrapper mirroring stancl's tenant into `Filament::setTenant()`
  would remove the divergence at the source, and **does not work here**:
  `TenancyServiceProvider::makeTenancyMiddlewareHighestPriority()` forces
  stancl's identification ahead of Filament's panel setup, so at bootstrap time
  there is no current panel to set a tenant on and the bootstrapper would no-op
  exactly when it is needed. The reverse — a Filament middleware reading
  `tenant()` — is redundant in HTTP (Filament already resolves it from the
  domain) and unavailable outside it. So the seam stays, and the helper is the
  honest fix. If Filament's tenancy is ever dropped from the panel in favour of
  stancl's domain identification alone, the `{tenant}` parameter and this whole
  class of failure go with it.

- **Filament's tenant must be a model its panel can resolve, not stancl's
  current tenant object by coincidence.** `->tenant(Tenant::class, 'id')` means
  the panel resolves `{tenant}` through `Tenant::resolveRouteBinding($key, 'id')`.
  A tenant whose `id` was generated rather than set — see the non-fillable `id`
  trap in `.claude/rules/tenant-provisioning.md` — resolves to nothing and the
  panel answers **404**, which is how that trap surfaced in
  `TenantAdminAuthTest`.

- **Filament page tests break for reasons that have nothing to do with
  tenancy, and the tenancy error hides them.** Once the `{tenant}` failures
  were fixed, what remained in the same files was ordinary API drift:
  `getSidebarItems()`/`getActiveSidebarItem()` belonged to a sidebar plugin
  that has since been removed (those tests were deleted), and
  `disconnectSocialAccount` is now a Filament Action
  (`SocialAccounts::disconnectSocialAccountAction()`), not a public method a
  test can `->call()`. Fix the tenancy setup first, then read what is actually
  left.

- **`NumerosisTenantPlugin::shouldRegisterPanel()` deliberately registers the
  tenant panel on a central-domain request whenever `app()->runningInConsole()`
  is true — and that exemption is what makes "central route wins over the
  tenant wildcard" untestable from Pest/tinker, not a routing bug.** The
  panel's `{tenant}.<domain>` pattern syntactically matches a central domain
  that happens to be a single label too (`app.thinapp.dev` matches
  `{tenant}.thinapp.dev` with `tenant=app`), so the plugin normally skips
  registering itself there — real HTTP (`php-fpm`, or even PHP's built-in
  server, both report `PHP_SAPI !== 'cli'` so `runningInConsole()` is false)
  only ever sees the central route and resolves `/` correctly. Console
  (`PHP_SAPI === 'cli'`, i.e. every artisan command, Pest, and Tinker) is
  *exempted from the skip on purpose* — so `route:list`, queue workers, and
  ordinary tenant-panel tests still see the panel — but that means any
  Pest/tinker code that then *dispatches a fake HTTP request* against the
  central domain (`Http\Kernel::handle()`/`$app->handleRequest()` called by
  hand with a `Request` built for `app.thinapp.dev`) still has the tenant
  panel registered, and the wildcard route wins the match, 404ing central's
  own `/`. Confirmed empirically 2026-08-12: identical `Request` objects,
  dispatched through the literal `public/index.php` flow, resolve to the
  central route under `php -S` (`PHP_SAPI = 'cli-server'`, so
  `runningInConsole()` false) and to the tenant wildcard under plain `php`
  or `artisan tinker` (`PHP_SAPI = 'cli'`) — same code, same request, only
  `PHP_SAPI` differs. **This is not a bug to fix — it is
  `shouldRegisterPanel()`'s documented tradeoff working as designed.** It
  does mean a "central routes bound per `tenancy.central_domains`" assertion
  cannot be written as a plain Pest HTTP-dispatch test — it will always see
  the tenant panel registered and always resolve the wildcard, regardless of
  what `tenancy.central_domains` actually contains.

  **A browser test does not fix this, which is the correction worth carrying.**
  `pestphp/pest-plugin-browser` (added 2026-08-31) serves Laravel
  **in-process** — an amphp socket in front of the same booted kernel the test
  holds — so `PHP_SAPI` stays `cli` and `runningInConsole()` is still `true`
  inside a browser request. The console exemption applies exactly as it does
  under `Livewire::test()`. Closing this needs a genuinely separate FPM or
  `php -S` server, which nothing here has; the available substitute is a
  narrow unit test against `shouldRegisterPanel()`'s decision logic (stub
  `runningInConsole()` false, assert it returns `false` for a central-domain
  request) rather than asserting on route-match outcome. Path mode *is*
  browser-covered (`tests/Browser/PathModeTest`) precisely because it
  registers no wildcard and so has no console dependence —
  `.claude/rules/identification-modes.md`.

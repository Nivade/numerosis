---
topic: filament-tenancy
updated: 2026-07-28
---
# Filament Tenancy vs stancl Tenancy

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

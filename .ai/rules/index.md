# Codebase Rules Index

Gotcha/invariant/why-this-way/cross-file trap stuff, learned from codebase. Not design doc — readable from code? skip it here.

Before planning or editing, find the row whose globs match the file's path and read that rule file. Keep this table in sync when a rule file is added, renamed or removed — one row per file, note under ~150 chars.

**These are traps, not orientation.** If you do not yet know how the package is
laid out or how it boots, read `docs/architecture.md` first, then
`docs/features.md` (what each feature toggles), `docs/extending.md` (the seams)
and `docs/host-requirements.md` (what a host owns). `.claude/plans/` is
historical — see `.claude/plans/README.md` before reading any plan.

**This copy is the only copy (decided 2026-08-31, superseding D11).** The old rule — saas-m and thin-app carry byte-identical copies, change here then re-copy — is retired. saas-m is archived; thin-app is a host app on a different toolchain (Sail, plain Laravel `TestCase`, its own `bootstrap/`), and `testing.md`, `static-analysis.md`, `package-split.md`, `package-boundaries.md` and `stancl-tenancy-v4.md` are now written against *this* repo's Testbench-without-Sail monorepo. A byte-identical copy would actively mislead there. **Copy individual rules into a host deliberately if it wants them; never sync the directory.** `host-integration-quickstart.md` is the file written for a host's point of view.

**`src/Support/` was deleted 2026-09-11**, along with the two-grouping-axis
`Services/Billing/` and the 589-line `src/Numerosis.php`. `Support` named the
absence of a category, so it accumulated: `HostConfig`, `Domains`, `Assets`,
`ModelResolver` are `Boot/` now (what the package reads from, writes to, or
validates in the host app), joined by `ConfiguredSteps`, `UserModels` (was
`Services\Tenancy\UserModelResolver`), `MiddlewareRegistrar` and
`ExceptionRegistrar`. `FeatureRegistry` is in `Features/`, `CacheKeys`/
`GlobalCache` in `Cache/`, `RouteNames` and the new `RouteLoader` in
`Routing/`, `TaxIdType` in `Services/Billing/`, `RegistrationState` in
`Livewire/Tenant/Registration/`. `Services/Billing/` is flat, mirroring
`Contracts/Billing/`. Blade reaches package classes through `@use` rather
than inline FQCNs. Four arch tests hold all of it down — see
`architecture-conventions.md` and `tests/Feature/ArchTest.php`; a fifth,
`BladeClassReferencesTest`, covers the views PHPStan cannot see.

**Layout, since 2026-09-03 (Phase 3):** one repo — core at the root, plus
`packages/ui` only. `packages/{auth-ui,account,onboarding}` folded into core;
`packages/filament` was deleted (Phase 1). Rules describing moved code carry
a header naming where it lives now. `package-boundaries.md` is the seam map.

**Auth moved onto `laravel/fortify` (Phase 4), and `torann/geoip`/
`ryangjchandler/laravel-cloudflare-turnstile` were dropped or demoted (Phase
6)**, both of `.claude/plans/archive/humming-nibbling-flame.md`. Core's own actions
(`CreateRegisteredUser`, `UpdateUserProfile`, `UpdateUserPassword`,
`ResetUserPassword`) are bound against Fortify's contracts rather than
running their own controllers; `ResolveCheckoutRegion` always returns null
now (no GeoIP lookup wired in); `TurnstileFeature::isEnabled()` gained a
`class_exists()` guard since the package is a `suggest`, not a `require`. See
`docs/extending.md` for the Fortify customization table and
`.ai/rules/auth-login.md` for what stayed core-only through the move.

**`packages/filament` was deleted 2026-09-03** (Phase 1 of `.claude/plans/archive/humming-nibbling-flame.md`), along with `filament/filament` itself, both panels, `config/numerosis/panels.php`, `Support\Compat\Filament*`, and `filament-tenancy.md`. Nothing in this repo names a `Filament\` symbol: `tests/Feature/PackageBoundariesTest.php` enforces it for all four satellites (`account` was missing from that data provider until the same day, so its rules had been vacuous since extraction) *and*, via `test_core_names_no_filament_symbol()`, for `src/`, `config/`, `routes/`, `resources/`, `database/` and `workbench/`. Any rule text below still describing a panel is describing history.

**The module system, and impersonation, were deleted 2026-09-03** (Phase 2 of
`.claude/plans/archive/humming-nibbling-flame.md`), along with `module-marketplace.md`
and `internachi/modular` — which is now not even a `suggest`. Gone with it:
`Actions/Modules`, `Contracts/{Modules,Billing/Module*}`,
`Models/{Central/ModuleOffering,Tenant/Module}` and their 4 migrations,
`ModuleSystemFeature`, `config/numerosis/modules.php`, the three
`tenants:*-module` commands, the `modules` permission context,
`ImpersonationFeature`, `Actions/Tenancy/ImpersonateTenantUser`,
`Exceptions/Tenancy/TenantHasNoOwner` and the `impersonate/{token}` route.
`config/numerosis.php` is one publishable file with eleven top-level keys
now, `numerosis.models` lists **nine** models, and
`Permission::additionalActions()` returns `[]` —
it was the module system's only caller, and it stays as the seam
`actionsFor()` reads. The `tenant_user_impersonation_tokens` migration
**stays**: it is stancl's, and a host may still register
`Stancl\Tenancy\Features\UserImpersonation` itself.

**Deleting a package deletes its middleware registrations, silently.** Moved to [middleware-registration.md](middleware-registration.md) on 2026-09-04 — it was load-bearing and lived only in this preamble, which `record-rule` discards on every regeneration. Anything else in this preamble is at the same risk; prefer a rule file.

**Bare commit hashes in these rules refer to the archived saas-m repo** (`git@gitlab.com:nvade_/saas-m.git`), not numerosis — `c66cc72`, `ddd7c35`, `de06293`, `438f12f`, `549223e`, `6b8c78c` are all saas-m's. Hashes for this package or thin-app always name their repo.

**Where rules live.** `.ai/rules/`, Boost's own default location — moved here
2026-09-02 from `.claude/rules/` (was Claude-specific; a dev on a different
Boost-integrated tool now sees the same rules with no tool-specific path).
Add new rules with the `record-rule` MCP tool (pass a `glob`, `title`, and a
short `note`); it writes here.

**`record-rule` regenerates this whole table from the `paths:` frontmatter it
finds, and drops every file that has none.** Observed 2026-09-04: one call
rewrote 82 lines down to 9 — the prose above and 19 of 22 rows gone, leaving
exactly the three files that then carried `paths:`. It does not self-correct;
that instance was restored from `git`. **Every rule file now carries a `paths:`
block**, added 2026-09-04 for this reason, so a regeneration reproduces the
full row set. It still discards this preamble and every row's note, so: diff
this file after any `record-rule` call, and if you add a rule file by hand,
give it `paths:` in the same edit or the next call deletes its row.

**`paths:` is the only frontmatter key a rule file carries.** Three
conventions had accumulated — none at all, `topic:`/`updated:`, and `paths:`.
The first two went on 2026-09-04. `topic:` was byte-for-byte the filename in
all seven files that had it, and `updated:` was stale in six of them, by up to
three weeks (`host-integration-quickstart.md` claimed 2026-08-12 against a
2026-09-02 commit). A hand-maintained date that git already tracks, and that is
wrong most of the time, is worse than no field: it invites trusting a rule
that has since changed underneath. Use `git log -1 -- .ai/rules/<file>` for
when something last moved. Date a *claim* inline instead, next to the
measurement it qualifies — which is what the rules that matter already do.

| Applies to | Rule file |
| --- | --- |
| `src/Contracts/**`, `src/Services/**`, `src/Actions/**`, `src/Data/**` | [architecture-conventions.md](architecture-conventions.md) — Contracts/Services mirror each other flatly; `Services/` is implementations only, with two named exceptions; Actions use `handle()`; DTOs are `spatie/laravel-data`. |
| `src/{Events,Listeners,Observers}/**`, `src/Notifications/**` | [events-listeners-observers.md](events-listeners-observers.md) — events carry scalars + `ShouldDispatchAfterCommit`; listeners are unordered and reactions only; core registers them by explicit `Event::listen()` map; the membership-to-tenant-user sync chain. |
| `src/**/Auth/**`, `src/Policies/**`, `config/numerosis.php` | [auth-guards.md](auth-guards.md) — guard follows tenancy; `web` = central; tenant guard outside tenancy reads central `users`; don't bind models. |
| `src/Actions/Auth/**`, `src/Livewire/**`, `resources/views/auth/**` | [auth-login.md](auth-login.md) — auth is Fortify's since Phase 4; core keeps the tenancy-specific pipeline steps and the actions bound against Fortify's contracts. Livewire method order is not enforced. Four audit findings (2026-09-04): no `route:cache`, request-time vs registration-time config, bootstrappers must be singletons, limiter tests need `tenancy()->end()`. Also covers OAuth identity matching (`Actions\Auth\Social\**`, rebuilt 2026-09-04): `(provider, provider_id)` only, never email alone; conditional email link. |
| `src/**/Billing/**`, `src/Http/Controllers/Billing/**` | [billing-checkout.md](billing-checkout.md) — `subscribable_id` is the owner's primary key not `global_id`; wizard bypasses StartCheckoutRequest; stale stripe_status. |
| `src/Exceptions/**`, `src/Concerns/Tenancy/TagsSentryScopeWithTenant.php`, `src/Jobs/**`, `database/migrations/**` | [exception-handling.md](exception-handling.md) — DomainException/ShowsMessageToUser split; TagsSentryScopeWithTenant fix tenant-less job-failure reports; failed_jobs trap. |
| `src/Models/**`, `src/Features/**`, `composer.json` | [optional-dependencies.md](optional-dependencies.md) — eager `implements`/`use trait` vs lazy type-hints; `Support\Compat\*`; one `class_exists` seam per package; absence only testable in a subprocess. |
| `packages/**`, `composer.json` | [package-split.md](package-split.md) — **historical**: how the six-package split was done and why three of those packages later folded back into core; the mechanisms (shared view namespace, vacuous-not-red scanning tests, constant autoload, config-write phase) are what `packages/ui` still relies on. |
| `src/Boot/**`, `src/Http/Middleware/**`, `packages/**` | [package-boundaries.md](package-boundaries.md) — **the seam map**, rewritten for the two-package (core + `numerosis-ui`) world: what a host contributes through and the boundary facts that still bite. Also covers `HostConfig`'s preference/correction split and its one deliberate asymmetry (the four tenancy model keys vs `auth.providers.users.model`). |
| `src/NumerosisServiceProvider.php`, `src/Numerosis.php`, `src/Boot/HostConfig.php`, `src/Boot/Domains.php`, `src/Routing/RouteLoader.php`, `src/Enums/Tenancy/IdentificationMode.php` | [package-host-bootstrap.md](package-host-bootstrap.md) — `Domains.php` can't call facades; host `bootstrap/app.php`/`providers.php` staleness; `HostConfig::apply()` register-vs-booting race; `Numerosis::middleware()` fatal on a real (non-Testbench) boot. |
| `src/**Tenancy**`, `config/numerosis.php` | [stancl-tenancy-v4.md](stancl-tenancy-v4.md) — **historical, 2026-09-04**: v4 port is permanently off the table, not deferred. No inline pointer to this file should exist in `src/` any more. |
| `phpstan.neon.dist`, `phpstan-baseline.neon`, `composer.json` | [static-analysis.md](static-analysis.md) — level 9, one config + baseline; warm result cache hides errors (compare cold-vs-cold); green doesn't survive `composer install`. |
| `src/Http/Middleware/**`, `src/Boot/MiddlewareRegistrar.php`, `routes/**` | [middleware-registration.md](middleware-registration.md) — deleting a package deletes its middleware registrations and nothing goes red; `tenancy.subscription` must stay off the `tenant` group or it loops; two alias registries that drift. |
| `src/Policies/**`, `src/Http/Controllers/**`, `src/Actions/Invitations/**`, `routes/tenant.php` | [central-rows-on-tenant-routes.md](central-rows-on-tenant-routes.md) — binding a `CentralConnection` model on a tenant route has no tenant scope and `deleteAny` is seeded to every tenant's admin; scope in the policy. Signed-link/row expiry interplay; two silent invitation-row traps. |
| `src/Cache/**`, `src/Providers/TenancyServiceProvider.php` | [tenant-caching.md](tenant-caching.md) — `global_cache()` un-prefixed; cross-tenant leaks of cached tenant models + shared session guard keys; `cache.serializable_classes` silently kills the domain resolver cache. |
| `src/Actions/Tenancy/**`, `src/Jobs/**`, `src/Console/Commands/**` | [tenant-provisioning.md](tenant-provisioning.md) — races between sync checkout redirect + async Stripe webhook; idempotency requirements; JobPipeline vs AsAction calling-convention conflict. |
| `src/Livewire/Tenant/Registration.php`, `src/Livewire/Tenant/Registration/**` | [tenant-registration-wizard.md](tenant-registration-wizard.md) — bare `@livewire()` view flattens child's component boundary; spatie wizard's `wizardClassName` needs alias resolution, not raw FQCN. Moved from `packages/onboarding` into core in Phase 3. |
| `tests/**` | [testing.md](testing.md) — no Sail (`vendor/bin/pest`); Playwright now required for *every* run; central rows escape RefreshDatabase; make a repaired assertion fail. |
| `resources/views/**` | [views.md](views.md) — `x-turnstile` compiles `@this` to `$_instance`, so passing `wire:model` from a plain Blade form is a 500, not a degraded widget. |
| `src/NumerosisServiceProvider.php`, `src/Boot/HostConfig.php` | [tenant-filesystem.md](tenant-filesystem.md) — `local` disk root tenant-suffixed but Livewire upload route never tenant-identified; dedicated `livewire` disk fixes it. |
| `src/Services/Tenancy/**`, `src/Http/Middleware/InitializeLivewireTenancyByPath.php`, `config/numerosis.php` | [identification-modes.md](identification-modes.md) — subdomain/custom-domain/path; slug ≠ domain; `{tenant}` literal enables dots; `/livewire/update` has no `{tenant}`, so path mode reads `Referer`. |
| `config/**`, `src/Console/Commands/InstallNumerosisCommand.php` | [host-integration-quickstart.md](host-integration-quickstart.md) — 7 silent traps integrating into a pre-existing host app (tabellio); all 7 fixed 2026-08-13 — read for the mechanism to reach for now. |
| `**/*.php` | [general.md](general.md) — comment style: default to zero, hard caps of 5 docblock prose lines and 3 `//` lines with no exemption for public seams, one fact per comment, no em-dash/"rather than" cadence, never cite `.ai/rules`, `.claude` or `docs/`. Validate preservation when shortening. |
| `**` (execution constraint) | [subagents.md](subagents.md) — execution constraint, not a codebase fact: never spawn sub-agents; the `.claude/agents/`+`.codex/agents/` that contradicted it were deleted 2026-09-01. |

# Codebase Rules Index

Gotcha/invariant/why-this-way/cross-file trap stuff, learned from codebase. Not design doc — readable from code? skip it here.

Before planning or editing, find the row whose globs match the file's path and read that rule file. Keep this table in sync when a rule file is added, renamed or removed — one row per file, note under ~150 chars.

**These are traps, not orientation.** If you do not yet know how the package is
laid out or how it boots, read `docs/architecture.md` first, then
`docs/features.md` (what each feature toggles), `docs/extending.md` (the seams)
and `docs/host-requirements.md` (what a host owns). `.claude/plans/` is
historical — see `.claude/plans/README.md` before reading any plan.

**This copy is the only copy (decided 2026-08-31, superseding D11).** The old rule — saas-m and thin-app carry byte-identical copies, change here then re-copy — is retired. saas-m is archived; thin-app is a host app on a different toolchain (Sail, plain Laravel `TestCase`, its own `bootstrap/`), and `testing.md`, `static-analysis.md`, `package-split.md`, `package-boundaries.md` and `stancl-tenancy-v4.md` are now written against *this* repo's Testbench-without-Sail monorepo. A byte-identical copy would actively mislead there. **Copy individual rules into a host deliberately if it wants them; never sync the directory.** `host-integration-quickstart.md` is the file written for a host's point of view.

**Layout, since 2026-08-31:** one repo — core at the root, plus `packages/{ui,auth-ui,account,onboarding}`, path-installed and published as read-only splits on tag. Rules describing moved code carry a header naming the package that holds it. `package-boundaries.md` is the seam map.

**`packages/filament` was deleted 2026-09-03** (Phase 1 of `.claude/plans/humming-nibbling-flame.md`), along with `filament/filament` itself, both panels, `config/numerosis/panels.php`, `Support\Compat\Filament*`, and `filament-tenancy.md`. Nothing in this repo names a `Filament\` symbol: `tests/Feature/PackageBoundariesTest.php` enforces it for all four satellites (`account` was missing from that data provider until the same day, so its rules had been vacuous since extraction) *and*, via `test_core_names_no_filament_symbol()`, for `src/`, `config/`, `routes/`, `resources/`, `database/` and `workbench/`. Any rule text below still describing a panel is describing history.

**The module system, and impersonation, were deleted 2026-09-03** (Phase 2 of
`.claude/plans/humming-nibbling-flame.md`), along with `module-marketplace.md`
and `internachi/modular` — which is now not even a `suggest`. Gone with it:
`Actions/Modules`, `Contracts/{Modules,Billing/Module*}`,
`Models/{Central/ModuleOffering,Tenant/Module}` and their 4 migrations,
`ModuleSystemFeature`, `config/numerosis/modules.php`, the three
`tenants:*-module` commands, the `modules` permission context,
`ImpersonationFeature`, `Actions/Tenancy/ImpersonateTenantUser`,
`Exceptions/Tenancy/TenantHasNoOwner` and the `impersonate/{token}` route.
`config/numerosis.php` assembles **thirteen** partials now, `numerosis.models`
lists **eight** models, and `Permission::additionalActions()` returns `[]` —
it was the module system's only caller, and it stays as the seam
`actionsFor()` reads. The `tenant_user_impersonation_tokens` migration
**stays**: it is stancl's, and a host may still register
`Stancl\Tenancy\Features\UserImpersonation` itself.

**Deleting a package deletes its middleware registrations, silently.** `packages/filament`'s tenant panel was the only registration site for four core middleware, and unit tests that instantiate a middleware directly stay green when nothing applies it. `EnsureTenantSubscriptionActive` (suspension enforcement) was off for the length of Phase 1 before a route-level test caught it; it is now the `tenancy.subscription` alias on a nested group in `routes/tenant.php`, deliberately not on the `tenant` group — it redirects to `tenant.suspended`, which is itself a tenant route, so a group-wide registration loops. `UpdateUserLastSeenMiddleware` and the whole `last_seen_at` leg were deleted instead. **When a package that owned a middleware stack goes, enumerate that stack before deleting it and give each core entry a new home or a grave.**

**Bare commit hashes in these rules refer to the archived saas-m repo** (`git@gitlab.com:nvade_/saas-m.git`), not numerosis — `c66cc72`, `ddd7c35`, `de06293`, `438f12f`, `549223e`, `6b8c78c` are all saas-m's. Hashes for this package or thin-app always name their repo.

**Where rules live.** `.ai/rules/`, Boost's own default location — moved here
2026-09-02 from `.claude/rules/` (was Claude-specific; a dev on a different
Boost-integrated tool now sees the same rules with no tool-specific path).
Add new rules with the `record-rule` MCP tool (pass a `glob`, `title`, and a
short `note`); it writes here.

| Applies to | Rule file |
| --- | --- |
| `src/Contracts/**`, `src/Services/**`, `src/Actions/**`, `src/Data/**` | [architecture-conventions.md](architecture-conventions.md) — Contracts/Services interface-implementation split; Actions use `handle()`; DTOs are `spatie/laravel-data`. |
| `src/**/Auth/**`, `config/auth.php` | [auth-guards.md](auth-guards.md) — guard follows tenancy; `web` = central; tenant guard outside tenancy reads central `users`; don't bind models. |
| `packages/auth-ui/**`, `src/Actions/Auth/**`, `src/Livewire/**` | [auth-login.md](auth-login.md) — screens in `packages/auth-ui`, mechanics in core; Livewire method order is not enforced, so every granting method re-checks its own preconditions. |
| `src/**/Billing/**`, `src/Http/Controllers/Billing/**` | [billing-checkout.md](billing-checkout.md) — `subscribable_id` is the owner's primary key not `global_id`; wizard bypasses StartCheckoutRequest; stale stripe_status. |
| `bootstrap/**`, `src/Concerns/TagsSentryScopeWithTenant.php`, `database/migrations/**` | [exception-handling.md](exception-handling.md) — DomainException/ShowsMessageToUser split; TagsSentryScopeWithTenant fix tenant-less job-failure reports; failed_jobs trap. |
| `src/Models/**`, `composer.json` | [optional-dependencies.md](optional-dependencies.md) — eager `implements`/`use trait` vs lazy type-hints; `Support\Compat\*`; one `class_exists` seam per package; absence only testable in a subprocess. |
| `packages/**`, `composer.json` | [package-split.md](package-split.md) — one shared view namespace; scanning tests go vacuous not red; constants autoload; satellite config writes can truncate a core namespace. |
| `src/Support/**`, `packages/**` | [package-boundaries.md](package-boundaries.md) — **the seam map**: what to contribute through (routes/features/migrations/seeders/permissions/panels) and the boundary facts that still bite. |
| `bootstrap/**`, `src/NumerosisServiceProvider.php` | [package-host-bootstrap.md](package-host-bootstrap.md) — `Domains.php` can't call facades; host `bootstrap/app.php`/`providers.php` staleness; `HostConfig::apply()` register-vs-booting race; `Numerosis::middleware()` fatal on a real (non-Testbench) boot. |
| `config/tenancy.php`, `src/**Tenancy**` | [stancl-tenancy-v4.md](stancl-tenancy-v4.md) — **port map, not current code**: v3-only since 2026-08-31; 11 moved symbols (9 eager) + 4 moved config keys; v4 docs wrong on 3 points. |
| `phpstan.neon`, `phpstan-baseline.neon`, `composer.json` | [static-analysis.md](static-analysis.md) — level 9, one config + baseline; warm result cache hides errors (compare cold-vs-cold); green doesn't survive `composer install`. |
| `src/Support/Cache/**` | [tenant-caching.md](tenant-caching.md) — `global_cache()` un-prefixed; cross-tenant leaks of cached tenant models + shared session guard keys. |
| `src/Actions/Tenancy/**`, `src/Jobs/**` | [tenant-provisioning.md](tenant-provisioning.md) — races between sync checkout redirect + async Stripe webhook; idempotency requirements; JobPipeline vs AsAction calling-convention conflict. |
| `packages/onboarding/**` | [tenant-registration-wizard.md](tenant-registration-wizard.md) — bare `@livewire()` view flattens child's component boundary; spatie wizard's `wizardClassName` needs alias resolution, not raw FQCN. |
| `tests/**` | [testing.md](testing.md) — no Sail (`vendor/bin/pest`); Playwright now required for *every* run; central rows escape RefreshDatabase; make a repaired assertion fail. |
| `config/filesystems.php`, `src/**` | [tenant-filesystem.md](tenant-filesystem.md) — `local` disk root tenant-suffixed but Livewire upload route never tenant-identified; dedicated `livewire` disk fixes it. |
| `src/Resolvers/**`, `config/tenancy.php` | [identification-modes.md](identification-modes.md) — subdomain/custom-domain/path; slug ≠ domain; `{tenant}` literal enables dots; all three modes now browser-tested. |
| `bootstrap/**`, `config/**` | [host-integration-quickstart.md](host-integration-quickstart.md) — 7 silent traps integrating into a pre-existing host app (tabellio); all 7 fixed 2026-08-13 — read for the mechanism to reach for now. |
| `**` (execution constraint) | [subagents.md](subagents.md) — execution constraint, not a codebase fact: never spawn sub-agents; the `.claude/agents/`+`.codex/agents/` that contradicted it were deleted 2026-09-01. |

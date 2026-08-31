# Codebase Rules Index

Gotcha/invariant/why-this-way/cross-file trap stuff, learned from codebase. Not design doc — readable from code? skip it here.

One line per file, under ~150 chars. Update index when rules file add/rename/remove.

**This copy is the only copy (decided 2026-08-31, superseding D11).** The old rule — saas-m and thin-app carry byte-identical copies, change here then re-copy — is retired. saas-m is archived; thin-app is a host app on a different toolchain (Sail, plain Laravel `TestCase`, its own `bootstrap/`), and `testing.md`, `static-analysis.md`, `package-split.md`, `package-boundaries.md` and `stancl-tenancy-v4.md` are now written against *this* repo's Testbench-without-Sail monorepo. A byte-identical copy would actively mislead there. **Copy individual rules into a host deliberately if it wants them; never sync the directory.** `host-integration-quickstart.md` is the file written for a host's point of view.

**Layout, since 2026-08-31:** one repo — core at the root, plus `packages/{ui,auth-ui,filament,onboarding}` (`nvade/numerosis-{ui,auth-ui,filament,onboarding}`), path-installed and published as read-only splits on tag. Rules describing moved code carry a header naming the package that holds it. `package-boundaries.md` is the seam map.

**Bare commit hashes in these rules refer to the archived saas-m repo** (`git@gitlab.com:nvade_/saas-m.git`), not numerosis — `c66cc72`, `ddd7c35`, `de06293`, `438f12f`, `549223e`, `6b8c78c` are all saas-m's. Hashes for this package or thin-app always name their repo.

<!-- topic-index:start -->
- [auth-guards.md](auth-guards.md) — guard follows tenancy; `web` = central; tenant guard outside tenancy reads central `users`; don't bind models.
- [auth-login.md](auth-login.md) — screens in `packages/auth-ui`, mechanics in core; Livewire method order is not enforced, so every granting method re-checks its own preconditions.
- [billing-checkout.md](billing-checkout.md) — `subscribable_id` is the owner's primary key not `global_id`; wizard bypasses StartCheckoutRequest; stale stripe_status.
- [exception-handling.md](exception-handling.md) — DomainException/ShowsMessageToUser split; TagsSentryScopeWithTenant fix tenant-less job-failure reports; failed_jobs trap.
- [filament-tenancy.md](filament-tenancy.md) — Filament tenancy + stancl's share no state; `{tenant}` param null in tests, not a routing bug; a browser test does *not* escape `runningInConsole()`.
- [optional-dependencies.md](optional-dependencies.md) — eager `implements`/`use trait` vs lazy type-hints; `Support\Compat\*`; one `class_exists` seam per package; absence only testable in a subprocess.
- [module-marketplace.md](module-marketplace.md) — system in core, UI in `packages/filament`, permanently; stale registry on long-running workers; `$tenant->run()` has no try/finally.
- [package-split.md](package-split.md) — one shared view namespace; scanning tests go vacuous not red; constants autoload; satellite config writes can truncate a core namespace.
- [package-boundaries.md](package-boundaries.md) — **the seam map**: what to contribute through (routes/features/migrations/seeders/permissions/panels) and the boundary facts that still bite.
- [package-host-bootstrap.md](package-host-bootstrap.md) — `Domains.php` can't call facades; host `bootstrap/app.php`/`providers.php` staleness; `HostConfig::apply()` register-vs-booting race truncating `tenancy.database`.
- [stancl-tenancy-v4.md](stancl-tenancy-v4.md) — **port map, not current code**: v3-only since 2026-08-31; 11 moved symbols (9 eager) + 4 moved config keys; v4 docs wrong on 3 points.
- [static-analysis.md](static-analysis.md) — level 9, one config + baseline; warm result cache hides errors (compare cold-vs-cold); green doesn't survive `composer install`.
- [tenant-caching.md](tenant-caching.md) — `global_cache()` un-prefixed; cross-tenant leaks of cached tenant models + shared session guard keys.
- [tenant-provisioning.md](tenant-provisioning.md) — races between sync checkout redirect + async Stripe webhook; idempotency requirements; JobPipeline vs AsAction calling-convention conflict.
- [tenant-registration-wizard.md](tenant-registration-wizard.md) — bare `@livewire()` view flattens child's component boundary; spatie wizard's `wizardClassName` needs alias resolution, not raw FQCN.
- [testing.md](testing.md) — no Sail (`vendor/bin/pest`); Playwright now required for *every* run; central rows escape RefreshDatabase; make a repaired assertion fail.
- [tenant-filesystem.md](tenant-filesystem.md) — `local` disk root tenant-suffixed but Livewire upload route never tenant-identified; dedicated `livewire` disk fixes it.
- [identification-modes.md](identification-modes.md) — subdomain/custom-domain/path; slug ≠ domain; `{tenant}` literal enables dots; path round trip now browser-tested.
- [host-integration-quickstart.md](host-integration-quickstart.md) — 7 silent traps integrating into a pre-existing host app (tabellio); all 7 fixed 2026-08-13 — read for the mechanism to reach for now.
- [subagents.md](subagents.md) — execution constraint, not a codebase fact: never spawn sub-agents; ask the user to split a task instead.
<!-- topic-index:end -->
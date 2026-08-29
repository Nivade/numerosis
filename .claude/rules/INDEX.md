# Codebase Rules Index

Gotcha/invariant/why-this-way/cross-file trap stuff, learned from codebase. Not design doc — readable from code? skip it here.

One line per file, under ~150 chars. Update index when rules file add/rename/remove.

**This copy is canonical (D11).** saas-m and thin-app carry byte-identical copies that must not be edited in place — change a rule here, then re-copy.

**Bare commit hashes in these rules refer to the archived saas-m repo** (`git@gitlab.com:nvade_/saas-m.git`), not numerosis — `c66cc72`, `ddd7c35`, `de06293`, `438f12f`, `549223e`, `6b8c78c` are all saas-m's. Hashes for this package or thin-app always name their repo.

<!-- topic-index:start -->
- [auth-guards.md](auth-guards.md) — guard follows tenancy; `web` = central; tenant guard outside tenancy reads central `users`; don't bind models.
- [auth-login.md](auth-login.md) — two passwordless-login components, two domains, protections drift between; Livewire method order not enforced.
- [billing-checkout.md](billing-checkout.md) — Livewire wizard bypass StartCheckoutRequest; stale local stripe_status; retired plans stay purchasable.
- [exception-handling.md](exception-handling.md) — DomainException/ShowsMessageToUser split; TagsSentryScopeWithTenant fix tenant-less job-failure reports; failed_jobs trap.
- [filament-tenancy.md](filament-tenancy.md) — Filament tenancy + stancl's share no state; `{tenant}` route param null in tests, not routing bug.
- [optional-dependencies.md](optional-dependencies.md) — `implements`/`use trait` resolve eagerly (unlike method type-hints); `Support\Compat\*` pattern to make one genuinely optional.
- [module-marketplace.md](module-marketplace.md) — stale module registry on long-running queue workers; `$tenant->run()` no try/finally, manage tenancy manually for webhook-triggered code.
- [package-host-bootstrap.md](package-host-bootstrap.md) — `Domains.php` can't call facades; host `bootstrap/app.php`/`providers.php` staleness; `HostConfig::apply()` register-vs-booting race truncating `tenancy.database`.
- [static-analysis.md](static-analysis.md) — PHPStan level 9 over src/config/database/tests/workbench; baseline size before trusting green run.
- [tenant-caching.md](tenant-caching.md) — `global_cache()` un-prefixed; cross-tenant leaks of cached tenant models + shared session guard keys.
- [tenant-provisioning.md](tenant-provisioning.md) — races between sync checkout redirect + async Stripe webhook; idempotency requirements; JobPipeline vs AsAction calling-convention conflict.
- [tenant-registration-wizard.md](tenant-registration-wizard.md) — bare `@livewire()` view flattens child's component boundary; spatie wizard's `wizardClassName` needs alias resolution, not raw FQCN.
- [testing.md](testing.md) — central-connection rows escape RefreshDatabase; tenant DB leaks; providers register before getEnvironmentSetUp; suite-slow/known-failure baseline.
- [tenant-filesystem.md](tenant-filesystem.md) — `local` disk root tenant-suffixed but Livewire upload route never tenant-identified; dedicated `livewire` disk fixes it.
- [host-integration-quickstart.md](host-integration-quickstart.md) — 7 silent traps integrating into a pre-existing host app (tabellio); all 7 fixed 2026-08-13 — read for the mechanism to reach for now.
<!-- topic-index:end -->
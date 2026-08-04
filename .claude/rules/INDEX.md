# Codebase Rules Index

Gotcha/invariant/why-this-way/cross-file trap stuff, learned from codebase. Not design doc — readable from code? skip it here.

One line per file, under ~150 chars. Update index when rules file add/rename/remove.

<!-- topic-index:start -->
- [auth-guards.md](auth-guards.md) — default guard follow tenancy context; `web` central guard; why models must not container-bound.
- [auth-login.md](auth-login.md) — two passwordless-login components, two domains, protections drift between; Livewire method order not enforced.
- [billing-checkout.md](billing-checkout.md) — Livewire wizard bypass StartCheckoutRequest; stale local stripe_status; retired plans stay purchasable.
- [exception-handling.md](exception-handling.md) — DomainException/ShowsMessageToUser split; TagsSentryScopeWithTenant fix tenant-less job-failure reports; failed_jobs trap.
- [filament-tenancy.md](filament-tenancy.md) — Filament tenancy + stancl's share no state; `{tenant}` route param null in tests, not routing bug.
- [module-marketplace.md](module-marketplace.md) — stale module registry on long-running queue workers; `$tenant->run()` no try/finally, manage tenancy manually for webhook-triggered code.
- [static-analysis.md](static-analysis.md) — PHPStan level 9 over app/ + tests/; run red on master, baseline own errors before trust them.
- [tenant-caching.md](tenant-caching.md) — `global_cache()` un-prefixed; cross-tenant leaks of cached tenant models + shared session guard keys.
- [tenant-provisioning.md](tenant-provisioning.md) — races between sync checkout redirect + async Stripe webhook; idempotency requirements; JobPipeline vs AsAction calling-convention conflict.
- [testing.md](testing.md) — central-connection rows escape RefreshDatabase; tenant DB leaks; why suite slow; known-failure baseline.
- [tenant-filesystem.md](tenant-filesystem.md) — `local` disk root tenant-suffixed but Livewire upload route never tenant-identified; dedicated `livewire` disk fixes it.
<!-- topic-index:end -->
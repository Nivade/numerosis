# Changelog

All notable changes to `numerosis` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions before `0.1.0` were never tagged — every host up to and including
this release consumed the package via a `dev-main` path repo, so there is no
prior tag to diff against. See `UPGRADING.md` for the one breaking change
that predates this tag and still matters to a host updating from an
untagged checkout.

## [Unreleased]

## [0.2.0] - 2026-09-18

The SaaS readiness review and its eleven-phase remediation
(`.claude/plans/archive/saas-readiness-review-remediation.md`). Four security holes
closed, the derived billing reads cached, domain rechecks given a failure
streak to reason from, and three user-facing surfaces put behind a feature
class. Read the Changed section before updating: middleware aliases and five
`Data\Api` class names moved, and the tenant two-factor requirement now gates
a host's own product routes.

### Added

- `Features\Api\ReadApiFeature` gates the read-only `/api/v1` surface and the
  token screen that mints keys for it. **Off by default**, so a host relying
  on either must add it to `numerosis.features`.
  `Features\Notifications\NotificationCenterFeature` and
  `NotificationPreferencesFeature` gate the header bell and the
  `settings/notifications` channel matrix, both on by default.
- `/api/v1/domains`, read-only, over the same `GetServableDomains` query the
  panel uses and behind a token ability. The foundations table promised it
  and nothing served it.
- `numerosis.health.scheduler_stale_after_seconds` (default 300). A scheduler
  heartbeat older than that fails `HealthReport::healthy()`; a heartbeat never
  written reads as unknown, so a deployment running no scheduler still reports
  healthy.
- `domains.failing_since`, written by the same `forceFill()` that already
  stamped `last_checked_at` and `status`, with
  `numerosis.domains.recheck_backoff_period_hours` (12) and
  `recheck_backoff_cap_minutes` (1440) driving the new backoff.
- `sessions.global_user_id` and its index.
- `numerosis.cache.ttl.entitlements` (300 seconds).
- `numerosis:prune-sessions` on the schedule, behind
  `SCHEDULE_PRUNE_SESSIONS`. Database sessions became the preferred driver
  and nothing was removing the dead rows.
- `tenancy:backup --chunk=`, which the signature had always claimed, wired to
  the restore batch size.
- `Contracts\Billing\SeatPolicy`, bound to `SeatLimitPlanPolicy`, splitting
  seat counting from plan eligibility. The capability constants moved onto
  `Contracts\Billing\Entitlements`: reading `PlanEntitlements::SEATS` through
  a contract hint handed a host that swapped the implementation the concrete
  class anyway.
- `ImpersonationSession` in `numerosis.models`, so it and `Membership` resolve
  through `Numerosis::model()` at their seven bare `::query()` call sites.

### Changed

- Middleware aliases `impersonation` and `entitlement` are now
  `numerosis.impersonation` and `numerosis.entitlement`. **Breaking for a host
  route naming either**, including the parameterized form, which reads
  `numerosis.entitlement:custom-branding`.
- `TenancyTwoFactor` moved onto the `tenant` middleware group. **A host's own
  product routes are now gated by the tenant's two-factor requirement**, which
  is what the switch always claimed; before, it only gated routes this package
  registers. The team screen and the switch itself opt out, so an owner who
  let the grace period lapse can still reach what turned it on. It redirects
  to a central route, so it cannot loop the way the subscription gate would.
- The five `Data\Api\*Resource` classes are `*Data`. They are
  `spatie/laravel-data` objects, not Laravel API Resources, and read as the
  wrong thing.
- `GetBillingPeriod` returns `null` where Stripe has stamped no period,
  instead of synthesizing an anniversary window from `created_at` and
  reporting usage against it. Callers say no period is recorded.
- The derived entitlement scalars are a tenant-scoped cached read through
  `CacheTtl`, invalidated on subscription change, rather than a per-request
  memo recomputed on every request. Scalars only, never the models they came
  from.
- Plan metadata is validated at boot, the way `ConfiguredSteps` is, with the
  install doctor as a second caller of the same check. A typo used to stay
  silent until somebody ran the doctor.
- The give-up window for a failing domain is measured from `failing_since`
  rather than `created_at`, so a domain that served for months and then lost
  its DNS is no longer marked `Failed` on the first bad check. A
  never-verified claim behaves exactly as before. Recheck intervals grow with
  how long the failure has lasted, and cap.
- The closure screen shows its detail to Admins as well as Owners. Both roles
  are redirected to it while a tenant is closed; only the Owner reopens.
- `AnonymizeUser` walks the tenant connections too, nulling the causer morph
  and scrubbing the known identity keys out of activity properties. The
  entries stay: an audit trail with holes in it is not an audit trail.
- The staff activity filter offers the acting person alongside the actor
  class.
- `MembershipPolicy::manageSecurity()` is gone; it was `manageClosure()`
  spelled twice. `UpdateTwoFactorRequirementRequest::ability()` follows.
- Four actions lost the static entrypoints they carried beside `handle()`,
  and `RecordTenantMigrationLeg` its four static aliases; all five call sites
  pass an explicit `MigrationRunStatus`.
- `GetTenantSeatUsage` counts members and asks `Entitlements` for the cap,
  instead of walking to plan metadata through three optionals and answering
  differently when the two disagreed.
- `domains.created_at` and `updated_at` carry microseconds, and
  `Models\Central\Domain` writes them with matching precision.
  `Tenant::primaryDomain()` returns the most recently added domain, which two
  domains added in the same second could not decide before — the answer came
  back in whatever order the driver chose.
- `Permission::$ability` and `Permission::$context` are accessors splitting
  `name` rather than generated columns, and
  `2025_12_17_035929_add_ability_and_context_virtual_columns_to_permissions`
  is deleted. **Breaking for a host that queries either column**, since they
  no longer exist in the database and cannot appear in a `where`, `orderBy`
  or `groupBy`. Reading them off a model is unchanged, and they now resolve
  on the central connection too, where they had always been null.

### Security

- Impersonation links are signed. The token was already a 128-character
  single-use secret with a 60-second window, but a leaked URL was enough on
  its own. The signature is built by hand over the tenant's own `baseUrl()`,
  since the link is minted on the central domain and spent on the tenant's.
- The session registry reads through the indexed `sessions.global_user_id`
  stamp instead of unserializing every live row. `global_user_id` is the one
  identity the central and tenant guards share, since `user_id` holds
  whichever guard was ambient. The payload check stays the authority for what
  a read returns, and rows written before the stamp landed stay in the scan
  until they expire.
- A tenant archive that will not open now throws, and the job records the
  failure, rather than silently dropping a whole workspace from a subject
  access request.
- Two middleware hardcoded `route('settings.two-factor')` where
  `numerosis.routes.names` exists precisely so a feature-gated name is not a
  literal, and that one is gated behind Fortify's own check, so the literal
  threw exactly when the feature was off.

### Fixed

- `/health` fails on a stale scheduler heartbeat. The age was published and
  never reached the status code, so a host whose cron had stopped, which means
  provisioning had stopped, still got a 200.
- The tenant layout used by the registration wizard renders the impersonation
  banner. Only that layout was missing it, so nobody had been impersonated
  without one yet; the test enumerates the layouts from disk, so the next
  layout cannot reopen the gap.

### Removed

- `HostConfig` no longer forces `Password::min(8)`. The hardening plan asked
  only for `uncompromised()`, which stays; a minimum nobody specified is the
  package deciding something the host owns. **A host relying on the implicit
  8-character floor must set its own `Password::defaults()`.**
- `telescope/*` is out of the security-header exclusions and the CSRF
  exceptions. This package does not depend on Telescope; a host running it
  adds its own paths.
- Filament (both panels, `packages/filament`, `filament/filament` itself),
  the module system and its marketplace (`internachi/modular`,
  `Models\Central\ModuleOffering`, the three `tenants:*-module` commands),
  and tenant-user impersonation. 0.1.0 shipped all three; none is coming
  back, and `tests/Feature/PackageBoundariesTest.php` fails on a `Filament\`
  symbol anywhere in the tree.

## [0.1.0] - 2026-08-28

First tagged release. Extracted from the `saas-m` monolith
(`.claude/plans/archive/package-extraction.md`) and hardened against a second real
consumer, `tabellio` (`.ai/rules/host-integration-quickstart.md`).
Baseline for future entries in this file — not a reconstructed history of
every change since extraction began; consult `git log` for that detail.

Notable since extraction:

- Multi-tenant foundation (stancl/tenancy) with a shared auth guard,
  Filament admin + tenant panels, Cashier billing, module marketplace,
  passwordless login, and an opt-in feature-flag system
  (`config('numerosis.features')`).
- `HostConfig::apply()` normalizes tenancy/auth/filesystem/cache config for
  a host, with a narrowed `verify*()` check per normalization surfaced
  through `numerosis:install --check`.
- `docs/host-requirements.md` documents every config key `HostConfig`
  touches; `tests/Feature/Docs/HostRequirementsTest.php` fails the suite if
  a key is added there without a matching doc row.
- `Nvade\Numerosis\Testing\CleansUpTenancyDatabases` ships the
  central-write/tenant-database test teardown a host needs under
  `RefreshDatabase`, handling the `beforeApplicationDestroyed()` ordering
  difference between plain Laravel and Testbench internally.
- `Numerosis::routes(withAuth: false)` lets a host keep the package's
  billing/tenancy/checkout routes while running its own auth system,
  without a routes/auth.php name collision.

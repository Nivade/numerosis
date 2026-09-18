# Upgrading

## 0.1.0 → 0.2.0

Run `php artisan migrate` first: this release adds `domains.failing_since`
and `sessions.global_user_id` (with an index). The session registry falls
back to the payload scan for rows written before the stamp, so no session is
dropped, but the fast path only starts once the column exists.

**Re-publish or hand-merge `config/numerosis.php`.** New keys: `health`,
`domains.recheck_backoff_period_hours`, `domains.recheck_backoff_cap_minutes`,
`cache.ttl.entitlements`, `schedule.prune_sessions`,
`routes.names.two_factor_settings`, the `ImpersonationSession` model binding
and the `SeatPolicy` binding. The three new feature classes
(`NotificationCenterFeature`, `NotificationPreferencesFeature`,
`ReadApiFeature`) are listed there too; the first two are on, `ReadApiFeature`
is commented out.

Then check each of these against your host:

- **`/api/v1` and the API-token screen are off unless you opt in.** Add
  `Nvade\Numerosis\Features\Api\ReadApiFeature::class` to
  `numerosis.features` if you serve either.
- **Middleware aliases are prefixed.** `impersonation` → `numerosis.impersonation`,
  `entitlement:<capability>` → `numerosis.entitlement:<capability>`. A route
  naming the old alias fails when that route is matched.
- **Tenant two-factor now gates your routes too.** `TenancyTwoFactor` sits on
  the `tenant` middleware group, so every route in it, including your own
  product routes, redirects an unenrolled member to the central enrolment
  screen while the tenant requires a second factor. This is what the switch
  always claimed to do. If a route of yours must stay reachable, exclude it
  the way the team screen and the two-factor switch do.
- **`Password::min(8)` is no longer forced.** `uncompromised()` stays. Set
  your own length policy in `Password::defaults()` if you were relying on the
  package's.
- **`telescope/*` is no longer excluded** from the security headers or the
  CSRF exceptions. Add the paths yourself if you run Telescope.
- **Renames.** The five `Data\Api\*Resource` classes are `*Data`
  (`MemberData`, `InvitationData`, `SubscriptionData`, and so on). The
  entitlement capability constants moved from `PlanEntitlements` onto
  `Contracts\Billing\Entitlements`. `MembershipPolicy::manageSecurity()` is
  gone in favour of `manageClosure()`. Four actions and
  `RecordTenantMigrationLeg` dropped their static entrypoints; call
  `handle()`/`run()`.
- **`GetBillingPeriod` can return `null`** where Stripe stamped no period,
  instead of inventing an anniversary window from `created_at`. Callers must
  handle the null.

## Untagged checkout → 0.1.0

**If your host still has `config/numerosis-billing.php` and/or
`config/numerosis-tenancy.php` published, delete both and re-publish
`numerosis-config`.**

Both files were removed in `numerosis@59f026f` (decision D13,
`.claude/plans/archive/package-extraction.md`) — their contents moved into
`config/numerosis.php` under nested `'billing'` and `'tenancy'` keys. The
package's own `mergeConfigFrom()` for those two files was removed at the
same time, so a host holding a published copy of either loses its
customisations **silently**: nothing errors, the package falls back to its
own defaults under `numerosis.billing.*`/`numerosis.tenancy.*`, and the
orphaned file is simply never read again.

```bash
rm -f config/numerosis-billing.php config/numerosis-tenancy.php
php artisan vendor:publish --tag=numerosis-config --force
```

Then re-apply whatever customisations those two files held, into the
corresponding nested key of `config/numerosis.php`.

If your published `config/numerosis.php` predates this change too (i.e. it
has no top-level `'billing'`/`'tenancy'` keys at all), diff it against the
package's current `config/numerosis.php` and merge by hand — `--force`
overwrites the whole file.

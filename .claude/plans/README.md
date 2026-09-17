# Plans — historical, not authoritative

**Nothing in this directory describes how the package works today.** These are
session plans, kept for the reasoning and the incident history in them. A plan
records what someone intended at a point in time; several were superseded,
abandoned, or executed differently from what they say.

For current behaviour read, in this order:

1. `README.md` and `docs/` — architecture, features, extending, host requirements
2. `.ai/rules/` — traps and invariants, indexed in `index.md`
3. the code

If a plan and a `docs/` file disagree, the `docs/` file wins. If a plan and the
code disagree, the code wins.

## Layout

| Directory | Meaning |
|---|---|
| this directory (loose files) | **Live.** Not executed, or partially executed and still worth finishing |
| `archive/` | Executed. Kept for the reasoning and incident history only |
| `abandoned/` | Not executed, and no longer worth executing. The diagnosis in them may still be good; the plan of action is not |

Re-sorted 2026-09-04, validating every file against the tree rather than
against its own Status line. 37 files moved to `archive/`, 1 to `abandoned/`,
4 left live. Re-checked 2026-09-07: `config-consolidation.md` was in fact
executed — `config/` holds one `numerosis.php` and no `config/numerosis/`
directory exists — so it moved to `archive/`, and
`simplification-followups.md` joined it once its eight phases landed.

Re-audited 2026-09-12 the same way (tree, not Status lines): the table below
had gone stale — `domain-events-expansion.md` and `invitations-social-redesign.md`
were both marked "Not executed" but `src/Actions/Tenancy/EnsureTenantUserExists.php`
and `src/Events/Tenancy/MemberJoined.php` both exist, and the redesign's own
HANDOFF file said complete. All three moved to `archive/`, along with
`comment-destyle.md` (its own Status line said done), `drifting-puzzling-flame.md`
(said executed, commit hashes verified in `git log`), the superseded draft it
replaced (`delightful-doodling-turing.md`, added in the same commit, never
executed on its own), and `effervescent-questing-pumpkin.md` (its target
commit `888e31f` is in history under the same subject line). Only two plans
were left live because their own text — not just a Status header — said
something remained open.

`contract-seam-audit.md` moved to `archive/` 2026-09-12: all eleven phases
executed, then audited against the code, which turned up one live defect the
execution had introduced (phase 9's billable narrowing) and per-phase Done
notes missing from six sections. Both fixed in the same pass; the suite ran
710 passed / 6 skipped and `composer analyse` cold-clean.

`pr-review-remediation.md` moved to `archive/` 2026-09-14: every phase
executed, including the comment-budget sweep, which took the repo from 56
over-budget docblocks and 35 over-cap `//` runs to zero of each. Suite 734
passed / 6 skipped, `composer analyse` cold-clean, PHPStan baseline unchanged
at 17.

`team-members-management.md` moved to `archive/` 2026-09-16, the day it was
written: all five phases plus the session-survival middleware its risk section
recommended. Two shape changes, both in the plan's "What shipped": the screen
is a page view rather than a `Livewire\Tenant\Team\Members` class, and
self-removal is exempt from the last-admin refusal.

`seat-limit-at-invite.md` moved to `archive/` 2026-09-16, the day it was
written: all five phases executed. Its phase 1 shape changed on contact — the
counter is an action rather than a `Services/` class, and the invite and accept
checks count differently. Both recorded in the plan's own "What shipped".

`post-extraction-review.md` moved to `archive/` 2026-09-14: its last two open
items, 4.2 and 4.4, were re-validated as shipped, and 6.2 — the second-consumer
smoke test, never built — moved to `.claude/findings.md` rather than keeping a
563-line plan live for one line of work.

`findings-cleanup.md` moved to `archive/` 2026-09-14: phases 1, 2 and 4
executed, phase 3's flake did not reproduce in 30 consecutive `composer test`
runs and kept a rewritten findings line. Phase 4's scratch-host run produced a
`docs/host-requirements.md` diff (five fresh-app defaults that break the
documented install) and one boot bug, both recorded rather than fixed here.

`enum-vocabulary-sweep.md` moved to `archive/` 2026-09-12: all eight phases
executed on `refactor/enum-vocabulary-sweep`, `composer test` green (721
passed / 6 skipped) and `composer analyse` clean at every phase boundary.

`audit-remediation-eighty-commits.md` moved to `archive/` 2026-09-15: seven
tiers executed on `fix/audit-remediation-eighty-commits`, one commit per tier
and tier 7 alone. Five items were deliberately not done and three were already
fixed or stale; both lists, with evidence, are at the bottom of the plan. D5
could not be honoured literally — restoring `array<string, mixed>` on an
override of a vendor method typed bare `array` trades one PHPStan error for a
contravariance error — so the baseline rows went instead via
`array<array-key, mixed>`. Suite 779 passed / 6 skipped, `composer analyse`
clean.

`module-depth-audit.md` moved to `archive/` 2026-09-15: all seven items
executed on `refactor/module-depth-audit`, one commit each plus two extras —
a stale `Billing::resolve*Using()` claim in `config/numerosis.php` the first
item exposed, and item 5 and 6 landing separately. Two deviations, both noted
in their commits: `CheckoutContext` is a plain readonly class rather than a
`spatie/laravel-data` object, matching its two neighbours in
`Data/Billing/Checkout/`; and moving the freshness assert to the charge choke
point reorders one error in the saved-payment-method branch. Suite 790 passed
/ 6 skipped, `composer analyse` clean.

`cache-audit.md` moved to `archive/` 2026-09-14: all ten phases executed on
`feat/cache-audit`. Phase 9's open decision was settled both ways — `null` is
cached for `tenantPrimaryDomain()`, deliberately not for `FindUserByGlobalId`
— and recorded in `.ai/rules/tenant-caching.md`. Phase 4 turned up a second
defect the plan had not predicted: stancl's `PathTenantResolver` keys its cache
on the `Route` it was handed while its invalidator keys on the tenant id, so
enabling the cache alone would have cached entries nothing could forget. Suite
767 passed / 6 skipped, `composer analyse` clean.

`sql-driver-compatibility.md` moved to `archive/` 2026-09-16: all six phases
executed on `feat/sql-driver-compatibility`, option 2 (PostgreSQL at
production parity, SQLite for development). Verified on all three drivers —
MySQL 815 passed, PostgreSQL 803 passed, SQLite 802 passed — each with an
end-to-end `tenancy:provision` run across a real queue worker. Two things the
plan did not predict: the clone helper's PostgreSQL path collided with the
database `CreateTenantDatabase` had already made, since
`CREATE DATABASE ... WITH TEMPLATE` is the creation rather than a fill; and
SQLite cannot take `--parallel`, so it runs serially in CI. The
case-sensitivity sweep the plan expected on PostgreSQL found nothing.

`tenant-ownership-transfer.md` moved to `archive/` 2026-09-16, the day it was
written: all five phases executed on `feat/tenant-ownership-transfer`. Its
billing decision turned out to be moot and unimplementable at once — the
Stripe customer is the tenant, not the departing user, and Stripe will not
move a subscription between customers anyway — so what ships is a re-sync of
the customer's name and email. Password confirmation is a `current_password`
field rather than the `password.confirm.if-set` middleware, which throws in
path mode on a `{tenant}`-prefixed route, and phase 5 is a console command
because `staff-admin-panel.md` has not been executed. All four recorded in the
plan's own "What shipped".

`tenant-close-and-recovery.md` moved to `archive/` 2026-09-16, the day it was
written: owner-initiated closure with a 30-day recovery window, subscription
cancelled at period end. Phase 5 is two console commands rather than a staff
view, for the same reason `tenant-ownership-transfer.md`'s was — there is no
staff panel yet. Purging a closed tenant stays behind
`numerosis.tenancy.closure.purge_closed`, off, until backups exist, and that
key's `grace_days` sibling is now what `--days` defaults to. Deviations in the
plan's own "What shipped".

`staff-admin-panel.md` moved to `archive/` 2026-09-16, the day it was written:
five Livewire screens behind `StaffPanelFeature`, off by default, on the
central domain under `numerosis.routes.staff_prefix`. Impersonation stayed out
— it belongs to `support-impersonation.md`, which rebuilds what Phase 2 of the
six-package collapse deleted. Deviations in the plan's own "What shipped".

`support-impersonation.md` moved to `archive/` 2026-09-16, the day it was
written: stancl's own `UserImpersonation` behind a feature flag, with an
`impersonation_sessions` audit row, a banner, a 60-minute cap and mail
suppression around it. The link is unsigned on purpose — a 128-character
single-use token with its own TTL, because signing would need a URL built for
another host. Deviations in the plan's own "What shipped".

`provisioning-observability.md` moved to `archive/` 2026-09-16, the day it was
written: an unauthenticated health document behind `HealthEndpointFeature`
(off by default, counts and booleans only), a provision detail timeline and a
queue screen in the staff panel, and an operator alert behind
`Contracts\Notifications\OperatorRecipient`. A step that exhausts its retries
now records `StepOutcome::Failed` with its attempt count, which is what the
screens read. Deviations in the plan's own "What shipped".

`session-management.md` moved to `archive/` 2026-09-16, the day it was
written: a `SessionRegistry` seam with a database implementation, the
`settings/sessions` screen, `AuthenticateSession` on the tenant group and every
authenticated central route, and revocation on password change, 2FA disable and
`MemberRemoved`. The registry matches on the session payload rather than the
`user_id` column, which holds whichever guard was ambient when the row was
written. Deviations in the plan's own "What shipped".

`two-factor-authentication.md` moved to `archive/` 2026-09-17, the day after it
was written: Fortify's two-factor feature on with `confirm` and
`confirmPassword`, a `settings/two-factor` Livewire screen, a per-tenant
requirement with a grace period, an unconditional requirement for the staff
screens, and a staff clear path that writes an activity-log entry naming both
people. Enrolment is central-only, against the plan's own recommendation —
every login checks credentials on the central provider, so a secret on a tenant
user would never be challenged. Deviations in the plan's own "What shipped".

## Live

Twenty-one files added 2026-09-16 from a capability sweep of the tree:
`saas-readiness-roadmap.md` is the parent and holds the sequencing, the
cross-cutting decisions and the shared foundations. The other twenty are one
feature each and are listed here in the roadmap's wave order. Ten,
`seat-limit-at-invite.md`, `team-members-management.md`,
`tenant-ownership-transfer.md`, `tenant-close-and-recovery.md`,
`staff-admin-panel.md`, `support-impersonation.md`,
`provisioning-observability.md`, `fleet-tenant-migrations.md`,
`session-management.md`, `two-factor-authentication.md` and
`security-hardening.md`, `audit-log-coverage.md`, `tenant-backup-restore.md`,
`gdpr-data-export.md`, `runtime-entitlements.md`, `usage-metering.md` and
`coupons-and-promotions.md`, have since been executed and archived; the rest are
not.

| Plan | State |
|---|---|
| `saas-readiness-roadmap.md` | Not executed. Parent of the twenty below; builds nothing itself |
| `custom-domain-verification.md` | Not executed. Wave 5. A documented mode with no ownership proof |
| `public-api-and-webhooks.md` | Not executed. Wave 5. Largest of the set; splits into three |
| `notification-center.md` | Not executed. Wave 5. The `notifications` table has no writer |

`security-hardening.md` moved to `archive/` 2026-09-17, the day it was
executed: `uncompromised()` on `Password::defaults()` behind a flag the test
suite turns off, a `SecurityHeaders` middleware appended to the `web` group
through a new `MiddlewareRegistrar::groupAppends()`, and a
`SuspiciousLoginDetected` event dispatched once per login lockout. Stripe's
webhook is exempt by path rather than by content type — Cashier answers it
with `text/html`. Deviations in the plan's own "What shipped".

`audit-log-coverage.md` moved to `archive/` 2026-09-17, the day it was
executed: a `Models\Activity` that picks its connection off the subject,
`logOnly()` lists on six central models, nine domain events logged by one
listener, screens behind `ActivityLogFeature`, and
`numerosis:prune-activity-log` for retention. Two things the plan did not
predict: `activity_log.subject_id` was an integer column, so nothing about a
tenant could be logged at all; and Spatie's own `activitylog:clean` deletes
through the model's default connection, which is never reliably the central
one. Deviations in the plan's own "What shipped".

`tenant-backup-restore.md` moved to `archive/` 2026-09-17, the day it was
executed: a per-driver `TenantDatabaseDumper`, `tenancy:backup`/`tenancy:restore`
with a clone path that rewrites the source tenant's id, encrypted artefacts,
a retention command, and the purge interlock that finally makes
`purge_closed` safe to turn on. One deliberate deviation: the default dumper
is PHP-native rather than `mysqldump`, because no dump binary exists on the
development host and a binary-only default would have shipped untested.
Deviations in the plan's own "What shipped".

`gdpr-data-export.md` moved to `archive/` 2026-09-17, the day it was executed:
a personal exporter wrapping the tenant one, a queued request with a signed
single-use link, anonymize-in-place erasure, consent records, staff-side
paths, and every retention window gathered into one documented table. Wave 3
is complete with it. Three traps it turned up: a tenant model read outside
`run()` has no connection, a new tenant migration needs the harness's template
databases dropped by hand, and seeding roles after the first central user
exists leaves `assignRole('admin')` throwing. Deviations in the plan's own
"What shipped".

`runtime-entitlements.md` moved to `archive/` 2026-09-17, the day it was
executed: `Contracts\Billing\Entitlements` over a central `tenant_usage`
counter, a route middleware, an `@entitled` directive, the install doctor's
plan-metadata check, and the staff-side usage panel. Its phase 5 question was
settled as the plan recommended — a downgrade is allowed and blocks the next
addition. Two deviations: the free tier ships with no limits (a seat cap there
broke invitations for every tenant before its first checkout), and
`DefaultUnpaidTenantQuota` stayed where it was, since it counts tenants per
user and the counter is keyed on a tenant. Deviations in the plan's own
"What shipped".

`usage-metering.md` moved to `archive/` 2026-09-17, the day it was executed:
all six phases, over the counter `runtime-entitlements.md` built. Three shape
changes, all in the plan's own "What shipped" — the meter columns live on
`subscription_items` rather than `subscriptions`, `SubscriptionDualWriter` is a
test name and not a class, and the Stripe meter-event identifier derives from
the counter's cumulative total rather than from the period alone, which is what
lets a retry be a no-op without freezing usage after the first report.

`coupons-and-promotions.md` moved to `archive/` 2026-09-17, the day it was
executed: all six phases. Stripe keeps owning the arithmetic — the package
applies, validates with one message per refusal, records redemptions in
`applied_promotions` and displays what Stripe returns. Three notes in its own
"What shipped": the code rides on the reservation and is re-validated before the
charge, a code that stops validating is dropped rather than refusing the sale,
and the plan's swap-preserves-discount test is asserted as "no local write sends
`discounts`" because the offline Stripe fake has no invoice endpoints.

## Abandoned

| Plan | Why |
|---|---|
| `parallel-test-isolation.md` | `--parallel` deadlocked across two sessions and was dropped from the suite entirely. The root-cause diagnosis is preserved in `.ai/rules/testing.md`; the plan's direction (keep `--parallel`, delete most of the parallel bootstrap) is dead |

## Notes on the archive

Nothing in `archive/` is actionable. Two things there are worth knowing about:

- **`plan-audit-unexecuted.md`** is a snapshot of 2026-08-10 and was wrong by
  2026-08-11 (it calls `checkout-region-localization.md` unexecuted; it had
  been executed). It is archived as a record of the audit method, not as a
  status list.
- **`package-extraction-log.md`** is an append-only session log, not a plan.
  It is the only record of the bug classes that extraction turned up.
- **`delightful-doodling-turing.md`** is a superseded draft, not an executed
  plan — it was never run on its own. It and `drifting-puzzling-flame.md`
  ("cleaned up") were added in the same commit; the latter is the one whose
  phases actually landed.

Several archived plans describe subsystems that have since been **deleted**
(the Filament panels, the module marketplace, `torann/geoip`, the six-package
split, the chat module). They were executed as written and then removed by a
later plan — `humming-nibbling-flame.md` did most of the removing. Executed
does not mean still present.

Filenames generated from a random word list (`agile-bubbling-lake.md`,
`federated-wandering-wozniak.md`, …) say nothing about their contents — open
the file's first lines for its subject and Status.

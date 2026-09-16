# SaaS readiness roadmap

**Status: not executed. Written 2026-09-16 as the parent of twenty feature
plans, each a loose file in this directory.** This file holds the sequencing,
the decisions that apply across more than one of them, and the shared
foundations that must not be built twice. It builds nothing on its own.

## What this came from

A capability sweep of the tree on 2026-09-16, mapped across five areas: auth
and security, billing, tenant lifecycle, teams and authorization, and
platform operations. The package is strong where it has been worked —
provisioning, checkout, identification modes, four SQL drivers, 179 test
files — and the gaps cluster in three places nobody has needed yet: anything a
*team* does after the first user, anything *staff* do to run the platform,
and anything a *buyer* audits before signing.

Twenty gaps were kept. Each has its own plan file. The ranking below is by
what blocks running a real product, not by effort.

## The twenty, by wave

Waves are dependency order, not priority order. Everything in a wave can run
in parallel; a later wave has at least one plan that needs an earlier one.

### Wave 1 — the team surface

| # | Plan | Why first |
|---|---|---|
| 6 | `seat-limit-at-invite.md` | A live defect, not a feature. Smallest file in the set |
| 1 | `team-members-management.md` (executed, archived) | No HTTP surface exists for `Membership` at all |
| 2 | `tenant-ownership-transfer.md` (executed, archived) | An owner cannot delete their own account today |
| 13 | `tenant-close-and-recovery.md` | Needs ownership transfer to exist first |

These four touch the same files — `Membership`, `MembershipRole`,
`SendInvitation`, `AcceptInvitation`, `DeleteUserForm` — and splitting them
across sessions means three rounds of the same context.

### Wave 2 — running the platform

| # | Plan | Why here |
|---|---|---|
| 3 | `staff-admin-panel.md` | The shell every other staff-facing screen lands in |
| 4 | `support-impersonation.md` | Its entry point is a button on the panel's tenant detail |
| 16 | `provisioning-observability.md` | Its screens are panel screens |
| 12 | `fleet-tenant-migrations.md` | Console-only, but its status output is a panel screen |

### Wave 3 — security and compliance

| # | Plan |
|---|---|
| 7 | `two-factor-authentication.md` |
| 8 | `session-management.md` |
| 11 | `security-hardening.md` |
| 10 | `audit-log-coverage.md` |
| 14 | `tenant-backup-restore.md` |
| 9 | `gdpr-data-export.md` |

`gdpr-data-export.md` consumes the export machinery from
`tenant-backup-restore.md` and the log coverage from `audit-log-coverage.md`.
The other four are independent.

### Wave 4 — money

| # | Plan |
|---|---|
| 5 | `runtime-entitlements.md` |
| 18 | `usage-metering.md` |
| 17 | `coupons-and-promotions.md` |

`runtime-entitlements.md` builds the tenant-scoped counter service.
`usage-metering.md` is its second reader. Building metering first means
building that counter twice.

### Wave 5 — platform surface

| # | Plan |
|---|---|
| 15 | `custom-domain-verification.md` |
| 19 | `public-api-and-webhooks.md` |
| 20 | `notification-center.md` |

## Decisions already settled

Settled 2026-09-16 with the repo owner. A plan that contradicts one of these
is wrong, not an alternative.

| Decision | Ruling |
|---|---|
| Staff panel: Filament or Livewire | **Livewire + Flux.** `filament/filament` is not coming back. Five screens never repay a dependency every host inherits, and `tests/Feature/PackageBoundariesTest.php` fails on any `Filament\` symbol |
| Staff panel default state | **Off.** `StaffPanelFeature` is commented out in `config('numerosis.features')`, matching `OneTimePasswordFeature` |
| Metering columns | **Finish, do not drop.** `meter_id` and `meter_event_name` stay and get a pipeline |
| Custom domain mode | **Fix.** Ownership proof gets built. It is not being marked unsupported |
| TLS for custom domains | **The package issues no certificates.** Caddy on-demand is the shipped default via an ask endpoint, Traefik is supported via an HTTP-provider endpoint, and a `DomainVerified` event covers Cloudflare for SaaS |
| Impersonation scope | Central `admin` role only, short-TTL signed token, every session in the activity log, persistent banner, writes allowed |
| Tenant close | Owner-initiated, 30-day recovery window, subscription cancels at period end |
| 2FA enforcement | Per-user opt-in, per-tenant "require for all members" toggle, mandatory for central `admin` |

## Shared foundations — build once

Four things more than one plan needs. Each is owned by exactly one plan and
consumed by the rest.

| Foundation | Owned by | Consumed by |
|---|---|---|
| Tenant-scoped counter service (quota reads and meter reads over one counter) | `runtime-entitlements.md` | `usage-metering.md`, `seat-limit-at-invite.md` |
| `Membership` HTTP surface and its policy | `team-members-management.md` | `tenant-ownership-transfer.md`, `staff-admin-panel.md` |
| Staff panel shell: layout, guard, permission gate, navigation | `staff-admin-panel.md` | `support-impersonation.md`, `provisioning-observability.md`, `fleet-tenant-migrations.md` |
| Verified-domain query, one source for every TLS presenter | `custom-domain-verification.md` | nothing yet; `public-api-and-webhooks.md` exposes it read-only |
| Tenant data serializer (per-tenant dump and restore) | `tenant-backup-restore.md` | `gdpr-data-export.md`, `tenant-close-and-recovery.md` |

## Constraints every plan inherits

- **New user-facing capability goes behind a `Feature` class** in
  `config('numerosis.features')`. A feature class named in config but absent
  from disk is a hard container failure at boot, so packaging and config move
  in the same commit.
- **Breaking changes are free.** There are no existing installs. A migration
  that drops or reshapes a column needs no upgrade path, and
  `UPGRADING.md` records it rather than a compatibility shim.
- **Two connections, never mixed.** Central models under
  `src/Models/Central/`, tenant models under `src/Models/Tenant/`. A
  `CentralConnection` model bound on a tenant route has no tenant scope —
  scope it in the policy.
- **Every change is tested.** `composer test` is the parallel run; SQLite runs
  serially. `composer lint` once before committing, since Pint alone never
  runs Rector.
- **Actions use `handle()`**, contracts mirror services flatly, DTOs are
  `spatie/laravel-data`.

## What this roadmap does not cover

Recorded so the next reader does not think they were missed: tenant settings
screen (rename, branding, locale), per-user timezone and locale, onboarding
checklist and lifecycle email drip, per-tenant scheduled tasks, revenue
reporting (MRR, churn, cohort), Horizon, full-text search, a media library.
Each is real, none blocks operating the product, and several become cheap once
the notification centre and the staff panel exist.

# Phase 12 — Closing out

**Status: executed 2026-09-18.** Read `README.md` in this directory first. Runs
after every other phase.

The briefs directory was archived with the plan rather than deleted: 12.1 left
that choice open, and the anchors and per-phase scope are the record of what
each commit was answering.

No code changes. Documentation, plan hygiene and recorded rules.

## 12.1 — Move the plan and update the README

- Move `.claude/plans/saas-readiness-review-remediation.md` into
  `.claude/plans/archive/`.
- Move this whole `remediation-briefs/` directory in with it, or delete it —
  decide when you get there, but do not leave it beside live plans reading as
  work still to do.
- Update `.claude/plans/README.md`'s Live table **in the same pass**. Its absence
  is exactly what lets that table drift; see
  `.ai/rules/plans.md` and the memory note on the two hand-copied status copies.

`tests/Feature/PlanStatusLineTest.php` enforces a status marker on loose plans at
depth 0 only, so briefs in a subdirectory are exempt. The README audit is not
automated — do it by hand, by counting the files.

## 12.2 — Update each executed feature plan's "What shipped"

Where this remediation changed the answer:

| Plan | What to record |
|---|---|
| `support-impersonation.md` | D1 settled as "sign it". The deviation note claiming signing was impractical is wrong now — the signature is built by hand over the tenant's `baseUrl()`, since `route()` cannot build a foreign-host URL in path mode |
| `two-factor-authentication.md` | The fourth deviation is closed. The gate is on the `tenant` middleware group, so a host's own routes are covered; the team screen and the requirement switch opt out |
| `session-management.md` | The full-table scan is gone. Sessions carry `global_user_id`, stamped by `GlobalIdSessionHandler`, and the payload check stays the authority. `session:prune` is scheduled (phase 4) |
| `custom-domain-verification.md` | Three changes: `verified_at`/`verification_failed_at` stay deleted and the activity log is the history; the give-up window measures from the failing streak; rechecks back off |
| `staff-admin-panel.md` | `entitlementUsage`, `appliedPromotions` and `recentActivity` accepted as additions to the documented detail surface |
| `gdpr-data-export.md` | Causer anonymization walks both connections and scrubs `properties`; a tenant archive that will not open fails the export |
| `runtime-entitlements.md` | The scalars are cached through `CacheTtl` with invalidation on subscription change; plan metadata validates at boot |
| `notification-center.md` | D2 settled as the drop: no `digest` column, no `digestible()`. The operator notification routes through preferences, which diverges from the remediation plan's stated default |
| `public-api-and-webhooks.md` | The domains resource exists; the API is behind a feature class, off by default |
| `security-hardening.md` | D4 settled as the drop: no `Password::min(8)`, `uncompromised()` only |

Only touch a plan whose phase actually landed. If a phase was skipped, say so
there rather than writing what it would have said.

## 12.3 — Record what the review taught

Use `record-rule` (Boost MCP), not a native memory or notes tool. Three rules:

1. **The contract-hint versus implementation-constant trap** (from 2.1). Glob:
   `src/Contracts/**`, `src/Services/**`. A class that type-hints an interface
   and then reads a constant off the concrete implementation hands a host that
   swapped the binding the concrete class anyway, and nothing goes red.
2. **The `fail()` shadowing hazard** (from the already-closed S24). Glob:
   `src/Jobs/**`. A helper named `fail()` on a class using `Queueable` shadows
   `InteractsWithQueue::fail(?Throwable)`, which is a live hazard rather than a
   style point.
3. **The group-versus-route-group seam** (from phase 1's 1.2). Glob:
   `src/Boot/MiddlewareRegistrar.php`, `routes/**`. A gate that redirects to a
   *central* route can sit on the `tenant` group; one that redirects to a tenant
   route loops and cannot. Gating only the routes this package registers leaves
   every host route ungated, and no test in this repo sees that.

**`record-rule` drops every note from `.ai/rules/index.md` on each call.** Diff
`index.md` afterwards and restore its notes from git;
`tests/Feature/RulesIndexTest.php` fails if you forget. Expect to do this once
per call, not once at the end.

Parallel sessions also write `.ai/rules/` — an unexpected diff there is a
teammate, not a bug. Merge, never revert.

## Commit

```
docs(plans): close out the readiness remediation

Every executed phase recorded against the feature plan it changed, the
remediation plan archived, and the Live table in the plans README
re-derived by counting the files rather than by editing the last copy.

Three rules recorded: the contract-hint-versus-implementation-constant
trap, the fail() shadowing hazard on a Queueable job, and which middleware
gates can sit on the tenant group without looping.
```

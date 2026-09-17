# Phase 3 — Custom domain verification

**Status: not executed.** Anchors verified against `e411bed`. Read
`README.md` in this directory first. Closes P26 and P20; P25 is already closed
and must not be reopened.

Rules to read: `.ai/rules/identification-modes.md`,
`.ai/rules/events-listeners-observers.md`.

## P25 is closed — do not restore the columns

The plan's 3.1 asked that `verified_at` stop being nulled on a transient
failure. `verified_at` and `verification_failed_at` **no longer exist**: the
simplify branch deleted both, on the grounds that each was a pure function of
`status`. The table carries `status`, `verification_token` and `last_checked_at`
(`database/migrations/central/2026_09_17_140000_add_verification_to_domains_table.php`,
`src/Models/Central/Domain.php:30`), and the history now lives in the activity
log through the audited `DomainVerified` / `DomainRevoked` events.

**Decision, already made: leave it deleted.** Do not add a first-verified
timestamp back. Record the closure in phase 12, not here.

## 3.2 — Measure the give-up window from the failing streak (P26)

`src/Actions/Tenancy/Domains/RecordDomainVerification.php:51` — `statusFor()`
measures the window from `$domain->created_at` against
`numerosis.tenancy.custom_domains.verification_window_hours`. A domain verified
months ago that loses DNS is therefore marked `Failed` on its first bad check,
against the plan's rule that a failed check means "not yet", not "no".

The table has nothing to measure a streak from. `last_checked_at` and `status`
are both overwritten on every check, so neither can say when the current run of
failures began. **Both 3.2 and 3.3 need that, and one column carries it:**

- New migration on the central connection adding `failing_since` to `domains` —
  nullable timestamp, set on the transition into failing, cleared on any
  successful check. **Decided: one column, not a failure counter beside it.**
  Elapsed failing time is what both the window and the backoff want, and it is
  the more useful thing to show a human.
- `RecordDomainVerification::handle()` maintains it in the `forceFill()` that
  already stamps `status` and `last_checked_at` (`:33-36`). One write, not two.
- `statusFor()` (`:51`) measures the window from `failing_since` when set and
  from `created_at` otherwise, so a never-verified claim behaves exactly as it
  does today.

**A once-verified domain is still marked `Failed` eventually** — 72 hours after
its DNS broke rather than on the first bad check. The `DomainRevoked` event
keeps firing the moment it stops being servable, unchanged. Do not make
`Failed` unreachable for a domain that once served; that was considered and
rejected, because it would need a durable first-verified marker and P25 settled
against reintroducing one.

## 3.3 — Back off between rechecks (P20)

`src/Console/Commands/VerifyDomains.php:73` uses one flat
`numerosis.tenancy.custom_domains.recheck_minutes` (default 60,
`config/numerosis.php:731`) for every domain.

Derive the interval from how long the domain has been failing, reading the base
from that same config key. **Decided:** `base * 2 ** floor(hours_failing / 12)`,
capped at 24 hours, with `failing_since` null meaning the base interval. Add the
cap and the doubling period as sibling config keys with defaults, not as
literals in the command.

`Domain::dueForCheck()` (`src/Models/Central/Domain.php:92`) is where the
interval is applied; it orders by `last_checked_at` today and must compare
against the derived interval rather than a flat one.

## Tests

`tests/Feature/Tenancy/CustomDomainVerificationTest.php` is the home for all
three. It already holds `test_a_claim_past_its_window_is_marked_failed` and
`test_the_token_survives_a_failed_check_and_changes_only_on_request`.

Add:
- A domain active for longer than the window that fails one check is **not**
  `Failed`; the streak starts at that check.
- The same domain crossing the window measured from `failing_since` **is**
  `Failed`.
- One successful check clears `failing_since`.
- The recheck interval grows with the elapsed failing time and stops at the cap.

Prove each: write it, revert the fix, watch it fail, restore.

## Stop conditions

- If `verified_at` exists in the tree, the branch state is not what this brief
  was written against. Stop and report.
- Do not rename `DomainStatus` cases or touch the event names; the audit
  listener matches on them.

## Commit

```
fix(domains): a failed check means not yet, and rechecks back off

The give-up window was measured from created_at, so a domain that had
served for months and then lost its DNS was marked Failed on the first bad
check. It measures from the start of the current failing streak now, which
leaves a never-verified claim behaving exactly as before.

Rechecks used one flat interval whatever the history, so a domain nobody
was ever going to fix was polled at the same rate as one mid-setup. The
interval grows with how long the failure has lasted, and caps.

Both needed a streak the table did not record. last_checked_at and status
are overwritten on every check, so neither could say when the current run
of failures began; domains carry failing_since, written by the same
forceFill() that already stamped the other two.

Closes P26 and P20.
```

# Phase 10 — Duplication and dead weight

**Status: not executed.** Anchors verified against `e411bed`. Read
`README.md` in this directory first.

**Run this after phases 2 through 9.** It touches files every one of them edits.

**Nothing here changes behaviour. No test expectation may move.** If one does,
the refactor changed behaviour and is wrong — revert and report.

Rules to read: `.ai/rules/enums.md` (the Cashier no-cast trap),
`.ai/rules/architecture-conventions.md`.

## Already closed — verify, then skip

| Plan item | State | Evidence |
|---|---|---|
| 10.1 / S5 — six hand-written membership lookups | **Closed** | All six read through `Membership::roleFor()` or `FindMembershipForUser::run()`. `MembershipPolicy::manages():144`, `::transferOwnership():93`, `OwnershipNominationPolicy::delete():30`, `TransferTenantOwnershipCommand:50`, `TenantOwnerRequest::target():49` |
| 10.2 / S6 — duplicated delinquency probe | **Closed** | `Subscription::withUnpaidInvoice()` (`:88-91`) is the one definition; `AssertTenantClosable:30` and `AssertOwnershipTransferable:38` both call the scope |
| S31 — unused `$payload` | **Closed** | `DatabaseSessionRegistry::forget()` reads one row by key; `forgetOthers()` destructures and uses what it takes |

If any of those is not as described, stop and report.

## 10.3 — The seeder callback and the orphan (S7)

- `src/database/seeders/RoleAndPermissionSeeder.php:33-36` — `array_map` over
  `Permission::defaultActions()`
- `src/database/seeders/Tenant/PermissionAndRoleSeeder.php:35-37` — same shape,
  over `Permission::additionalActions()`
- `src/Models/Permission.php:94` — `actionsFor()` exists and has **no production
  caller**; the two seeders re-implement what it does.

**Decision, already made: call `actionsFor()` from both seeders.** It is the
seam the rules describe (`additionalActions()` returns `[]` and exists as the
host's extension point), so deleting it would remove the seam and keep the
duplication. If the two seeders genuinely need different shapes, that is a
finding — stop and report rather than forcing it.

## 10.4 — `DeleteTenants` (S10)

`src/Console/Commands/DeleteTenants.php`:

- `isProtected()` (`:62-77`) emits `$this->warn()` from a predicate.
- `report()` (`:79-82`) returns `FAILURE` for a merely skipped tenant.
- `:28-30` uses a ternary as a statement.

Separate the decision from the output. Return `SUCCESS` when nothing failed.
Make the ternary an `if`.

## 10.5 — Repeated switches (S11, S22)

1. `src/Actions/Tenancy/RecordTenantMigrationLeg.php:24-57` — `handle()` builds
   attributes from an if-chain over `MigrationRunStatus` (cases at
   `Enums/.../MigrationRunStatus.php:16-20`). `StepOutcome::finishesStep()`
   (`:29-32`) shows the enum-method alternative. Push it onto the enum.
2. `src/Listeners/Audit/RecordDomainEventActivity.php` — the plan claims **two**
   parallel `match (true)` blocks plus an `AUDITED_EVENTS` constant. Verified:
   there is **one** match, in `describe()` (`:60-146`), and **no**
   `AUDITED_EVENTS` constant in that file. Confirm before acting: if there is
   only one match and one place listing the events, replace it with a table keyed
   by event class and note in the commit that the plan's "two parallel blocks"
   was stale. If a second listing exists elsewhere (check
   `NumerosisServiceProvider`), both move together.
3. `src/Services/Tenancy/PortableTenantDatabaseDumper.php` — `driver()` switches
   at `:39`, `:57`, `:143`, `:150-165` and `:197`. Group into one driver
   strategy.

## 10.6 — The migration command's scratch state (S12, S13)

- `src/Actions/Tenancy/RecordTenantMigrationLeg.php` — four static aliases:
  `started()` (`:59`), `succeeded()` (`:67`), `failed()` (`:72`), `skipped()`
  (`:77`). Delete them and call `handle()`/`run()` with the status.
- `src/Actions/Queries/GetPendingTenantMigrations.php:50-58` — `paths()` **is**
  called, from `src/Console/Commands/MigrateTenants.php:107`. The plan calls it
  an entrypoint nothing needs; that is stale. **Leave `paths()` alone.**
- `src/Console/Commands/MigrateTenants.php` — `$runId` (`:37`), `$failures`
  (`:40`), `$migrated` (`:42`), `$skipped` (`:44`) are temporary fields with an
  explicit reset block at `:51-53`. Carry them in a small result object instead
  of on the command, and the reset block goes with them.

## 10.7 — The small ones (S25, S26)

- `src/Policies/MembershipPolicy.php` — `manageSecurity()` (`:105-108`) returns
  `manageClosure()` (`:97-102`) verbatim. Inline it unless the two are about to
  diverge. They are not.
- `src/Livewire/Settings/Sessions.php:40` and `:74` call
  `resolve(SessionRegistry::class)` inline, while `RevokeOtherSessions` (`:21-24`)
  and `EndSessionsForRemovedMember` (`:20`) constructor-inject the same contract.
  Pick injection. A Livewire component cannot take constructor injection the way
  an action does — use `boot()` or a `#[Locked]`-free property resolved once,
  whichever matches how other components in this tree do it. If none do, leave
  `resolve()` and report why.
  `AnonymizeUser` no longer calls it at all; that half is closed.

## Tests

Run `composer test` before and after. The two runs must be identical. Write no
new tests: this phase adds no behaviour, and a new test here is a claim that
something changed.

## Commit

```
refactor: collapse what the twenty features duplicated

No behaviour changes; the suite is identical either side.

Both permission seeders re-implemented Permission::actionsFor(), leaving
the real seam with no caller. They call it.

DeleteTenants warned from inside a predicate, reported FAILURE for a
tenant it had merely skipped, and used a ternary as a statement. The
decision, the output and the exit code are three things now.

Three repeated switches became one each: the migration leg's if-chain
moved onto MigrationRunStatus, the domain audit listener reads a table
keyed by event class, and the portable dumper's five driver checks share
one strategy.

RecordTenantMigrationLeg's four static aliases are gone, and MigrateTenants
carries its counters in a result object rather than in scratch fields it
had to reset at the top of handle().

MembershipPolicy::manageSecurity() was manageClosure() spelled twice, and
the sessions screen resolved a contract inline that its two siblings
inject.

Closes S7, S10, S11, S22, S12, S13, S25 and S26. S5, S6 and S31 verified
already closed.
```

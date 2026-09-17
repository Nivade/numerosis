# Remediating the SaaS readiness review

> **Status: partly executed. Do not execute this file.**
>
> Phase 1 is done (`e411bed`). Phases 2 through 12 are executed from
> `.claude/plans/remediation-briefs/` — one brief per phase, anchors re-verified
> against HEAD, every judgement call answered. **Start at that directory's
> `README.md`**, which carries the kickoff prompt and the progress table.
>
> This file stays as the record of *why* each finding was raised. Its file:line
> references predate `refactor/saas-readiness-simplify` and point at code that
> has moved, and its D1–D4 decisions are settled (D1 sign, D2 drop, D3 three
> feature classes, D4 **drop** `Password::min(8)` — note 9.1 below assumes the
> opposite). Where this file and a brief disagree, the brief is right.

**Originally written 2026-09-17 after a
two-axis review of `5d3a2cc...HEAD`, the twenty feature plans of
`saas-readiness-roadmap.md`.** Sixty-eight findings, all of them carried here:
forty-two on the standards axis, twenty-six on the spec axis. Twelve phases,
one commit each. The coverage table at the end maps every finding to its
phase, and nothing is closed without appearing there.

A separate four-angle review of the same range landed on
`refactor/saas-readiness-simplify` the same day and closed, half-closed or
contradicted twenty of these findings. Read "Reconciliation" below before
starting any phase.

## What this came from

`/code-review` against the fixed point `5d3a2cc` (the merge of the plans
branch), 474 files and +30431/-1589 lines. Two axes ran separately: does the
code follow the repo's documented standards, and does it implement what the
plan asked for. Six findings were spot-checked against the tree before this
plan was written; all six held.

Findings carry IDs. `S*` is a standards finding, `P*` a spec finding. They are
grouped here by what breaks if the fix is wrong, not by which agent found
them or which wave they came from.

## Reconciliation with `refactor/saas-readiness-simplify`

Two commits on that branch — `fix(api): authorize every read endpoint against
the caller` and `refactor(saas-readiness): collapse the seams twenty features
grew apart` — came out of a reuse/simplification/efficiency/altitude review of
the same range. It found none of the four security findings in phase 1 and
nothing in phases 3, 6, 8 or 9, so most of this plan stands. Where it did
touch a finding, check the tree before doing the phase item: the line numbers
quoted throughout were taken before that branch.

| Finding | State | What is left |
|---|---|---|
| S5 | Closed | `Membership::roleFor()`; the two policies, the transfer command and the nomination path all read through it |
| S6 | Closed | `Subscription::withUnpaidInvoice()`, three call sites. Deliberately **not** `SubscriptionStatus::isDelinquent()`, which also matches `IncompleteExpired` and would newly block transfers on a dead never-paid subscription |
| S23, S24 | Closed | `Jobs\Concerns\WritesDataExportOutcome`; the shadowing `fail()` is now `markFailed()` |
| S42 | Closed | Constructor is back at the top |
| P16 | Closed, wider than 5.1 asked | All three notifications route through preferences, the operator one included. If 5.1's default was right, `ProvisioningFailed` needs reverting rather than changing |
| S1, S2, S15 | Closed | The 25 over-cap docblocks are cut. 11.1 should re-run the rule's own `awk` check rather than trust the file list |
| S40 | Half closed | `GetServableDomains` calls `Domain::servable()`. The duplicated unsubscribe ternary in 5.4 is untouched |
| P15 | Half closed | `forget()` reads one row by key instead of scanning. `rowsFor()` still scans, so 1.3's indexed column is still the fix |
| S14 | Half closed | `GetTenantMembers` resolves through `Numerosis::model()`; the staff tenant screen still calls `Membership::query()` |
| S39 | Worse | `GetApiAbilities::only()` is gone, `::forUser()` is new, and `GetTenantMembersPage` was added. Eight static entrypoints now sit beside `handle()` |
| S38, S41 | Worse | Two more `Data\Api\*Resource` classes, `InvitationResource` and `UsageResource`. 7.4's rename grows by two |
| S8, P3 | Open, with evidence | See below |
| P17 | Contradicted | See below |
| P25 | Superseded | See below |
| P23, D2 | Decided by the branch | The column and `digestible()` are deleted, which is D2's second option. 5.3 is done unless D2 is re-opened the other way |
| S34 | Open | `SeatLimitPlanPolicy` was edited without moving the constant; 2.1 is unchanged |
| P21, D3 | Unaffected | A rule recorded in `optional-dependencies.md` argues against converting *request-time* booleans to feature classes. D3 is about three ungated surfaces, which is a different question and still open |

**S8 and P3, the seat limit — evidence against 2.2 as written.** Routing
`GetTenantSeatUsage` at `Entitlements::limit(SEATS)` was tried and reverted:
`PlanEntitlements` memoizes per tenant, and `SeatLimitTest`'s downgrade case
requires an accept to see a plan change written earlier in the same request.
Two definitions remain, which is the finding. Whatever 2.2 does has to keep
that test honest — either the memo learns to invalidate, or the seat count
keeps reading the plan directly and 2.2 closes the duplication somewhere else.
`limitFor()` now goes through `GetActiveSubscription`, so only the metadata
read is still duplicated.

**P17, the dead toggles — the branch took the other option.** 5.2 prefers
sending `MemberJoined` and `SecurityAlert`; the branch deleted both enum cases
and the vacuous `mailByDefault()`/`databaseByDefault()` with them. Deleting is
right if nothing is going to send them soon, since a toggle wired to nothing is
the defect either way. Sending is right if the roadmap actually promised those
two notifications. Check the roadmap row before restoring: if the cases come
back, they come back with senders in the same commit, which is what 5.2 says.

**P25, the verified-at nulling — superseded, and check this one.** 3.1 wants
`verified_at` to stop being nulled on a transient failure. The branch deleted
`verified_at` and `verification_failed_at` outright, on the grounds that both
were written as pure functions of `status` and that the nulling made
`verified_at` useless as a first-verified record anyway. The history is now in
the activity log, since `DomainVerified` and `DomainRevoked` became audited
events on the same branch. If a durable first-verified timestamp is wanted,
this is the wrong answer and the column should come back written once and never
nulled — which is what 3.1 originally described.

## Decisions needed before phases 1, 5, 7 and 9

**Decision needed — D1, the impersonation token.** The roadmap settled
"short-TTL **signed** token"; `routes/tenant.php:54` registers
`impersonate/{token}` with `throttle:10,1` and no `signed` middleware.
`support-impersonation.md` records the deviation and `README.md` defends it
("signing would need a URL built for another host"), but the roadmap says a
plan contradicting a settled decision "is wrong, not an alternative", and the
decision was never re-settled. Either sign the link and solve the foreign-host
URL problem, or amend the roadmap row to say unsigned single-use token and
record why. Phase 1 assumes the first; say so if it is the second.

**Decision needed — D2, the digest.** `notification_preferences.digest` and
`NotificationType::digestible()` shipped with no reader, which is the
half-schema the roadmap forbade for metering ("Finish, do not drop"). Build
phase 6 of `notification-center.md`, or drop the column and the method until
there is something to batch. Phase 5 assumes the drop.

**Decision needed — D3, feature gating.** The roadmap's inherited constraint
is that new user-facing capability goes behind a `Feature` class. `routes/api.php`
and the API-token screen, the notification centre and the preferences screen
all shipped unconditionally. Either three new feature classes (on by default
or off), or a recorded deviation saying the read API and the notification
centre are core surface. Phase 7 assumes three classes, off by default for
the API and on for the notification centre.

**Decision needed — D4, password minimum.** `HostConfig::passwordDefaults()`
sets `Password::min(8)->uncompromised()`; `security-hardening.md` asked only
for `uncompromised()`. Keep the minimum as package policy and record it in
`docs/host-requirements.md`, or drop it back to Laravel's default and leave
length to the host. Phase 9 assumes keep-and-record.

**Noticed, not doing.** The ~1100 lines of `resources/lang/vendor/filament-*`
deleted in the wave-2 diff (P5) were flagged as scope creep. They are correct
deletions under `PackageBoundariesTest` and re-adding them would be wrong. The
finding is recorded here and closed, not actioned.

## Phase 1 — Security correctness

The four findings where the shipped behaviour is a hole, not a blemish. One
commit, and it lands first because the rest of the plan touches the same
files.

1.1 **P6, the impersonation link.** Per D1, put `signed` on
`impersonate/{token}`. The foreign-host problem is real: the link is minted on
the central domain and redeemed on the tenant's, so `URL::signedRoute()` signs
against the wrong host. Build the signature over the tenant's `baseUrl()` with
`URL::forceRootUrl()` around the mint, and verify in
`RedeemImpersonationController` with `$request->hasValidSignature()` after the
tenant is identified. The existing 60-second TTL and single-use token stay;
signing is defence in depth over them, not a replacement.

1.2 **P8, the tenant 2FA requirement.** `MiddlewareRegistrar.php:70-71` keeps
`MiddlewareAlias::TenancyTwoFactor` off the `tenant` group on purpose, because
`EnsureTwoFactorEnrolled` redirects to a central route. The consequence is
that a tenant requiring 2FA still lets an unenrolled member reach the
dashboard and every product route; only `routes/tenant.php:87` is gated. The
group is the wrong seam for the reason the comment gives, so add the alias to
the authenticated tenant route group alongside `tenancy.membership`, which
solved the identical problem. `TenantTwoFactorRequirementTest` currently
asserts one route; assert the dashboard and a product route too.

1.3 **P15, the session scan.** `DatabaseSessionRegistry::rowsFor()` selects
every session row inside `session.lifetime` and unserializes each one, on
every screen render, every revoke, every `forgetOthers()` (which `AnonymizeUser`
calls) and every `forgetTenantAccess()`. Payload matching is a justified
deviation from matching `user_id`, since that column holds whichever guard was
ambient. Narrow the scan without giving it up: write the owning guard and
user into a dedicated indexed column on insert, filter on it, and keep the
payload check as the authority for what is returned. Add an index migration.

1.4 **S21, the swallowed archive failure.** `PersonalDataExporter::addTenantArchive()`
returns silently when `$inner->open($local) !== true`, dropping a whole
workspace from a subject access request with no trace. Under
`.ai/rules/exception-handling.md` this needs a `report()` at minimum; a
failed member archive should fail the export, since a partial SAR that looks
complete is worse than a failed one. Throw, let the job's catch record
`Failed`, and test the branch.

Tests: signed-link redemption and a tampered signature; an unenrolled member
blocked on the dashboard; the registry returning the same sessions after the
column lands, plus a query-count assertion; the archive failure surfacing.

## Phase 2 — Contracts and the seat counter

2.1 **S34, the constant off the implementation.** `SeatLimitPlanPolicy` hints
`Contracts\Billing\Entitlements` and then reads `PlanEntitlements::SEATS`, so
a host swapping `numerosis.billing.implementations[Entitlements]` still
inherits the concrete class. Move the capability constants onto the
`Entitlements` interface. Keep them strings: `.ai/rules/enums.md` records that
a core enum closes a host string-extension seam, and capabilities are exactly
such a seam.

2.2 **P3 and S8, two definitions of the seat limit.** The roadmap's shared
foundation says the tenant-scoped counter is built once and consumed by
`seat-limit-at-invite.md`. `hasSeatForNewInvitation()` reads `Entitlements`;
`hasSeatForNewMember()` reads the limit from `Entitlements` but then asks
`GetTenantSeatUsage`, which re-derives the cap itself through
`$subscription?->paymentPlan?->metadata()['options']['max_users']`, a message
chain over three optionals. Delete the re-derivation: `GetTenantSeatUsage`
counts members and asks `Entitlements` for the cap. The invite-versus-accept
difference in *what is counted* is deliberate and documented, and stays.

2.3 **S9, `PlanPolicy`'s two jobs.** The contract now answers plan eligibility
and seat availability. Judgement call: once 2.2 lands, the seat questions are
two thin reads over `Entitlements`. Either split
`Contracts\Billing\SeatPolicy` out, or record in the contract's docblock that
seats are an eligibility question. Prefer the split if the docblock needs more
than one line to justify itself.

2.4 **S14, model resolution.** New code uses bare `Membership::query()` and
`ImpersonationSession::query()` while `AnonymizeUser` and `PersonalDataExporter`
use `Numerosis::model(Membership::class)`. Neither model is in
`numerosis.models`, unlike the `TenantMigrationRun` added in the same wave.
Add both to the config map and route every call site through
`Numerosis::model()`.

## Phase 3 — Custom domain verification

3.1 **P25, the erased proof date.** `RecordDomainVerification::handle()` sets
`'verified_at' => $status->isServable() ? ($domain->verified_at ?? now()) : null`,
so one transient DNS failure erases the date the domain was originally proven.
`verified_at` is a fact about the past and must never be nulled; add a
separate `unverified_at` if the "not currently serving" state needs a
timestamp.

3.2 **P26, the give-up window.** `statusFor()` measures the window from
`$domain->created_at`, so a domain verified months ago that loses DNS is
marked `Failed` on its first bad check. The plan's rule is that a failed check
is "not yet", not "no". Measure from the start of the current failing streak:
`last_checked_at` when the previous status was already failing, `verified_at`
or `created_at` otherwise.

3.3 **P20, backoff.** `VerifyDomains.php:71` uses one flat `recheck_minutes`;
`custom-domain-verification.md` asked for a schedule with backoff. Derive the
interval from the consecutive-failure count with a cap, reading the base from
the same config key.

Tests: a verified domain surviving one failed check with its date intact; the
streak-based window; the interval growing and capping.

## Phase 4 — Privacy, audit and backup completeness

4.1 **P10, causer anonymization.** `gdpr-data-export.md` asks for "Anonymized
causer, entry retained". `AnonymizeUser` touches no `activity_log` row on
either connection, relying on the redacted central user resolving through the
relation. Tenant-database entries and any `properties` copies of name and
email survive. Walk both connections, null the causer morph, and scrub the
known name and email keys out of `properties`.

4.2 **P11, the actor filter.** The activity screen filters `properties->actor`,
which is the actor *class* (`user`, `staff`, `system`), not the acting person
that `audit-log-coverage.md` asked for. Keep the class filter, add a causer
filter beside it.

4.3 **P9, `--chunk=`.** `BackupTenantCommand` has `tenant`, `--all` and
`--disk`; the spec signature has `--chunk=`. Wire it through to the dumper's
row batching.

4.4 **P12, session pruning.** The package now prefers database sessions and
nothing prunes them; `session-management.md` names this as its own risk. Add
`session:prune` beside the four prunes already scheduled.

4.5 **S23 and S24, the two export jobs.** `GeneratePersonalDataExport` and
`GenerateTenantDataExport` share their whole shape: load the request, guard
the status, resolve the disk, try/catch into `Failed`, `update(Completed)`
with five identical keys, log, notify. One extracted a `fail()` helper and the
other inlined it twice. Extract the shared shape into a base job or a trait,
and rename the helper: `fail(DataExportRequest, string)` shadows
`InteractsWithQueue::fail(?Throwable)` from the `Queueable` trait the class
already uses, which is a live hazard, not a style point.

## Phase 5 — Notifications

5.1 **P16, the mail-only notifications.** `notification-center.md` phase 2
asks for the database channel "on every existing notification".
`InvitationNotification.php:31` and `PersonalDataExportReady.php:28` hard-return
`['mail']` and never consult `PreferredNotificationChannels`. A third,
`ProvisioningFailed.php:30`, does the same; it is operator-facing rather than
member-facing, so decide whether operator notifications are in scope and
record the answer in the plan's "What shipped". Default: route the two
member-facing ones through preferences, leave the operator one mail-only with
a one-line reason.

5.2 **P17, the dead toggles.** `Livewire\Settings\Notifications` renders a
switch for every `NotificationType` case, so `InvitationReceived` and
`DataExportReady` are toggles wired to nothing until 5.1 lands, and
`MemberJoined` and `SecurityAlert` have no sending class at all. Either send
them or stop rendering a switch for a type nothing emits. Prefer sending: both
events already exist.

5.3 **P23, the digest.** Per D2, delete the `digest` column and
`NotificationType::digestible()`, or build phase 6. Whichever way D2 goes, the
column and its reader move in the same commit.

5.4 **S40 and S42, duplication and a stray constructor.** The "Stop these
emails: {$unsubscribe}" ternary is verbatim in `PaymentConfirmed::toMail()`
and `TenantRestored::toMail()`; move it onto `TenantNotification`. The diff
also relocated `OwnershipNominationNotification::__construct()` below
`notificationType()` and `toDatabase()` for no reason; put it back at the top.

## Phase 6 — Entitlements, metering and billing reads

6.1 **P18, the uncached scalars.** `runtime-entitlements.md` phase 2 asks that
the derived scalars be cached "under a tenant-scoped key with the package's
`CacheTtl`". `PlanEntitlements` only memoizes within the request. Add the
cached read through `CacheTtl`, and mind `.ai/rules/tenant-caching.md`: cache
the scalars, never the models they came from, and invalidate on subscription
change.

6.2 **P19, boot-time plan metadata validation.** The same plan asks for
validation "at boot, the way `ConfiguredSteps` validates the step lists". It
landed as a manual `verifyPlanMetadata()` in the install doctor, so a typo
stays silent until somebody runs the command. Move the check to boot, keeping
the doctor as a second caller.

6.3 **P24, the invented billing period.** `GetBillingPeriod.php:68` synthesises
an anniversary period from `created_at` when Stripe stamped none; the spec
says report against the period "recorded locally". Return null and let callers
say "no period recorded", rather than reporting usage against a period that
does not exist.

6.4 **S36, `ReconcileUsage`.** The command holds divergence detection, Stripe
reads and alert throttling inline across `check()`, `stripeTotal()`,
`aggregatedValue()` and `reportDivergence()`. `.ai/rules/architecture-conventions.md`
puts logic in an action and leaves transport in the command; its siblings
`ReportUsage` and `VerifyDomains` already do. Extract an action and leave the
command thin.

6.5 **S37, the webhook's price walk.** `WebhookController::usageAmountOf()`
walks `$line['pricing']['price_details']['meter']` and `$line['plan']['usage_type']`
inside a controller while `Data\Billing\StripeSubscriptionData` exists for that
shape. Move the walk into the data object.

## Phase 7 — Feature gating and the API surface

7.1 **P21, the ungated surfaces.** Per D3, add feature classes for the read
API and its token screen, the notification centre and the preferences screen.
The roadmap's constraint is hard: packaging and config move in the same
commit, because a feature class named in config but absent from disk is a
container failure at boot.

7.2 **P22, the domains resource.** The roadmap's foundations table says
`public-api-and-webhooks.md` exposes the verified-domain query read-only.
Nothing under `/api/v1` does. Add the resource over the same
`GetServableDomains` query, scoped by token ability.

7.3 **S39, second entrypoints.** `GetServableDomains::includes()`,
`GetApiAbilities::ability()` and `::only()`, `GetTenantMeters::forPlan()` sit
as static publics beside `handle()`, against the actions convention. Fold each
into `handle()` or into the model, and note that S40's other half is here:
`Domain::servable()`'s scope is re-spelled inside
`GetServableDomains::servableQuery()`, so the action should call the scope.

7.4 **S38 and S41, naming.** `MiddlewareAlias` now mixes three conventions in
one enum: bare `entitlement`, prefixed `numerosis.api-token`, dotted
`tenancy.membership`. Pick one and migrate the new cases to it; the prefixing
argument that justifies `api-token` applies to `entitlement` equally.
`src/Data/Api/*Resource` breaks the tree's `*Data` suffix and reads as a
Laravel API Resource, which these are not; rename.

7.5 **S35, the route file header.** `routes/api.php:15-23` is a seven-line
header carrying two facts. One line, one fact.

## Phase 8 — Tenant lifecycle and the staff panel

8.1 **P1, the closure screen.** `tenant-close-and-recovery.md` says "Owner and
Admins can still reach the closure screen while closed".
`EnsureTenantSubscriptionActive` redirects every role to `tenant.closed`, and
`⚡closed.blade.php` gates the reopen on `isOwner` only, so Admins get the
notice and nothing else. Let Admins through to the screen with closure detail;
reopen stays owner-only unless the plan says otherwise.

8.2 **P2, the health endpoint.** Scheduler last-run age is published and never
reaches `HealthReport::healthy()`, so a stopped cron reports 200. Either fold
the age into `healthy()` behind a configurable threshold, or state in the
health document and in `provisioning-observability.md` that only the central
database decides the status code. Prefer folding it in: a stopped scheduler
means provisioning has stopped.

8.3 **P7, the missing banner.** `layouts/app/none.blade.php` renders no
`x-numerosis::impersonation.banner`, so a page on that layout impersonates
with no banner, against the roadmap's "persistent banner". Only the
registration wizard uses that layout today, so this is latent. Add it, and add
a test that every tenant layout renders the banner, so the next layout cannot
reintroduce the gap.

8.4 **P4, the panel's extra reads.** `staff-admin-panel.md`'s detail surface is
"memberships, domains, subscription, provision record"; `⚡tenant.blade.php`
adds `entitlementUsage()` and `appliedPromotions()` panels from wave-4 plans.
They are useful and already tested. Record them in the plan's "What shipped"
as an accepted addition rather than removing them, and close the finding that
way.

## Phase 9 — Hardening configuration

9.1 **P13, the password minimum.** Per D4, keep `Password::min(8)` and record
it in `docs/host-requirements.md` as package policy, or drop it.

9.2 **P14, the Telescope exclusion.** `numerosis.security.headers.except`
ships `telescope/*` for a package this repo does not depend on and whose
absence `.ai/rules/optional-dependencies.md` governs. Remove the entry; a host
that runs Telescope adds its own.

9.3 **S28, the repeated config reads.** `'numerosis.security.headers.'` is
re-concatenated at eight call sites in `SecurityHeaders`, and the
`Config::string('numerosis.privacy.disk', Config::string('numerosis.tenancy.backup.disk', 'local'))`
fallback is verbatim in three files. One private accessor for the first, one
shared resolver for the second.

9.4 **S29, the route-name literals.** `route('settings.two-factor')` is
hardcoded in both new middleware while `config/numerosis.php`'s `routes.names`
exists precisely so feature-gated names are not literals, and two-factor is
gated behind Fortify's feature check. Read it from config.

9.5 **S30, the unused hooks.** `SecurityHeaders` exposes five `protected` hook
methods with no subclass in the repo. Speculative generality: make them
private, or record the extension point in `docs/extending.md` if a host is
meant to subclass.

## Phase 10 — Duplication and dead weight

Judgement calls, all from the smell baseline. One commit; nothing here changes
behaviour, so the suite must be green without touching a test expectation.

10.1 **S5, the six hand-written membership lookups.** The diff adds
`Actions\Queries\FindMembershipForUser`, then hand-writes the same
`tenant_id + global_user_id` lookup in `MembershipPolicy::manages()`,
`MembershipPolicy::transferOwnership()`, `OwnershipNominationPolicy::delete()`,
`AcceptOwnershipNomination`, `TransferTenantOwnershipCommand` and
`NominateOwnerRequest::target()`. Route all six through the action.

10.2 **S6, the delinquency probe.** The `whereIn('stripe_status', [PastDue, Unpaid])->exists()`
check is byte-equivalent in `AssertTenantClosable` and
`AssertOwnershipTransferable`. Extract it, minding
`.ai/rules/enums.md`'s Cashier no-cast trap on `stripe_status`.

10.3 **S7, the seeder callback.** The spread callback is identical in
`RoleAndPermissionSeeder` and `Tenant\PermissionAndRoleSeeder` and
re-implements `Permission::actionsFor()`, which now has no production caller.
Call the method from both, or delete it if the seeders genuinely need a
different shape.

10.4 **S10, `DeleteTenants`.** `isProtected()` emits `$this->warn()` from a
predicate, `report(int $skipped): int` returns `FAILURE` for a merely skipped
tenant, and a ternary is used as a statement for `$skipped++ : $tenant->delete()`.
Separate the decision from the output, return `SUCCESS` when nothing failed,
and make the ternary an `if`.

10.5 **S11 and S22, the repeated switches.** `RecordTenantMigrationLeg::handle()`
builds attributes from an if-chain over `MigrationRunStatus` while
`StepOutcome::finishesStep()` in the same diff shows the enum-method
alternative. `Listeners\Audit\RecordDomainEventActivity` holds two parallel
`match (true)` over the same nine event types, so a tenth means editing both
plus `AUDITED_EVENTS`. `PortableTenantDatabaseDumper` switches on `driver()`
in four places. Push the first onto the enum, give the second one table keyed
by event class, and group the third into one driver strategy.

10.6 **S12 and S13, the migration command.** `RecordTenantMigrationLeg`'s four
static aliases and `GetPendingTenantMigrations::paths()` are entrypoints
nothing needs; `MigrateTenants` holds `$runId`, `$failures`, `$migrated` and
`$skipped` as temporary fields with an explicit reset block at the top of
`handle()`. Delete the aliases, and carry the counters in a small result
object instead of on the command.

10.7 **S25, S26, S31, the small ones.** `MembershipPolicy::manageSecurity()`
returns `manageClosure(...)` verbatim, so inline it unless the two are about
to diverge. `Livewire\Settings\Sessions` and `AnonymizeUser` call
`resolve(SessionRegistry::class)` inline while `EndSessionsForRemovedMember`
and `RevokeOtherSessions` constructor-inject the same contract; pick
injection. `DatabaseSessionRegistry::forget()` and `forgetOthers()` destructure
`[$row, $payload]` and never use `$payload`.

## Phase 11 — The comment sweep

`.ai/rules/general.md` is the whole standard here: default to zero comments,
five docblock prose lines and three `//` lines as hard caps with no exemption
for public seams, one fact per comment, no em-dash or "rather than" cadence,
and no citing `.ai/rules`, `.claude` or `docs/` outside `tests/`. Validate
preservation when shortening: each shortened comment must still carry its
fact.

11.1 **Over-long docblocks.** `Actions/Tenancy/MigrateTenant.php` is nine prose
lines carrying three facts and is the clearest breach in the diff. Then
`Actions/Admin/StartImpersonation.php`, `Actions/Queries/GetCurrentImpersonation.php`,
`Services/Billing/SeatLimitPlanPolicy.php`, `Actions/Auth/AnonymizeUser.php:30`,
`Services/Tenancy/PortableTenantDatabaseDumper.php:27`,
`Services/Tenancy/TenantDataExporter.php:28` and `Models/Activity.php:24`,
which narrates what `booted()` does.

11.2 **The `//` run.** `src/Testing/CleansUpTenancyDatabases.php` around line
302 replaced a four-line run with a five-line one.

11.3 **The cadence.** Thirty-three added comment lines in `src/` carry an em
dash or a `rather than` / `, not` contrast, both named in the rule's slop
table. Named examples: `GetCurrentImpersonation` ("the expiry guard — all of
which"), `DeleteUserAccount` ("A closed workspace is an exit, not a holding:",
which is the em-dash and colon-reveal patterns in one sentence),
`MembershipObserver::updated()`, the three `/** Global: … */` colon reveals in
`CacheKeys`, `ArtifactCipher`, `PersonalDataExporter` ("Copied entry by entry
rather than embedded whole"), and the new wave-5 comments ("Seats are rows,
not events", "Reads the raw Stripe objects rather than Cashier's wrappers",
"Named rather than left to be discovered").

11.4 **The dangling pointers.** `src/Livewire/Notifications/Center.php:24`
cites `.ai/rules/central-rows-on-tenant-routes.md`; only `tests/` is exempt.
`config/numerosis.php:705` and a runtime string in `InstallNumerosisCommand`
both point at `docs/host-requirements.md`, and `/docs` is `export-ignore`d, so
the pointer dangles for every Composer install. State the fact instead of
citing the file.

11.5 **The wrong comments.** `EnsureStaffTwoFactor` says the staff screens
"mint impersonation links", which was deleted 2026-09-03 and rebuilt
elsewhere. In `NumerosisServiceProvider`, `private const array AUDITED_EVENTS`
was inserted between `registerEventListeners()`'s docblock and the method, so
that docblock now documents the constant; and the `activitylog.clean_after_days`
comment sits above the `prune_data_exports` block, two `if`s from the command
it describes. `Domain::dueForCheck()`'s comment duplicates `VerifyDomains`'
class docblock word for word, and the `{tenant}`-prefix `UrlGenerationException`
explanation appears three times across `UpdateTwoFactorRequirementController`
and `UpdateTwoFactorRequirementRequest`. One fact, stated once, in the place
that owns it.

## Phase 12 — Closing out

12.1 Move this plan to `archive/` and update `.claude/plans/README.md` in the
same pass, which is the step whose absence lets the Live table drift.

12.2 Update each executed feature plan's "What shipped" where this remediation
changed the answer: `support-impersonation.md` (D1),
`two-factor-authentication.md` (the fourth deviation, now closed),
`session-management.md` (the scan), `custom-domain-verification.md` (three
fixes), `staff-admin-panel.md` (the accepted extra panels),
`gdpr-data-export.md` (causer anonymization), `runtime-entitlements.md` (the
cache and boot validation), `notification-center.md` (the digest decision),
and `public-api-and-webhooks.md` (the domains resource).

12.3 Record what the review taught, with `record-rule`: the contract-hint
versus implementation-constant trap from 2.1, the `fail()` shadowing hazard
from 4.5, and the group-versus-route-group seam that 1.2 turns on. Diff
`index.md` afterwards and restore its notes, which `record-rule` drops on
every call.

## Running it

One commit per phase, on a branch per phase or one branch with twelve commits.
`composer test` at every phase boundary, `composer analyse` before the commit,
and `composer lint` once before committing so Rector's rewrites do not pile up
into somebody else's diff. Phase 11 is the one phase where `composer lint`
may produce a large unrelated diff; read `git status` and carry anything
unintended as a separate `refactor: apply rector` commit.

Phases 1 through 9 each need tests. Phases 10 and 11 must not change a single
test expectation; if one moves, the refactor changed behaviour and is wrong.

## Coverage

Every finding, and the phase that closes it.

| ID | Finding | Phase |
|---|---|---|
| S1, S15 | Over-long docblocks, eight files | 11.1 |
| S2 | Six-line docblocks on three impersonation and seat classes | 11.1 |
| S3 | Five-line `//` run in `CleansUpTenancyDatabases` | 11.2 |
| S4, S16 | Em-dash and contrast cadence, 33+ lines | 11.3 |
| S5 | Six hand-written membership lookups | 10.1 |
| S6 | Duplicated delinquency probe | 10.2 |
| S7 | Duplicated seeder callback, orphaned `actionsFor()` | 10.3 |
| S8 | `GetTenantSeatUsage::limitFor()` message chain | 2.2 |
| S9 | `PlanPolicy` answering two questions | 2.3 |
| S10 | `DeleteTenants` predicate, exit code, ternary | 10.4 |
| S11, S22 | Repeated switches, three sites | 10.5 |
| S12, S13 | Static aliases and temporary fields on the migration command | 10.6 |
| S14 | Bare `::query()` versus `Numerosis::model()` | 2.4 |
| S17, S32, S33 | `.ai/rules` and `docs/` pointers in shipped code | 11.4 |
| S18, S19, S20, S27 | Stale, orphaned, misplaced and triplicated comments | 11.5 |
| S21 | Swallowed archive failure | 1.4 |
| S23, S24 | Duplicated export jobs, `fail()` shadowing | 4.5 |
| S25, S26, S31 | Middle man, inline `resolve()`, unused `$payload` | 10.7 |
| S28 | Repeated config concatenation and disk fallback | 9.3 |
| S29 | Hardcoded `settings.two-factor` route name | 9.4 |
| S30 | Five unused `protected` hooks | 9.5 |
| S34 | `PlanEntitlements::SEATS` read through a contract hint | 2.1 |
| S35 | Seven-line `routes/api.php` header | 7.5 |
| S36 | `ReconcileUsage` logic inline | 6.4 |
| S37 | `usageAmountOf()` feature envy | 6.5 |
| S38, S41 | `MiddlewareAlias` and `*Resource` naming | 7.4 |
| S39 | Second entrypoints on four actions | 7.3 |
| S40 | `servable()` re-spelled, unsubscribe block duplicated | 5.4, 7.3 |
| S42 | `OwnershipNominationNotification` constructor position | 5.4 |
| P1 | Admins cannot reach the closure screen | 8.1 |
| P2 | Scheduler age absent from `healthy()` | 8.2 |
| P3 | Two definitions of the seat limit | 2.2 |
| P4 | Extra panels on the staff tenant detail | 8.4 |
| P5 | Filament vendor lang deletion | Decisions, closed as correct |
| P6 | Unsigned impersonation token | D1, 1.1 |
| P7 | Banner missing from one layout | 8.3 |
| P8 | 2FA requirement off the tenant group | 1.2 |
| P9 | `--chunk=` missing from `tenancy:backup` | 4.3 |
| P10 | Causer anonymization not implemented | 4.1 |
| P11 | Actor filter filters the class, not the person | 4.2 |
| P12 | No `session:prune` schedule | 4.4 |
| P13 | `Password::min(8)` never specified | D4, 9.1 |
| P14 | `telescope/*` in the header exclusions | 9.2 |
| P15 | Full session-table scan on every read | 1.3 |
| P16 | Three notifications hard-returning `['mail']` | 5.1 |
| P17 | Preference toggles with no sender | 5.2 |
| P18 | Entitlement scalars not cached with `CacheTtl` | 6.1 |
| P19 | Plan metadata validated in the doctor, not at boot | 6.2 |
| P20 | Domain re-verification without backoff | 3.3 |
| P21 | API, notification centre and preferences ungated | D3, 7.1 |
| P22 | No domains resource under `/api/v1` | 7.2 |
| P23 | `digest` column and `digestible()` with no reader | D2, 5.3 |
| P24 | Invented anniversary billing period | 6.3 |
| P25 | `verified_at` nulled on a transient failure | 3.1 |
| P26 | Give-up window measured from `created_at` | 3.2 |

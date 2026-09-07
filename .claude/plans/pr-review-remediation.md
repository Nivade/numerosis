# Remediation plan: `package-scope-reduction` review findings

Written 2026-09-05 from a two-axis review of `git diff main...HEAD` on
`package-scope-reduction` (118 commits, 1097 files, +32360/-24327).
Partially executed — read the status table below before any phase.

The branch's scope is intended — scope reduction, the Fortify move, the config
consolidation, the domain-events expansion and the invitations/social redesign
all belong here. Nothing below argues for splitting it. These are defects and
staleness found inside that scope.

**Baseline at review time, all green:** `composer analyse` reports no errors,
`vendor/bin/pint --test` passes, `composer test` is 576 passed / 6 skipped /
0 failed. Every finding below is invisible to that suite, which is why each
phase names the test that would have caught it.

**Line numbers in this file drift.** Grep for the symbol; treat a mismatch as
drift, not as a missing thing.

## Execution status, re-audited 2026-09-07 against `3c3de4e`

Nineteen commits landed after the `1798bab` audit — a `src/` reorganization,
a central-migration squash, a webhook/wizard refactor, and the
"layer over a host app" change. **None of them executed a phase of this plan.**
Two phases closed as a side effect of that other work (7.2, 8.6) and one
regressed. Every row below was checked by reading the code at `3c3de4e`.

Baseline re-measured at `3c3de4e`: `pint --test` passes, `phpstan analyse`
clean from a cold cache (`rm -rf build/phpstan` first), `composer test`
649 passed / 6 skipped / 0 failed, `phpstan-baseline.neon` still 5 entries.

### Paths in this file drifted at `7197177`/`5862566`/`bc1c664`/`0c3c5b6`

Every phase below that names a file may name the old one. The renames that
matter here:

| This file says | Now |
|---|---|
| `src/Support/Numerosis.php` | `src/Numerosis.php` |
| `src/Support/Features.php` | `src/Support/FeatureRegistry.php` |
| `src/Commands/InstallNumerosisCommand.php` | `src/Console/Commands/InstallNumerosisCommand.php` |
| `src/Actions/Billing/CreateInlineSubscription.php` | `src/Actions/Billing/Checkout/CreateInlineSubscription.php` |
| `Observers\MembershipObserver` | `Observers\Tenancy\MembershipObserver` |
| `src/Support/Contributions.php` | deleted (see 7.2) |

`067e623` squashed the central migrations, so Phase 2's
`2025_06_04_100837_nullable_password_in_users.php` and Phase 8.1's
`2026_01_07_003825_new_plan_tables.php` no longer exist. Both facts they were
cited for still hold — `users.email` is `string()->unique()` with no
`nullable()` at `2019_09_01_000000_create_users_table.php:26`, and
`payment_plan_features` is `id`/`payment_plan_id`/`feature_id`/`available` at
`2025_06_23_213023_create_payment_plan_tables.php:44`.

| Phase | State at `3c3de4e` |
|---|---|
| 1 middleware identification mode | **Fixed, verified on a real boot.** `Numerosis::middlewareAliases()` now aliases `tenancy.identification`/`tenancy.route` to two new delegating classes, `Http\Middleware\InitializeTenancy`/`TenantRouteGuard`, which resolve `IdentificationMode::current()` at `handle()` time instead of alias-build time. Both are pure class-string literals with no facade/config read, so `Numerosis::middleware()` no longer touches `Config`/`Facade` before `RegisterFacades`. `IdentificationMode::current()`'s facade-null fallback is deleted — nothing calls it early any more. `TenancyServiceProvider::makeTenancyMiddlewareHighestPriority()` had to gain both new classes too: Laravel's priority sort runs against the alias's literal target, not what it delegates to, and missing this broke tenant-session ordering (`TenantAdminAuthTest`, `BotBlockingAuthTest`, `LoginRateLimitTest` all failed until added). New regression test: `NumerosisSeamTest`'s "registers mode-agnostic middleware aliases with no facade application bound" — confirmed failing (fatal facade crash) against the code before this fix. Verified end-to-end on `numerosis-thin-app`: booted its Sail stack (stale composer.lock needed `composer update nvade/numerosis --with-all-dependencies`; its `welcome.blade.php` also needed a stale `Support\Tenancy\SelfServeRegistration::FEATURE` reference updated to `Features\Tenancy\RegistrationWizardFeature::NAME`, both host-side fixes, not committed here), set `NUMEROSIS_TENANCY_IDENTIFICATION_MODE=path`, confirmed no crash-loop at boot, provisioned a tenant, and confirmed `/acme` resolves `window.Numerosis.tenantId === "acme"` — the exact request path that crash-looped before. Suite: 586 passed / 6 skipped here; PHPStan clean cold; baseline unchanged. |
| 2 OAuth null email | **Open, unchanged.** `LoginWithSocialAccount::createUser()` still writes `'email' => $data->email` against a NOT NULL unique column. `refreshTokens()` still calls `$data->accountAttributes()`, which always carries `email`, and `createSocialAccount()` spreads the same array — one array feeds all three write paths |
| 3 `AcceptInvitation` transaction | **Open, unchanged.** `AddTenantMember::run()` still inside `DB::transaction()`; `Observers\Tenancy\MembershipObserver::created()` still calls `SyncTenantUserForMembership::run()` (tenant-database write) and fires `MemberJoined` inside it; neither `MemberJoined` nor `MemberRemoved` implements `ShouldDispatchAfterCommit`, while `InvitationAccepted` in the same closure does |
| 4 webhook payload guard | **Open, and the phase text is stale in two ways.** `5f13e85` deleted `suspendBillableFor()`, so that half of the fix has no target. `current_period_end` gained an `is_int()` guard, so the `TypeError` is gone — the API-version question (field moved to subscription items in `2025-03-31.basil`) is still unverified. Three unguarded `customer` reads remain: `:91` into `TenantProvisionData::$stripeCustomerId`, `:174` and `:273` into `FindTenantByStripeCustomer`'s `?string`. The multi-item skip is still unexplained |
| 5.1 line-number citations | **Partial, 7 of 9 left.** `middleware-registration.md`'s two are gone. Still live: `tenant-filesystem.md:12`/`:15`, `tenant-registration-wizard.md:25`/`:27`, `package-boundaries.md:57` (now citing the renamed `FeatureRegistry.php:127`)/`:64`, `host-integration-quickstart.md:130` |
| 5.2 two alias registries | **Partial, and the leftover half is now actively wrong.** `middleware-registration.md` gained a correct "One alias registry" bullet naming `registerMiddleware()`. But the bullet below it still says `tenancy.identification`/`tenancy.route` "resolve through `TenancyServiceProvider`, not to a class literal" — Phase 1 made them class literals (`InitializeTenancy::class`, `TenantRouteGuard::class`), which is the opposite claim, and the new trap (this method is evaluated before facades bind, so literals *only*) is stated in `Numerosis.php`'s docblock and nowhere in the rules |
| 5.3 plans README | **Partial.** Re-sorted 2026-09-07: `config-consolidation.md` and `simplification-followups.md` archived. Two Live rows still wrong — `domain-events-expansion.md` says "Confirmed unstarted: no `src/Actions/Tenancy/EnsureTenantUserExists.php`" (that file exists), `invitations-social-redesign.md` says "Not executed" (`Models/Central/{Invitation,SocialAccount}`, `Enums/Auth/SocialProvider`, two migrations and three controllers all ship). The Abandoned row still says `--parallel` was dropped; `composer.json:106` is `pest --parallel` |
| 5.4 deleted-config instructions | **Open.** `invitations-social-redesign.md:221` and `post-extraction-review.md:14` both still point at `config/numerosis/schema-version.php`. No `schema_version` key exists in `config/` at all |
| 5.5 `comment-destyle.md` status | **Open, and much further from true.** Its table claims 1 over-budget docblock and 0 over-cap `//` runs in `src/`. Measured at `3c3de4e` with its own `awk`: **18 docblocks and 4 `//` runs in `src/` alone.** The reorg commits reintroduced them |
| 6.1 over-cap docblocks | **Open, and the scope grew ~10x.** The two originals stand (`HostConfig` 9 with the decorative bold intact, `TenantRegistrationData` 7). Repo-wide there are now **37**, worst first: `packages/ui/.../toast.blade.php` 16, `RoleAndPermissionSeeder` 14, `NumerosisUiServiceProvider` 13, `Contracts/Billing/BillableUser` 12, `GeneratesUniqueEmails` 12, `SubscriptionFactory` 12, `Numerosis.php:335` 11, `ConfiguredSteps` 10, `PaymentPlanSeeder` 10 |
| 6.2 `//` runs over cap | **Open, and moved.** Repo-wide **30**. `routes/web.php` 14/4/4, `routes/tenant.php` 13/11/8/5, `config/numerosis.php` 5/4/4 all persist. New since this plan: `packages/ui` blade components (12/12/12/7/6/4/4), `database/factories/Tenant/UserFactory` 8/4, `Central/TenantFactory` 8/5, and four in `src/` (`NumerosisServiceProvider:133` 6, `TenancyServiceProvider:304` 5, `AuthenticateLoginCandidate:145` 5, `DefaultUnpaidTenantQuota:30` 4) |
| 6.3 stale symbols | **Partial, 2 of 9 left.** Still live: `routes/tenant.php:27` (Filament tenant panel) and `routes/tenant.php:30` (`Socialite\Login`). `routes/web.php:38` also still says "the tenant panel documents 'home' as the one route name guaranteed to exist" — a tenth occurrence this plan missed. The invitations migration `updateOrCreate` comment (`:28`) against `SendInvitation`'s `firstOrNew`+`save` is unchanged. The `docs/architecture.md` and `docs/extending.md` mentions are deliberate records of the deletion, not stale references |
| 7.1 `ModelResolver::modelFor()` | **Open, unchanged.** Still `class_exists($hostModel)` with no `is_subclass_of` and no `numerosis.models` read, while `resolve()` twelve lines up does both |
| 7.2 `Contributions` middle man | **Closed.** `src/Support/Contributions.php` no longer exists; the state folded back. Nothing left to decide |
| 7.3 small ones | **2 of 4.** The two `Contributions` items went with the class. Still open: `HostConfig::set()` appends to `$applied` with no dedupe (`src/Support/HostConfig.php:71`), `SendInvitation::handle()` is still `firstOrNew` + `save()` against `unique(tenant_id, email)` |
| 8.1 `PaymentPlanFeatureFactory` | **Open, unchanged.** `definition()` still returns `feature_key` and six other columns the table does not have, still never sets the NOT NULL `feature_id`, still the only factory outside `database/factories/Central/`. No factory-instantiation test |
| 8.2 zero-reference states | **Open, unchanged.** All seven states still have zero callers across `tests/`, `workbench/`, `resources/`, `packages/` and `src/` |
| 8.3 stub/workbench parity | **Open, unchanged.** 8 stubs under `stubs/Models/Central/`, 6 twins under `workbench/app/Models/Central/`; `Invitation` and `SocialAccount` are the gap. No parity test |
| 8.4 workbench `welcome` view | **Open, and the measurement this phase asked for is done: `composer serve` opens a 500.** `php artisan route:list` shows exactly one `/` and it is `workbench/routes/web.php:7`. The package's own `/` never registers here at all — no `home` route, no `get-started`, no `billing/webhook` in the list — because `routes/web.php` is domain-scoped inside `Numerosis::routes()` and workbench does not call it. So the "delete it as dead code" branch is off the table; the view has to exist |
| 8.5 `rector.php` skip | **Open, unchanged.** `withSkip()` still lists `database/migrations`; `withPaths()` is still `src` and `tests` only |
| 8.6 npm dependencies | **Closed, by removal rather than by decision.** `package.json` no longer declares `laravel-echo`, `pusher-js` or `@laravel/echo-vue`, and no file under `resources/js/` imports any of them |
| 9.1 `static` widened to `Model` | **Open, unchanged.** `Concerns\Billing\Billable::subscriptionsRelation(Model)` and `Models\Central\CentralUser::tenantsRelation(Model)` both still there |
| 9.2 post-charge `RuntimeException` | **Open.** Now at `src/Actions/Billing/Checkout/CreateInlineSubscription.php:92`, still after the Stripe call and before `$pending->update()` |
| 9.3 two fallbacks for one session value | **Open, unchanged.** `Steps/Plan.php:82` `tryFrom() ?? Monthly` against `Steps/Payment.php:33` `BillingCycle::from()` |
| 9.4 `socialiteproviders/manager` | **Open.** `composer.json` still requires `socialiteproviders/discord` only |
| 9.5 four copies of `available()` | **Closed.** One copy, in `src/Features/Concerns/IsNamedFeature.php` |
| 9.6 comment regressions | **Open,** and folded into the 6.1/6.2 counts above. `RoleAndPermissionSeeder` went 14→14 and 9, `SeedsAdminRole` 9 plus a 6-line `//` run, `SubscriptionFactory` 12 and 9 plus a 6-line run |
| 9.7 naming and placement | **1 of 4.** `HostConfig::filesystemDisks()` now *removes* `livewire` from the tenant-suffixed disk list rather than appending it, and its docblock matches, so that item is moot. Still open: `CompleteRedirectCheckout::tenantsMine(string $level, string $message)` where `$message` is a translation key; `numerosis.tenancy.implementations` (`config/numerosis.php:357`) still binding four non-tenancy auth contracts; `TestCase::ensureDatabaseExists()` still hardcoding a second copy of the MySQL credentials at `tests/TestCase.php:579` |

Scope delivered before `1798bab` that this plan never asked for, kept so a
later reader does not mistake it for a phase: Turnstile and Discord promoted to
`require` with both `class_exists` seams deleted; the PHPStan baseline cut from
109 entries to 5; the CI parallel-worker database fix; `Context::guard()`
adopted across the auth surface; eight class extractions; `Numerosis::modelStubs()`
as the single model list.

## Execution protocol

Written for whoever executes this, after `86ecb9e` did the pleasant half and
reported the whole. Everything here is a constraint on the run, not on the
findings.

**One phase per session, one commit per phase.** Update that phase's row in the
status table in the same commit. The row is the handoff; the phase prose is a
record of what was found and stays as written.

**A phase is done when its own Test section passes, not when the suite is
green.** The suite was green for every defect in this file. Write the test
first, run it, and watch it fail against the unfixed code — a test that passes
before the fix proves nothing, and `.ai/rules/testing.md` records that this
repo has shipped exactly that mistake before.

**The PHPStan baseline must not grow. Ever.** It went 109 entries to 5 in
`1798bab`. Adding an entry to make `analyse` pass is the one shortcut that
undoes the most work here, and it looks like success. If a fix cannot be made
level-9 clean, stop and say so. Run cold (`rm -rf build/phpstan` first);
a warm cache hides errors.

**Change nothing this file does not name.** No extractions, no renames, no
import tidying, no "while I was in there". `86ecb9e` shipped eight extractions
nobody asked for and four unfixed defects. If a real problem turns up outside
the phase, write it down and leave it.

**Run the `awk` commands from `general.md` verbatim.** POSIX classes, not `\s`.
The default `awk` here is mawk, which matches nothing on `\s` and does not
error — a version of that check reported 0 hits against 114 real ones. Do not
rewrite them to be clearer.

**Do not call `record-rule` for the Phase 5 edits; hand-edit the file.** It
regenerates `.ai/rules/index.md` from `paths:` frontmatter and discards the
preamble and every row's note. It has done this twice. If something does call
it, diff `index.md` before committing.

### Stop and ask, do not decide

These need a decision that is the maintainer's, not the executor's. Bring the
options; do not pick one and proceed:

- **2** — refuse the null-email login, or make `users.email` nullable. This
  file recommends refusing, but the second option changes the auth surface.
- ~~**7.2**~~ — settled: `Contributions` was folded back before 2026-09-07.
- **8.2** — write the missing tests for `bot()` /
  `forCheckout()`/`provisioning()`/`failed()`, or delete the states.
- **9.1** — try `static` on both sides once and run `analyse` cold. If real
  errors survive, revert and report them. Do not iterate on generic variance;
  that is a rabbit hole with no floor.

### Phase 1 cannot be closed from this repo

Its bug is invisible to Testbench by construction — that is the finding. The
unit test and the per-mode route tests are necessary and not sufficient. It
stays open until `numerosis-thin-app` boots under
`NUMEROSIS_TENANCY_IDENTIFICATION_MODE=path` and a tenant route resolves a
tenant. If that checkout is not available, do the code change, mark the row
**Fixed, unverified on a real boot**, and say so in the commit message.

## Phase 1 — `Numerosis::middleware()` picks the wrong identification mode in a real host

Highest priority. A host on `path` or `custom_domain` identification boots with
subdomain-mode middleware, and every test here stays green.

### What happens

`Numerosis::middlewareAliases()` resolves two entries eagerly:

```php
'tenancy.identification' => TenancyServiceProvider::identificationMiddleware(),
'tenancy.route' => TenancyServiceProvider::tenancyRouteMiddleware(),
```

Both call `IdentificationMode::current()`, which reads
`numerosis.tenancy.identification.mode` through the `Config` facade. It opens
with a guard:

```php
if (Facade::getFacadeApplication() === null) {
    return self::Subdomain;
}
```

`Numerosis::middleware()` is passed to `withMiddleware()` in a host's
`bootstrap/app.php`. `ApplicationBuilder::withMiddleware()` registers that
callback through `afterResolving(HttpKernel::class, …)` and
`afterResolving(ConsoleKernel::class, …)`, both of which fire before the
kernel's own `bootstrap()` — that is, before `RegisterFacades`. See
`.ai/rules/package-host-bootstrap.md`, which records the 2026-08-31 incident
where this same ordering fataled every real request and every `artisan` call.

So at the moment `middlewareAliases()` is evaluated on a host boot, the facade
application is null, the guard returns `Subdomain`, and the aliases are frozen
to `InitializeTenancyByDomainOrSubdomain` and `PreventAccessFromCentralDomains`
whatever the config says. Under path mode `tenancy.route` should be
`NullMiddleware`; `PreventAccessFromCentralDomains` instead blocks tenant
routes on the central domain, which under path mode is every tenant route.

`main` did not have this. Its `Numerosis::middleware()` used the
`TenancyServiceProvider::TENANCY_IDENTIFICATION` constant and a class literal,
reading no config at all. Unifying the two alias registries onto one method
(a good change on its own, see Phase 3) pulled the config read onto the host
path with it.

### Why nothing goes red

Inside this repo the aliases are registered a second time by
`NumerosisServiceProvider::registerMiddlewareAliases()` at boot, with facades
up, which overwrites the wrong values. Testbench never exercises the
facade-less call.

`IdentificationModeTest::test_each_mode_selects_its_own_identification_middleware()`
calls `TenancyServiceProvider::identificationMiddleware()` directly with the
container fully booted, so it passes and proves nothing about this path. Only
`tests/Feature/Support/NumerosisSeamTest.php` and `tests/TestCase.php` touch
`Numerosis::middleware()`, both under Testbench.

### Fix

Defer the resolution instead of baking it in. Laravel resolves a middleware
alias out of the container at match time, so the alias can name a class that
picks the implementation then, rather than naming the implementation now.

Preferred shape: alias both keys to stable class names and let those classes
delegate.

- `tenancy.identification` aliases to a single `InitializeTenancy` middleware
  whose `handle()` resolves `IdentificationMode::current()` per request and
  delegates to the mode's real middleware.
- `tenancy.route` aliases to a single `TenantRouteGuard` that either delegates
  to `PreventAccessFromCentralDomains` or passes through, on the same read.

Both then read config at request time, when it is correct, and
`middlewareAliases()` returns pure class-string literals with no container
dependency.

If that is too large a change to take here, the smaller fix is to keep
`middlewareAliases()` literal-only and have `NumerosisServiceProvider` register
the two mode-dependent aliases separately at boot — but that reinstates the two
registries Phase 3 is about, so prefer the delegating middleware.

Either way, delete the `Facade::getFacadeApplication() === null` guard in
`IdentificationMode::current()` once nothing calls it before facades are bound.
The guard's only effect today is to convert a loud crash into a silent wrong
default, which is strictly worse.

### Test

A unit test that calls `Numerosis::middleware()` with the facade application
unset, and asserts the aliases it produced are mode-independent:

```php
$app = Facade::getFacadeApplication();
Facade::clearResolvedInstances();
Facade::setFacadeApplication(null);

try {
    // build a Middleware configurator, run Numerosis::middleware() on it,
    // assert 'tenancy.identification' and 'tenancy.route' resolve to the
    // mode-agnostic classes and that no exception was thrown
} finally {
    Facade::setFacadeApplication($app);
}
```

Then a route-level test per mode — hit a tenant route under `path` mode and
assert it resolves the tenant, rather than asserting on the class name. The
rule in `.ai/rules/middleware-registration.md` already says a route-level
assertion is the shape that catches this class of bug; a unit test on the
middleware stays green while nothing applies it.

Confirm end to end against `numerosis-thin-app` with
`NUMEROSIS_TENANCY_IDENTIFICATION_MODE=path` before closing this phase. That
checkout is the only real (non-Testbench) boot available, and it is what caught
the 2026-08-31 version of this.

## Phase 2 — OAuth callback 500s for a provider that returns no email

### What happens

`users.email` is `->unique()` and NOT NULL
(`database/migrations/central/2019_09_01_000000_create_users_table.php`). Only
`password` was ever made nullable (`2025_06_04_100837_nullable_password_in_users.php`).

`SocialUserData::$email` is `?string`. `ResolveSocialUser::handle()` passes
`$user->getEmail()` through unchanged, and Socialite's contract allows null.
`LoginWithSocialAccount::createUser()` then does:

```php
'email' => $data->email,
```

with no guard. The `$hasLocalAccount` check above it is skipped entirely when
the email is null, so a null address reaches the insert and violates NOT NULL.
`HandleProviderCallbackController` catches only `ShowsMessageToUser`, so the
`QueryException` surfaces as a 500 on `/auth/{provider}/callback`.

Reachable with the shipped provider set: Discord returns no email without the
`email` scope, and Facebook users can decline the email permission. Both are
`SocialProvider` cases.

### Fix

Decide the policy and apply it in one place. Two options:

1. **Refuse.** `LoginWithSocialAccount::handle()` returns `null` when
   `$data->email === null` and no `(provider, provider_id)` match exists.
   `HandleProviderCallbackController` already renders `null` as a redirect to
   `login` with a message; give it a distinct one ("Your :provider account has
   no email address we can use. Add one there, or sign in another way.").
   This is the smaller change and keeps the `users.email` invariant.
2. **Accept.** Make `users.email` nullable and audit everything keyed on it —
   Fortify's password reset, `whereEmailMatches()`, the invitation email match
   in `StoreInvitationRequest::withValidator()`, and the unique index (which
   permits multiple NULLs on both MySQL and Postgres, so it stops preventing
   duplicate accounts).

Take option 1. Option 2 spreads a nullable through the auth surface for a case
the package has no product answer for.

### Second defect in the same file

`LoginWithSocialAccount::refreshTokens()` calls
`$account->update($data->accountAttributes())`, and `accountAttributes()`
always includes `'email' => $this->email`. A provider that returns an email on
first login and omits it later overwrites the stored address with null. Filter
nulls out of the refresh path, or make `refreshTokens()` update only the
credential columns (`token`, `refresh_token`, `token_expires_at`), which is
what its name says it does.

### Third, lower priority

`ResolveSocialUser::isEmailVerified()` reads `$raw['email_verified']` for
GitHub. GitHub's `/user` response — which is what Socialite puts in
`$user->user` — does not carry that key; verification status lives on
`/user/emails`. If that holds, GitHub always evaluates to `false` and never
qualifies for the conditional email link, while
`.ai/rules/auth-login.md` states that Google and GitHub both expose it.

This fails closed, so it is not a security defect. Verify against the actual
payload before changing anything. Then either wire the `/user/emails` lookup
(requires the `user:email` scope) or drop GitHub from the match arm and correct
the rule text.

### Test

- Feature test: callback with a provider payload carrying `email => null`,
  assert a redirect to `login` with the message, and assert no `users` row was
  created.
- Feature test: existing `SocialAccount`, second callback with `email => null`,
  assert the stored email is unchanged.

## Phase 3 — cross-connection write and uncommitted event inside `AcceptInvitation`'s transaction

### What happens

`AcceptInvitation::handle()` wraps its whole body in `DB::transaction()`. Inside
it, `AddTenantMember::run()` calls `$user->tenants()->attach(...)`, which fires
`MembershipObserver::created()`. That observer does two things the transaction
cannot cover:

1. `SyncTenantUserForMembership::run($membership)` →
   `EnsureTenantUserExists::run($tenant, $user)`, which **writes the tenant
   database**. A rollback on the central connection cannot undo it. The tenant
   database keeps a `users` row for a membership that does not exist centrally.
   `EnsureTenantUserExists` is `firstOrCreate`-idempotent, so the state is
   recoverable rather than corrupt, but it is still wrong.

2. `event(new MemberJoined(...))`. `MemberJoined` does **not** implement
   `ShouldDispatchAfterCommit`. Per
   `.ai/rules/events-listeners-observers.md`: "Anything dispatched from inside
   a transaction needs `ShouldDispatchAfterCommit`; `event()` fires immediately
   otherwise and a queued listener can read a row that hasn't committed." A
   queued listener sees no membership row, and on rollback the event has
   already fired for a membership that never existed.

`InvitationAccepted`, dispatched two statements later in the same transaction,
*does* implement `ShouldDispatchAfterCommit`. The branch got it right one line
below and wrong here.

### Fix

- Add `ShouldDispatchAfterCommit` to `Events\Tenancy\MemberJoined` and
  `Events\Tenancy\MemberRemoved`. `MemberRemoved` fires from
  `MembershipObserver::deleted()` and is exposed to the same shape wherever a
  detach is wrapped.
- Move the tenant-side write out of the central transaction. Options, in order
  of preference:
  - Narrow `AcceptInvitation`'s transaction to the claim (`UPDATE … WHERE
    accepted_at IS NULL`) and the `attach()`, and run
    `SyncTenantUserForMembership` after commit — `DB::afterCommit()` inside the
    observer, or an explicit call in `AcceptInvitation` after the closure
    returns.
  - If the observer must stay synchronous (the rule explains why it is an
    observer and not a listener: the tenant-side row is a data invariant, not a
    reaction a host may unregister), wrap only its tenant write in
    `DB::afterCommit()`.

Check the other `attach()` call sites for the same shape before choosing;
`AddTenantOwner` runs as a provisioning step where `isProvisioned()` is still
false, so the observer skips there and only `AcceptInvitation` is affected
today.

### Test

- `AcceptInvitation` inside a transaction that is rolled back afterwards:
  assert no tenant-side `users` row survives.
- `Event::fake()` plus a forced failure after `AddTenantMember::run()`: assert
  `MemberJoined` was not dispatched.

Note that `tests/TestCase.php` sets `queue.default = 'sync'`, so every
`ShouldQueue` listener runs inline and the ordering bug is invisible by
default. The `AddTenantOwnerTest` pattern applies — `Queue::fake()`, placed
after any factory call that queues its own jobs.

## Phase 4 — unguarded webhook payload field

`WebhookController::handleCustomerSubscriptionDeleted()`:

```php
$customerId = $payload['data']['object']['customer'] ?? null;
$this->suspendBillableFor($customerId);
$tenant = FindTenantByStripeCustomer::run($customerId);
```

`FindTenantByStripeCustomer::handle()` is typed `?string`. PHPStan accepts the
call because the method's array-shape docblock declares `customer?: string`,
but that shape is a claim about untrusted input, not a check. Stripe sends an
expanded object for `customer` under some configurations, which produces a
`TypeError`, a 500, and a webhook Stripe retries indefinitely.

`handleCustomerSubscriptionUpdated()`, two methods down, guards the same field:
`is_string($customerId) ? $customerId : null`. Apply the same guard here and to
`suspendBillableFor()`.

### Also verify: `current_period_end`

The same method reads:

```php
$periodEnd = $payload['data']['object']['current_period_end'] ?? null;
```

to populate `SubscriptionCancelled::$gracePeriodEndsAt`. Stripe moved
`current_period_end` off the subscription object onto subscription items in API
version `2025-03-31.basil`. If the account's pinned version is at or past that,
the key is absent, `$periodEnd` stays null, and the grace period silently
reports as unknown on every cancellation. Check the version Cashier v16 pins
and read the field from `items.data[0].current_period_end` if it has moved.
Add a fixture-based test either way, so the shape is pinned rather than assumed.

### Also: undocumented multi-item skip

```php
$newPriceId = count($items) === 1 ? ($items[0]['price']['id'] ?? null) : null;
```

A subscription with more than one item never reports `SubscriptionPlanChanged`.
That is probably deliberate now that the module system (the only thing that
added items) is gone, but nothing says so and the comment above it explains a
different case (the null previous price). State the reason or handle the case.

## Phase 5 — stale rules and plan status

### 5.1 Every line-number citation in `.ai/rules/` is stale

All nine, checked against HEAD:

| Rule | Cites | Actually at that line now |
|---|---|---|
| `middleware-registration.md:42` | `src/NumerosisServiceProvider.php:467-486` | file ends well before 467 |
| `middleware-registration.md:44` | `src/Support/Numerosis.php:401-435` | `'tenancy.session',`, inside `middlewareGroups()` |
| `package-boundaries.md:58` | `src/Support/Features.php:127` | `if (isset($map[$name])) {` |
| `package-boundaries.md:65` | `src/NumerosisServiceProvider.php:243-251` | `$this->registerSchedule();` |
| `package-boundaries.md:115` | `src/Support/Numerosis.php:512` | a bare `*/` |
| `tenant-filesystem.md:12` | `src/Support/HostConfig.php:255-263` | `}` — the real `root_override.local` is at ~333 |
| `tenant-filesystem.md:15` | `src/NumerosisServiceProvider.php:218-230` | blank — the livewire disk block is at ~201-212 |
| `tenant-registration-wizard.md:25` | `routes/web.php:49` | blank |
| `tenant-registration-wizard.md:27` | `src/Features/Tenancy/RegistrationWizardFeature.php:90` | `Livewire::addComponent(` |

`.ai/rules/index.md` already tells readers to grep for the symbol rather than
seek to a line, for `.claude/plans/`. Apply the same standard to the rules
themselves: replace every `path.php:NNN` with the enclosing symbol
(`NumerosisServiceProvider::registerMiddlewareAliases()`,
`HostConfig::corrections()`, and so on). A symbol survives a refactor; a line
number does not survive one commit.

### 5.2 `middleware-registration.md`'s "two alias registries" trap is solved

The rule says two registries exist and must not drift. This branch unified
them: `NumerosisServiceProvider` now iterates `Numerosis::middlewareAliases()`
and `Numerosis::middlewareGroups()` rather than repeating the list. There is
one source of truth.

Rewrite that bullet to record the fix and why the shape matters — and, once
Phase 1 lands, to say that `middlewareAliases()` must return class-string
literals only, because it is evaluated before facades are bound. That
constraint is the new trap and it is the reason the old one could not simply be
deleted.

### 5.3 `.claude/plans/README.md` is wrong on all three Live rows

Last touched at `f12ed0a`, well before this work. At HEAD:

- `config-consolidation.md` — "**Not executed** … `config/numerosis/` still
  holds the partials". The directory is gone; one `config/numerosis.php` with
  eleven top-level keys ships.
- `domain-events-expansion.md` — "**Not executed** … Confirmed unstarted: no
  `src/Actions/Tenancy/EnsureTenantUserExists.php`". That file exists.
- `invitations-social-redesign.md` — "**Not executed**".
  `Models/Central/Invitation`, `Models/Central/SocialAccount`,
  `Enums/Auth/SocialProvider`, three migrations and four controllers all ship.

Move all three to `archive/` and rewrite the Live table. Re-validate the fourth
row (`post-extraction-review.md`) against the tree at the same time.

### 5.4 Two live plans instruct against deleted config

- `invitations-social-redesign.md:221` — "Bump
  `config/numerosis/schema-version.php`; the shape changed."
- `post-extraction-review.md:14` — "**4.3** shipped — `schema_version` is in
  `config/numerosis/schema-version.php`".

Phase 2 of the config consolidation deleted `schema_version` outright. Both
files are loose, so they read as current instruction. Archiving them (5.3)
handles the first; `post-extraction-review.md` needs its status correction
edited, since three of its items are genuinely still open.

### 5.5 `comment-destyle.md` claims a completeness that no longer holds

Its status table says one over-budget docblock remains in `src/`, named as
`InitializeTenancyByDomainOrSubdomain.php`. At HEAD there are two, and neither
is that file — see Phase 6. The `HostConfig` rewrite landed after the sweep
closed and regressed it. Update the table, or re-run the sweep's own commands
and restate it from measurement.

## Phase 6 — comment standard

`.ai/rules/general.md` applies to `**/*.php`, caps docblock prose at 5 lines and
`//` runs at 3, and states "One budget, no exemptions". The sweep scoped itself
to `src/`, which is why the violations cluster outside it.

Run the two `awk` commands from `general.md` (POSIX classes, not `\s` — mawk
silently matches nothing otherwise) over `src/`, `config/`, `routes/` and
`packages/` after each batch.

### 6.1 Two over-cap docblocks in `src/`

- `src/Support/HostConfig.php` class docblock — 9 prose lines. Also carries
  decorative bold (`**preference**`, `**correction**`), a pattern
  `general.md` names explicitly, and four facts against the one-fact rule.
  The preference/correction split is already documented in
  `.ai/rules/package-boundaries.md`, which is where the long form belongs.
  Reduce to the one sentence a reader of this class needs, with no pointer back.
- `src/Data/Tenancy/TenantRegistrationData::rules()` — 7 prose lines, two
  em-dashes, and "same field, different rules by design" binary contrast.

Run `validate_preservation.py` from the `unslop` skill on old against new before
each edit lands, and diff the backticked identifiers — `general.md` records four
fact losses in edits that had already been reviewed by hand and called finished.

### 6.2 `//` runs over cap outside `src/`

`routes/tenant.php` (13, 11, 8 and 5 lines), `routes/web.php` (13, 4, 4) and
`config/numerosis.php` (5, 4, 4). Both route files were substantially rewritten
in this branch. Route these the way `general.md` says: the facts that are
package-wide invariants go to `.ai/rules/`, the facts a host must act on go to
`docs/`, and the source keeps one sentence.

### 6.3 Stale symbol and subsystem references in comments

- `routes/tenant.php` names `Socialite\Login`. That class was deleted in this
  branch; the replacement is `Http\Controllers\Auth\Social\*`.
- Nine live references to the Filament panel deleted 2026-09-03, one of them
  user-facing:
  - `src/Commands/InstallNumerosisCommand.php` — the failure message "a tenant
    panel answers 404 on every tenant URL when this is unresolvable", printed by
    `numerosis:install` to a host that has never had a panel.
  - `src/Features/Tenancy/MembershipsFeature.php` — "The tenant panel's Team
    screens".
  - `src/Actions/Auth/PromoteFirstCentralUserToAdmin.php` — "someone who can
    reach the admin panel".
  - `routes/web.php` — "the tenant panel documents 'home' as the one route name
    guaranteed to exist".
  - `routes/tenant.php`, twice — "Registered here since the Filament tenant
    panel was deleted", "The deleted tenant panel owned the only registration
    of `EnsureTenantSubscriptionActive`".
  - `docs/host-requirements.md`, three times.

  `general.md`: "Dated moves, old class names and superseded designs are already
  in git." The two `routes/tenant.php` occurrences are the interesting case —
  each states a real invariant (why `/` is registered here, why the subscription
  group exists and is empty) wrapped in the history of how it got that way.
  Keep the invariant, drop the history. The
  `EnsureTenantSubscriptionActive` incident is already recorded in
  `.ai/rules/middleware-registration.md`, which is its right home.

  `tests/Feature/PackageBoundariesTest.php` matches `/Filament\\/` — a symbol
  reference, not the bare word — so none of these trip it. That is correct
  behaviour for that test; do not widen it to prose.

- `database/migrations/central/2026_09_04_000001_create_tenant_invitations_table.php`
  says the unique index exists so "a re-invite of the same address updates the
  existing row (`updateOrCreate`)". `SendInvitation::handle()` uses
  `firstOrNew` + `forceFill` + `save`. Name the method the code calls, or drop
  the parenthetical.

## Phase 7 — design cleanups

Lower value. Take them if the files are open anyway; none is worth its own pass.

### 7.1 `ModelResolver::modelFor()` ignores `numerosis.models.*`

`resolve()` honours an explicit `numerosis.models.<FQCN>` override and guards
the conventional `App\Models\<suffix>` guess with `is_subclass_of`.
`modelFor()` does neither: it checks `class_exists` on the host-namespaced path
and otherwise falls back to `Nvade\Numerosis\Models\<suffix>`.

It is registered globally at `NumerosisServiceProvider` via
`Factory::guessModelNamesUsing()`. So a host that points
`numerosis.models.<Tenant FQCN>` at a class outside `App\Models\` gets
`resolve()` returning the override while `TenantFactory` builds the package
class — factories exercise a model production never uses, and no test notices
because the divergence is between two correct-looking classes.

Make `modelFor()` consult the same `numerosis.models` map, keyed in reverse,
before falling back. Add the `is_subclass_of` guard to match `resolve()`.

Test: set `numerosis.models.<Tenant FQCN>` to a subclass outside `App\Models\`
and assert `TenantFactory::new()->create()` returns that class.

### 7.2 `Contributions` is a pure delegation layer

231 new lines, fourteen public methods, every one a single-line pass-through.
One caller, `Numerosis.php`, and the class docblock tells readers to go through
`Numerosis::add*()` rather than calling it directly — so nothing ever will.
Fowler's Middle Man.

Splitting the state out of a 37-method facade is a reasonable motive, and the
`source` attribution array is real behaviour. But the current shape pays for
the split twice: two names for one operation
(`Numerosis::resetRouteContributionsForTesting()` /
`Contributions::flushRouteContributions()`), and a reader who has to open two
files to answer one question.

Either make `Contributions` the public seam and reduce `Numerosis`'s
contribution methods to documented aliases, or fold it back. Do not leave it
half-way. This is a judgement call, not a defect — record the decision either
way, since the next reader will ask the same question.

### 7.3 Small ones in the same files

- `Contributions::flushMigrationAndSeederContributions()` also clears
  `$permissionContexts`. Rename it, or split the permission contexts out.
- `Contributions::permissionContexts()` is public but declared after the
  private `appendOnce()`, breaking the file's own ordering.
- `HostConfig::set()` appends to `$applied` with no dedupe, while
  `Contributions::appendOnce()` exists for exactly that. `HostConfig::applied()`
  can therefore report a key twice, and `numerosis:install` prints that list.
- `SendInvitation::handle()`'s `firstOrNew` + `save()` is not atomic against
  `unique(tenant_id, email)`. Two concurrent invites to one address make the
  second a `QueryException` rather than a validation error. `upsert`, or catch
  `UniqueConstraintViolationException` and re-read, as
  `WebhookController::handleCustomerSubscriptionCreated()` already does.

## Phase 8 — deferred findings from the `/simplify` pass

Added 2026-09-05, from a reuse/simplification/efficiency/altitude pass over
`database/`, `routes/`, `stubs/`, `packages/`, `workbench/` and the five root
config files across `main...HEAD`.

That pass already landed its mechanical half on the branch: `GeneratesUniqueEmails`
applied to the two factories the diff left on `fake()->unique()->safeEmail()`,
the shared permission-seeding loop extracted to
`Database\Seeders\Concerns\SeedsAdminRole`, a `Log::info()` wrapper deleted
from `Tenant\PermissionAndRoleSeeder` (with its now-obsolete
`phpstan-baseline.neon` entry), dead commented-out code removed from four
files, and inline FQCNs replaced by imports. Suite after: 585 passed /
6 skipped / 0 failed, `composer analyse` clean from a cold cache.

Everything below was found by the same pass and deliberately left alone,
because each needs a decision rather than an edit. They are ordered by
consequence, not by effort.

Note on the long-method sweep: of the three methods over 40 lines in scope, two
are the vendored spatie `create_permission_tables` copies (113 lines, central
and tenant) and are covered by 8.7; the third,
`RoleAndPermissionSeeder::run()`, is already reduced by the `SeedsAdminRole`
extraction. `2026_07_28_120000_move_tenant_columns_out_of_data_column.php::up()`
at 41 lines is a linear data migration and should stay as it is.

### 8.1 `PaymentPlanFeatureFactory` writes columns that no longer exist

The sharpest item in this phase, and not a tidy-up: this factory cannot run.

`database/factories/PaymentPlanFeatureFactory.php::definition()` returns
`feature_key`, `feature_name`, `description`, `value_type`, `value`,
`is_enabled` and `sort_order`. The live `payment_plan_features` table is
`id`, `payment_plan_id`, `feature_id`, `available` — that is
`2026_01_07_003825_new_plan_tables.php`, which recreated the table after
`2026_01_07_001248_unfuck_payment_plans_and_features.php` dropped it. The four
named string columns had already gone in
`2025_06_25_105704_update_payment_plan_features.php`. No later migration
restores any of them.

So `PaymentPlanFeature::factory()->create()` throws `Unknown column
'feature_key' in 'field list'`. Nothing calls it: the only two references in
the repo are `src/Models/Central/PaymentPlanFeature.php:15` (the import) and
its `/** @use HasFactory<PaymentPlanFeatureFactory> */` docblock. That is also
why nothing goes red — factory attribute arrays are untyped, so PHPStan sees
a well-formed `array<string, mixed>` and the suite never constructs one.

Its six unused states (`boolean()`, `integer()`, `string()`, `disabled()`,
`sortOrder()`, `forPlan()`) all set `value_type`/`value`/`is_enabled`/
`sort_order`, so they describe the pre-2026 schema throughout.

The file is also the only factory not under `database/factories/Central/`,
despite its model living at `Models\Central\PaymentPlanFeature` — a second
signal it was missed when the plan tables were rebuilt.

**Recommendation: delete the file** and the `HasFactory` trait use and
docblock on the model. The pivot is written exclusively through
`PaymentPlan::features()->sync()` with an `available` flag
(`PaymentPlanSeeder::seedPlan()`); nothing constructs it directly, and a
rewritten factory would have one column of its own to set. If a direct fixture
is wanted later, `PlanFeatureFactory` plus `sync()` already covers it.

**Test, and the reason this phase is worth its own test:** an architecture or
feature test that walks every class under `database/factories/`, instantiates
it and calls `->make()`, asserting no exception. That is a handful of lines,
catches this class of rot at the moment a migration lands rather than years
later, and is the only thing that would have caught this one. It also fails
today, which makes it a good first commit for this phase.

### 8.2 The remaining zero-reference factory states

Eight more public states have no caller anywhere, including Blade:

| File | States |
|---|---|
| `database/factories/Central/CentralUserFactory.php` | `unverified()` |
| `database/factories/Tenant/UserFactory.php` | `unverified()`, `bot()` |
| `workbench/database/factories/UserFactory.php` | `unverified()` |
| `database/factories/Central/PendingTenantProvisionFactory.php` | `forCheckout()`, `provisioning()`, `failed()` |

Decide these by audience, not by usage count. A factory shipped inside
`nvade/numerosis` is public API a host's own tests call; the workbench copy is
Testbench scaffolding and is not.

- **Keep `unverified()`** in both shipped factories. It is a Laravel-convention
  state, hosts reasonably expect it, and it costs four lines. The finding worth
  acting on is that there are three copies of it, which 8.3 covers.
- **`bot()` documents a test that does not exist.** Its docblock says "The
  seeded Chat Bot occupies a real row in every tenant database and is skipped
  by `PromoteFirstUserToAdmin`, so tests covering that need one" — and no test
  does. Either write that test, or delete the state and let the `is_bot` note in
  `definition()` carry the trap on its own.
- **`forCheckout()` / `provisioning()` / `failed()`** are the exact shape a
  provisioning-status transition test would use, and
  `.ai/rules/tenant-provisioning.md` documents races those states model. This
  is adjacent to Phase 3. Write those tests, or delete the states; do not leave
  fixtures standing in for coverage that was never written.

### 8.3 `stubs/` and `workbench/app/Models/` are byte-identical, and have already drifted

Seven pairs are identical byte for byte:

```
stubs/Models/Central/{CentralUser,Domain,PaymentPlan,PendingTenantProvision,Subscription,Tenant}.stub
stubs/Models/Tenant/User.stub
```

against the same paths under `workbench/app/Models/`. That is not accidental
duplication — `NumerosisServiceProvider` publishes
`stubs/Models/{$relative}.stub` to `app_path("Models/{$relative}.php")`, and
composer's `autoload-dev` maps `App\Models\` to `workbench/app/Models/`, so
workbench is standing in for a real host's published output. Identical content
is the invariant.

It is already broken. This branch added `stubs/Models/Central/Invitation.stub`
and `stubs/Models/Central/SocialAccount.stub` with no workbench twin, so two
of the nine published models are never exercised as host classes. Nothing goes
red because `Numerosis::model()` falls back to the package class when
`App\Models\Central\Invitation` is absent — the fallback is correct behaviour
and it is also what hides the gap.

**Recommendation:** add the two missing workbench models, then add a test that
walks `stubs/Models/**/*.stub`, derives the workbench path, and asserts the
file exists with identical content. That makes workbench the standing proof
that every published stub compiles and resolves, and it fails the moment a
tenth stub is added without one. Prefer this over generating the models during
`workbench:build`: generated files are invisible in review, and the whole
value here is that a reviewer sees the pair.

### 8.4 `workbench/routes/web.php` renders a view that does not exist

```php
Route::get('/', function () {
    return view('welcome');
});
```

There is no `welcome.blade.php` anywhere in the repo — `workbench/resources/views/`
contains only `.gitkeep`. `testbench.yaml` sets `workbench.start: '/'`, so this
is where `composer serve` lands.

This is a correctness defect rather than a cleanup, which is why the
`/simplify` pass did not touch it; it is recorded here because it sits in the
same directory as the rest of the phase.

**Before fixing, establish which route wins.** The package's own
`routes/web.php` registers `/` as `home`, and its comment states that
registering a second route named `home` does not work because the first
declaration wins the path match. If the package route is registered first,
this workbench route is unreachable dead code and should simply be deleted; if
it is not, `composer serve` currently opens a 500. The two fixes are different,
so measure before choosing.

While in the file: `workbench/app/Providers/WorkbenchServiceProvider.php` has
empty `register()` and `boot()` bodies and is registered nowhere — its only
mention in the repo is the commented-out line in `testbench.yaml`. Leave it.
That commented line is the documented enable-seam and the class is stock
Testbench scaffolding; deleting it buys nothing and removes the seam.

### 8.5 `rector.php` skips a path it never scans

`->withSkip([__DIR__.'/database/migrations', …])` matches nothing:
`->withPaths([...])` lists only `src` and `tests`, and neither
`withComposerBased()` nor `withSetProviders()` adds paths.

**Recommendation: keep it, and say so in one line.** It is a forward guard — if
anyone later adds `database` to `withPaths()`, that entry is what stops rector
rewriting applied migrations. Removing it is a simplification that quietly
deletes a safety net. Amend the comment to state that it guards a path not
currently scanned, so the next reader does not re-derive this.

### 8.6 Two npm dependencies that nothing imports

`package.json` declares `laravel-echo` and `pusher-js`; no file under
`resources/js/` imports either. `resources/js/numerosis.js` imports
`@laravel/echo-vue`, and `stripe-checkout.js`/`stripe-confirm.js` import
`@stripe/stripe-js`.

Both are probably still required and **must not be removed on static grounds**:
`@laravel/echo-vue` depends on `laravel-echo`, and Echo's Reverb connector
instantiates `pusher-js` internally because Reverb speaks the Pusher protocol.
A transitive dependency that the bundler resolves does not appear as an import.

**Recommendation:** decide empirically or not at all. `npm run build`, then grep
`dist/numerosis.js` for Pusher symbols; remove only what survives that, and
re-run the build afterwards. The test suite covers none of this, so a wrong
guess here surfaces as a `ReferenceError` in a host's browser — the same class
of failure the `process.env.NODE_ENV` note in `vite.config.js` records. If
nobody is going to run that build, leave both and move on.

### 8.7 Central and tenant migration duplication

Byte-identical pairs across `database/migrations/central/` and
`database/migrations/tenant/`:

| Pair | Differing lines |
|---|---|
| `create_cache_table` | 0 |
| `create_jobs_table` | 0 |
| `create_permission_tables` | 0 |
| `add_batch_uuid_column_to_activity_log_table` | 0 |
| `create_users_table` | 4 |
| `create_activity_log_table` | 7 |
| `add_event_column_to_activity_log_table` | 2 |

All pre-existing; this branch only renamed three of the central copies to
`2019_09_01_*`.

Two of these must stay duplicated. `create_permission_tables` (113-line `up()`,
both copies) is published from `spatie/laravel-permission`, and the activity-log
migrations from `spatie/laravel-activitylog`. Keeping them byte-identical to
upstream is what makes the next package upgrade a re-publish instead of a
manual merge. Do not extract those.

For the rest, note that a shared helper cannot live in either directory: both
are registered migration paths, so any file dropped there is loaded as a
migration. It would have to go in a normal autoloaded namespace such as
`src/Database/Schema/`, with both migrations calling into it.

**Recommendation: defer, and extract opportunistically.** The payoff is roughly
200 lines across files that are append-only history, and editing already-applied
migrations is only safe here because there are no live installs. Take the
extraction at the moment a schema change forces touching both copies — that is
when the duplication actually costs something, and when the shared shape is
known rather than guessed.

## Phase 9 — findings new to `140b282...1798bab`

Added 2026-09-05 from a two-axis review of the last ten commits. None of these
existed when this plan was written; all were introduced or entrenched by the
config consolidation, the `HostConfig` rebuild, `86ecb9e` and `1798bab`.

### 9.1 `Subscribable`/`HasTenants` widened from `static` to `Model`

`1798bab` changed both interfaces' relation generics to `Model` and added two
private static trampolines, `Billable::subscriptionsRelation(Model)` and
`CentralUser::tenantsRelation(Model)`, whose only job is to launder `$this`
into `Model` so the returned relation matches the declaration.

The reason given in the docblocks is that "an interface cannot declare 'narrows
to whichever class implements this'". That is not right: `@return
MorphMany<Subscription, static>` in an interface is exactly that declaration,
and Larastan resolves it against the implementer. What Larastan actually
objects to is `$this` against `static` under an invariant `TDeclaringModel` on
the *implementation* side.

Cost paid: every host call site loses the declaring-model type, and one
operation is now spread over two methods in two classes with three lines of
docblock explaining why.

Try declaring `static` on both the interface and the implementation and
deleting the trampolines. If a real Larastan error survives that, keep the
widening and rewrite the two docblocks to name the actual constraint, since
the current text will mislead the next person who tries.

### 9.2 A post-charge `RuntimeException` bought for a type narrowing

`CreateInlineSubscription::handle()`:

```php
throw_unless($subscription instanceof Subscription, RuntimeException::class, '…');

$pending->update(['stripe_subscription_id' => $subscription->stripe_id]);
```

The `throw_unless` sits after Cashier has created the subscription in Stripe
and before the reservation records its id. If it ever fires, the customer is
subscribed remotely and nothing local knows the subscription exists — the
worst of the three possible outcomes, and it exists only to satisfy PHPStan.

Narrow without throwing, or move the check above the Stripe call where it
costs nothing. `ModelResolver::resolve()` already guards with `is_subclass_of`,
so a host cannot reach this state through the supported seam; state that and
drop the throw, or assert it at boot.

### 9.3 Two fallbacks for one session value

`Plan::cycle()` reads `billingCycle` with `BillingCycle::tryFrom(…) ?? Monthly`
and documents the reason: "a stale session cannot break the whole wizard".
`Payment::render()` reads the same value out of the same wizard state with
`BillingCycle::from()`, which throws `ValueError` on exactly the input the
other method was written to survive.

Pick one. If the tolerant read is right, the payment step needs it too; if the
strict read is right, `Plan::cycle()`'s docblock is describing protection that
does not hold one step later.

### 9.4 `socialiteproviders/manager` is used but not required

`SocialLoginFeature` now imports `SocialiteProviders\Manager\SocialiteWasCalled`
as a plain `use`, resolved when the class loads. `composer.json` requires
`socialiteproviders/discord` only; the manager is present as its dependency.
Dropping or replacing the Discord provider fatals every boot.

Add `socialiteproviders/manager` to `require` — core names its symbol directly,
so core depends on it.

### 9.5 Four copies of `available()`

`public static function available(): bool { return Features::enabled(self::NAME); }`
is now byte-identical on `PasswordResetFeature`, `SocialLoginFeature`,
`RegistrationWizardFeature` and `OneTimePasswordFeature`. It exists so Blade
can write `RegistrationWizardFeature::available()` instead of
`Features::enabled(RegistrationWizardFeature::NAME)`, which is worth having.

Put it in one trait beside `NamedFeature`, or on a small abstract base. A fifth
feature class will otherwise get a fifth copy, and a sixth will get it wrong.

### 9.6 Comment cap regressions introduced by `86ecb9e`

Phase 6 was scoped to what existed when this plan was written. These are new:

- `database/seeders/RoleAndPermissionSeeder.php` `run()` — 14 prose lines, up
  from 12, carrying three facts (the `central` pin, the lock-wait chain it
  prevents, and the `defaultActions()`-versus-`actionsFor()` split). The middle
  one is a codebase trap and belongs in `.ai/rules/`; the last one describes
  the trait, not this method.
- `database/seeders/Concerns/SeedsAdminRole.php` — 9 prose lines on the trait
  plus a `seedAdminRole()` docblock that sends the reader to
  `RoleAndPermissionSeeder`'s *comment* for the lock-wait explanation. A
  pointer to prose in another file is the cross-reference `general.md` forbids;
  `{@see}` on a symbol is fine, on an explanation it is not.
- `database/factories/Central/SubscriptionFactory.php` — 12 and 9 prose lines.
- `//` runs over cap in files this range edited: `routes/web.php` (13, 4, 4),
  `config/numerosis.php` (5, 4, 4), `database/factories/Tenant/UserFactory.php`
  (8, 4), `database/factories/Central/TenantFactory.php` (8, 5),
  `SeedsAdminRole.php` (6), `SubscriptionFactory.php` (6).

Same handling as Phase 6: run the two `awk` commands from `general.md` (POSIX
classes, not `\s`) over `src/`, `config/`, `routes/`, `database/` and
`packages/` after each batch, and run `validate_preservation.py` on old against
new before each edit lands.

### 9.7 Naming and placement, low value

- `CompleteRedirectCheckout::tenantsMine(string $level, string $message)` —
  `$message` holds a translation key, passed to `__()`. Rename to `$key`.
- `HostConfig::filesystemDisks()`, renamed from `livewireDiskExclusion()`,
  still does one thing: append `livewire` to `tenancy.filesystem.disks`. Its
  docblock still describes only that. The old name was the accurate one.
- `numerosis.tenancy.implementations` now binds `ResolvesLoginCandidate`,
  `AuthenticatesLoginCandidate`, `SendsEmailVerificationNotification` and
  `NotifiesTenantOwner`, none of which are tenancy, through
  `TenancyServiceProvider`. Either move them to a key of their own or say in
  `config/numerosis.php` why the tenancy map is where every non-billing
  contract lives.
- `TestCase::ensureDatabaseExists()` hardcodes
  `mysql:host=127.0.0.1;port=3306`, `root`, `root` — a second copy of the
  `$mysql` array eight lines below it, and this copy issues `CREATE DATABASE`.
  Build both from one array.

### Test

9.1 and 9.2 are covered by the existing suite once changed. For the rest:

- 9.3: a wizard test that puts an unrecognised `billing_cycle` in the session
  and renders both the plan and the payment step.
- 9.4: `composer.json` is the assertion; nothing runtime catches it, because
  the package is installed.
- 9.5: none — a trait extraction with four call sites already under test.

## Phase 10 — findings new to the 2026-09-07 re-audit

Turned up while checking the phases above at `3c3de4e`. Recorded, not fixed.

### 10.1 The workbench app never calls `Numerosis::routes()`

`php artisan route:list` at `3c3de4e` has 61 routes and none of the package's
own central ones: no `home`, no `get-started`, no `billing/webhook`. The only
caller of `Numerosis::routes()` in the repo is `tests/TestCase.php:536`.

So the dev harness `composer serve` boots is not a host that has wired the
package in — it is Fortify, Livewire, Flux and one workbench route. That is
what makes 8.4 a certain 500 rather than a maybe, and it means the harness
cannot be used to check anything route-level by hand.

Decide whether that is intended (the harness exists to boot the package, and a
host wires routes itself) or an omission. If intended, say so in
`workbench/routes/web.php` next to the fix for 8.4, because the next person to
open `composer serve` looking for `/get-started` will lose an hour.

### 10.2 `resources/js/app.js` and `bootstrap.js` are unreachable from the build

`vite.config.js` has one entry, `resources/js/numerosis.js`, which imports
`stripe-checkout.js` and `stripe-confirm.js` and nothing else. `app.js` and
`bootstrap.js` are in neither that graph nor any other.

`bootstrap.js` imports `axios`; `package.json` does not declare it (its
`devDependencies` are `@stripe/stripe-js`, `@tailwindcss/cli`, `playwright`,
`tailwindcss`, `vite`). `app.js` imports `virtual:livewire-hot-reload`. Adding
either to an entry today breaks `npm run build`.

This is the same class as 8.6 and became visible once 8.6's packages were
removed. Either delete both files or declare what they are for; do not leave a
file that fails the build the moment it is used.

## Sequencing

Phases 1 and 2 are independent and both ship user-visible breakage; do them
first, in either order. Phase 3 touches the invitation path that Phase 2's
tests also exercise, so it goes after. Phase 4 is self-contained. Phases 5 and 6
are documentation and can run in parallel with any of them, except 5.2, which
must land with Phase 1 so the rule describes the code that exists.

Phase 7 last, and only 7.1 is worth insisting on.

Phase 8 is independent of all of the above and can run at any point, with two
exceptions. 8.1 should go early — it is a latent break rather than a cleanup,
and the factory-instantiation test it recommends is worth having in place
before the later phases add fixtures. 8.2's `forCheckout()`/`provisioning()`/
`failed()` decision is easier once Phase 3 has settled what the provisioning
path guarantees, so take it after.

Phase 9 slots into the ordering above rather than running as a block. 9.4 goes
first and alone: one line of `composer.json`, and every other phase boots
through the class it protects. 9.6 belongs with Phase 6, since both are the
same sweep over the same files and running them apart means measuring twice.
9.1 and 9.2 are static-analysis debt with no user-visible symptom; take them
whenever those files are open, but re-run `phpstan analyse` cold, because the
whole point of both is what Larastan accepts. 9.3 goes with any wizard work.
9.5 and 9.7 are opportunistic.

Phase 10 is opportunistic. 10.1 should be answered before 8.4 is fixed, since
it decides what the workbench `/` is for.

Ordering aside, take the three open defect phases (2, 3, 4) before any
documentation or cleanup phase. They are the reason this file exists, they are
the ones two previous runs skipped, and 5 and 6 are pleasant work that will
absorb a whole session if allowed to go first.

**Phase 6 has outgrown "documentation".** At the 2026-09-07 re-audit it is 37
docblocks and 30 `//` runs across five directories, not the four files this
plan described, and the `packages/ui` blade components are new scope nobody has
looked at. Budget it as its own session or scope it explicitly to `src/`; do
not let it ride along with a defect phase.

## Verification

Per phase, and once at the end:

```
vendor/bin/pint --dirty --format agent
composer test
composer analyse
```

Pint before Pest, so a formatting edit does not invalidate a green run.

Compare PHPStan cold against cold — a warm result cache hides errors, per
`.ai/rules/static-analysis.md`. The baseline must not grow; a new entry means
something was suppressed rather than fixed.

After any `record-rule` call, diff `.ai/rules/index.md`. It regenerates the
whole table from `paths:` frontmatter and discards the preamble and every row's
note; one call has already cut that file from 82 lines to 9.

End to end on `numerosis-thin-app` for Phase 1, under each of the three
identification modes. That is the only real boot available, and Phase 1 is
precisely a bug that Testbench cannot see.

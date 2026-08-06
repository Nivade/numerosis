# Package extraction — session log

Archival. Every dated status section that used to sit at the top of
`package-extraction.md` lives here, verbatim, oldest first. It is the only
record of the bug classes this extraction turned up, so nothing is deleted —
but nothing here is navigational either. **To find out what to do next, read
`package-extraction.md`; to find out why something is the way it is, read
here.**

Bare commit hashes in these notes refer to whichever repo the surrounding
paragraph names. Hashes with no repo named are saas-m's, which is archived —
see `.claude/rules/INDEX.md`.

New sessions append at the bottom, under a dated heading. Do not edit an
earlier entry; it recorded what was true when it was written, including the
parts that later turned out to be wrong.

---

## Phases 0-5 — status as written at the time
**Status 2026-08-04:** Phase 0 ✅ complete. Phase 1: steps 1.1-1.7 ✅ done
(verified + two bugs fixed: 1.5 dropped the `tenant-registration` Livewire
component; 1.4's `tenant_pattern` default parsed `APP_URL`'s host, which
includes the central subdomain, instead of matching `env('DOMAIN')`). 1.7
split `config/billing.php` into `config/numerosis-tenancy.php`
(provisioning steps, `TenantDomainPolicy`/`ProvisionsTenant` bindings, bound
from `TenancyServiceProvider::register()`) and `config/numerosis-billing.php`
(everything else, bound from `BillingServiceProvider::register()` as
before); `config/billing.php` deleted. 1.8 wrote
`~/repos/private/numerosis/DEPENDENCIES.md` (all 31 saas-m `require` entries
triaged, each with exactly one verdict — verified programmatically). **Phase
1 complete.**

Phase 2: 2.1 done —
`App\Features\Ui\{AdminPanel,TenantPanel}Feature` gate their panel
providers' `register()` (skip `Filament::registerPanel()`, since
bootstrap/providers.php can't read config that early — provider's own
`register()` can); `App\Features\Tenancy\MembershipsFeature` gates
`UserResource::canAccess()`/`shouldRegisterNavigation()`, same
directory-discovery trap as `ModuleResource`/`Marketplace`. All three
registered in `config('numerosis.features')`.

2.2 done, following the existing `tests/Feature/Features/*FeatureTest.php` +
`*DisabledTest.php` pair convention (14 files already there) instead of the
plan's single `FeatureIsolationTest.php` — codebase already had the
per-feature-pair pattern established, so matched it rather than introducing
a second style. Added: `AdminPanelFeatureTest`/`AdminPanelDisabledTest`
(assert `'admin'` key present/absent in `Filament::getPanels()` —
`FilamentManager` has no `hasPanel()`), `TenantPanelFeatureTest`/
`TenantPanelDisabledTest` (same, `'tenantAdmin'` key), `MembershipsFeatureTest`/
`MembershipsDisabledTest` (`UserResource::canAccess()`, gated by `UserPolicy`
having no `viewAny` — test user needs `viewAny users` permission granted via
`Permission::firstOrCreate(['name' => 'viewAny users', 'guard_name' =>
'tenant'])`, same pattern as `MarketplaceTest`). Disabled-test for Memberships
force-enables `TenantPanelFeature` alongside disabling Memberships — forcing
`[]` entirely made `actingAsTenantPanelUser()` throw before reaching the
assertion, since `Filament::getPanel('tenantAdmin')` requires the panel
feature on. All 6 new tests pass; Pint clean; PHPStan unchanged (3
pre-existing errors, none in new files). **Phase 2 complete.**

`~/repos/private/thin-app` does not exist yet.

**Phase 3, 2026-08-05:** 3.1 done — `App\Support\Numerosis` gained
`routes()`, `broadcasting()`, `csrfExceptions()`, `middleware(Middleware
$middleware)`, bodies moved verbatim from `bootstrap/app.php`. `bootstrap/app.php`
now calls all four; `withMiddleware()` still calls
`$middleware->preventRequestForgery(except: Numerosis::csrfExceptions())`
itself rather than folding that call into `Numerosis::middleware()`, per the
plan's own reasoning (except-list is the one piece a consumer is likely to
extend). Pint clean; PHPStan unchanged (3 pre-existing errors, none touching
either changed file).

**Full-suite check found the plan's step-done criterion stale, not my
change:** working tree already carried a large uncommitted diff *before*
Phase 3 started (see `git status` — billing/tenancy renames, feature classes,
config split), so "still 9 failures" (0.4's clean-master baseline) was never
the right comparison here. Full run showed **22 failed, 1 skipped, 409
passed**. Isolated by `git stash push -- app/Support/Numerosis.php
bootstrap/app.php` and re-running the failing subset
(`RegistrationWizardDisabledTest`, `CentralModelPolicyResolutionTest`,
`ConnectSocialAccountTest`, `RecordSubscriptionTest`, `ActivityLogDisabledTest`)
— identical 8 failed/7 passed with Phase 3's two files reverted, so none of
the 22 trace to this step; stash popped, Phase 3 changes restored.
**Dug into and fixed, 2026-08-05.** All 22 traced to five root causes, none
caused by Phase 1-3 work — every failing file was unmodified against `HEAD`
(`ddd7c35`), so this was pre-existing breakage on `master` itself that had
just never been measured with a full run since landing:

1. **`assertDatabaseHas()` blind spot on `central`-connection tables (4
   failures, `ConnectSocialAccountTest` + `RecordSubscriptionTest`).** Models
   using `CentralConnection` commit immediately on a different named
   connection than the test's `RefreshDatabase` transaction (default
   connection), and MySQL's REPEATABLE-READ snapshot on that transaction
   predates the commit — "table is empty" is a snapshot artifact, not a
   missing row. Fixed by passing `'central'` as the explicit connection arg.
   Not documented anywhere before this — added as a new case to
   `.claude/rules/testing.md` (TODO: not yet written — see follow-up below).
2. **Test bug (1 failure, `RegistrationWizardDisabledTest`).** `Features::
   forceForTesting([])` disabled every feature including `MarketingPagesFeature`,
   which gates the `features` route the same test asserts still renders.
   Fixed: force everything except `RegistrationWizardFeature` explicitly.
3. **Test contradicted a documented, deliberately-deferred gap (1 failure,
   `CentralModelPolicyResolutionTest`).** Asserted `Gate::getPolicyFor(CentralUser::class)`
   resolves `UserPolicy` — but `auth-guards.md` records this as accepted,
   unresolved (moving `#[UsePolicy]` onto the shared base needs a real
   decision, not a silent slip-in while fixing tests). Fixed the test to
   assert the current, documented behavior (`assertNull`) instead of
   implementing the deferred fix myself.
4. **Real app bug, not a test bug (14 failures, `ChatPanelTest` +
   `ChannelViewTest`).** `ChatServiceProvider::boot()` calls `Livewire::
   addComponent(name:, viewPath:, class:)` with both `viewPath` and `class`
   set — but `Livewire\Finder\Finder::addComponent()` only stores one of the
   two (`if ($class !== null) {...} elseif ($viewPath !== null) {...}`), so
   `viewPath` is silently dropped whenever `class` is also given. Livewire
   then falls back to its own naming convention, which resolves against the
   host app's `resources/views/`, not the module's — `FileNotFoundException`,
   not a missing-registration error. (The test files' use of a `⚡` SFC marker
   in the component name turned out to be a red herring — Livewire's `Finder`
   already strips that character before building the lookup path, so it
   never was the mismatch.) Fixed: added an explicit `render(): View` to
   `ChatPanel`, `ChannelView`, `StatusPicker` returning `view('chat::livewire.*')`
   — the `chat::` namespace is already auto-registered by `internachi/modular`
   per-module, confirmed via `view()->exists('chat::filament.topbar-button')`.
   Trimmed the now-dead `viewPath:` args from `ChatServiceProvider::boot()`.
5. **Same bug as #2, surfaced later (1 failure, `ActivityLogDisabledTest`,
   masked by lock-wait noise in the first full run).** Same `forceForTesting([])`
   blast-radius trap — killed `TenantPanelFeature` too, so `Filament::
   getPanel('tenantAdmin')` returned null before the plugin check ran. Fixed
   the same way: force everything except `ActivityLogFeature`.

All five fixes verified individually, then Pint (clean) + PHPStan (same 3
pre-existing errors, none in touched files) + full suite:
**1 failed, 1 skipped, 430 passed** — one remaining `SQLSTATE 1205` lock-wait
failure, `CentralModelPolicyResolutionTest::test_a_central_user_without_permissions…`.

**That one turned out fixable too, not just "known-flaky."** Root cause,
specific to this test: `RoleAndPermissionSeeder` wrote `Role`/`Permission`
rows via the *default* connection (ambient, no override) inside
`RefreshDatabase`'s open, uncommitted transaction; `PromoteFirstCentralUserToAdmin`
then called `$user->assignRole()` on a `CentralUser`, whose `model_has_roles`
pivot insert goes through `CentralUser`'s own `central` connection — and that
insert's FK check needs a shared lock on the very `roles` row the default
connection was still sitting on, uncommitted, for the rest of the test.
Guaranteed block every run, not timing luck. Fixed by pinning
`RoleAndPermissionSeeder`'s writes to the `central` connection explicitly
(`Role::on($central)`/`Permission::on($central)`) — safe specifically because
this seeder only ever writes `guard_name = 'web'` rows, which
`auth-guards.md` establishes as central-guard by definition; `Role`/`Permission`
themselves stay ambient-connection (still used in tenant context via
`SpatiePermissionsBootstrapper`), so nothing broader changed. Seeding via
`central` commits immediately (autocommit) — nothing left to block on.
`TestCase::recordCentralWrites()`/`deleteCentralWrites()` already tracks and
cleans up *any* table written via the central connection generically, so this
didn't need new teardown wiring.

Fixing the lock cleared the way to a **second, previously-masked bug** in the
same test: `test_a_central_user_without_permissions_cannot_manage_users_roles_or_permissions`
created exactly one `CentralUser`, and `CentralUserObserver::created()` →
`PromoteFirstCentralUserToAdmin` auto-promotes whichever `CentralUser` is the
*only* row in the central `users` table to the (now fully-permissioned)
`admin` role — so the test's own "user" was actually being granted every
permission it meant to assert the absence of. The failure had always just
been swallowed by the lock-wait timeout before reaching that assertion.
Fixed the same way `PromoteFirstCentralUserToAdminTest` already does it: a
decoy `CentralUser::factory()->create()` before the one under test, so the
real subject is never "the first" central user.

Verified with the specific test (4/4 green), then Pint (clean) + PHPStan
(same 3 pre-existing errors) + one more full run: **0 failed, 1 skipped, 431
passed.** Tree is genuinely clean now — not "known-failures accepted," fully
green. This is a stronger baseline than 0.4's documented "9 failures" ever
was; worth updating that section once this work is committed.

Both bug classes now written into `.claude/rules/testing.md` — (1) the
`assertDatabaseHas()`-on-`central`-tables snapshot-isolation trap, distinct
from the teardown-only central-connection issue already documented there,
and (2) the cross-connection seeding-vs-FK-check lock (pin seeding side's
connection to match the writing side's), in case the general ~9-site class
resurfaces elsewhere.

3.2 done — `~/repos/private/numerosis/docs/host-requirements.md` written:
per-file table (`config/tenancy.php`, `database.php`, `auth.php`,
`session.php`, `filesystems.php`+`livewire.php`, `cashier`/`permission`/
`broadcasting`) of exactly which keys the host must own and why, reasons
copied from the relevant `.claude/rules/*.md` files. **Phase 3 complete.**

The 22-failure dirty tree is also resolved now (see above) — full suite is
**0 failed, 1 skipped, 431 passed**, genuinely green, not just down to the
accepted lock-wait baseline. Rules write-up done. Next: commit Phase 1-3
work, then Phase 4 (freeze, copy, rename) — 4.0's precondition (clean tree,
suite green) is now met.

**Phase 4 started 2026-08-05.** saas-m frozen as of commit `c66cc72`
(numerosis Phase-0 skeleton config committed separately, `676a702`). From
this point saas-m is read-only per 4.1 — no further feature work or fixes
land here; anything needed goes into numerosis or thin-app instead.

**Phase 4 done, 2026-08-05 — `numerosis@71dd502`.** 4.2 copied per the
table: `app/*` (minus `AppServiceProvider`/`TelescopeServiceProvider`/
`Filament/{AdminPanel,TenantAdminPanel}Provider`, which stay for thin-app
in Phase 7) into `src/`; `routes/{web,auth,tenant,channels,console}.php`;
central migrations (64) and tenant migrations (25); the four root seeders
plus `Central/`/`Tenant/` seeder subdirs; all factories; `resources/views`
(components/filament/flux/layouts/livewire/pages/partials + the five
top-level marketing blades, not called out in the table but needed by
`MarketingPagesFeature`'s routes); `resources/{css,js}`; `lang/en`+
`lang/vendor` → `resources/lang`; `tests/{Feature,Unit,Concerns,Support}`
plus `TestCase.php`/`Pest.php` over the skeleton's stand-ins; `.claude/`,
`CLAUDE.md`, `AGENTS.md`, `pint.json`, `rector.php`, `boost.json`; the
three `config/numerosis*.php` files.

4.3's sed example only covers `App\` — three more rewrites turned out to
be required and are now part of this step for next time: `Database\
Factories\`/`Database\Seeders\` (factories/seeders don't declare
`namespace App\...`, they declare `namespace Database\Factories...`,
untouched by the `App\` patterns) and `Tests\` (all 131 copied test files
declare `namespace Tests\...`, which doesn't autoload under this
package's `Nvade\Numerosis\Tests\` → `tests/` PSR-4 mapping at all — this
wasn't a "some tests fail to resolve a class," it was "every copied test
file is unreachable by the autoloader"). Two sed bugs surfaced and got
fixed while doing this: an over-escaped backslash pattern that matched
zero fully-qualified `\App\Foo` references, and a set of six chained `-e`
substitutions each re-matching the previous one's output, doubling the
prefix to `Nvade\Numerosis\Nvade\Numerosis\...` on `use`/`namespace`
lines that happened to hit more than one rule. Both caught by grepping
for the broken pattern afterward, not by re-reading the sed by eye — that
grep (`grep -rln 'Nvade\\Numerosis\\Nvade' ...`) is the thing to run again
if this rewrite is ever repeated.

Verification used here, since PHPStan can't fully type-check yet
(`stancl/tenancy`, `filament/filament`, `spatie/laravel-data`,
`stripe/stripe-php` aren't in numerosis's `composer.json` `require` until
Phase 5 — every class touching tenancy/Filament/billing throws
`class.notFound`/`trait.notFound` right now, expected and not a rewrite
bug): `php -l` across every copied file (src, tests, database, routes —
zero syntax errors) confirmed the sed passes didn't corrupt anything, and
a directory-by-directory PHPStan pass on the three dependency-free leaves
(`Contracts`, `Enums`, `Data`) confirmed the namespace rewrite itself
resolves correctly once framework classes are available. **PHPStan's
`turbo-ext` shared library doesn't load in this environment** (wrong
arch/PHP-build combo, `dlopen` fails) — every run needs `--debug` to
force single-process mode, or it exits 255 with a swallowed native
extension warning instead of a normal PHPStan error list.

`tests/TestCase.php` needed a real merge, not a straight overwrite: the
skeleton's version had Testbench's `getPackageProviders()` and a
`Factory::guessFactoryNamesUsing()` stub keyed on `class_basename()`
only (loses `Tenant\`/`Central\` sub-namespaces — see 4.3's own factory
note below); saas-m's version has none of that but carries the
`RefreshDatabase` teardown logic every copied test depends on
(`.claude/rules/testing.md`'s central-write cleanup, tenant-database
drop, connection purging). Copying either alone breaks the other's
tests. Merged: Testbench's `getPackageProviders()` plus a
`factoryNameFor()` resolver that strips the `Nvade\Numerosis\Models\`
prefix and keeps the remainder (so `Tenant\User` → `Tenant\UserFactory`,
matching `composer.json`'s new `Nvade\Numerosis\Database\Seeders\` PSR-4
entry, added alongside the existing `Database\Factories\` one), grafted
onto saas-m's teardown methods unchanged. `composer.json`'s `/docs`
`.gitignore` entry was silently blocking `host-requirements.md` (written
back in 3.2) from ever being committed — removed.

**Deliberately not attempted this phase:** getting numerosis's own test
suite green, or clearing the `class.notFound`/`trait.notFound` PHPStan
noise from missing tenancy/Filament/billing dependencies. Both need
`composer.json` `require` entries and test-environment config
(database connections, tenancy config) that don't exist in the package
yet — that's Phase 5 ("wire package") and Phase 6 ("harness"), not a
loose end from Phase 4's copy-and-rename scope.

**Phase 5 done, 2026-08-05.** Also did 4.4 first (models: abstract base +
stubs), skipped in Phase 4 despite being numbered under it — 5.1's
`numerosis-models`/`numerosis-stubs` publish groups have nothing to publish
without it. All 9 models (`Tenant`, `Domain`, `CentralUser`, `Subscription`,
`PaymentPlan`, `Tenant\Invitation`, `Tenant\Module`, `PendingTenantProvision`,
`Tenant\User`) now `abstract`; `numerosis/stubs/Models/{Central,Tenant}/*.stub`
holds the one-line concrete subclass for each, re-declaring `#[UsePolicy]` on
the three that carry it (`Invitation`, `Module`, `Tenant\User`) since PHP
attributes aren't inherited — the exact trap `auth-guards.md` already
documents for `#[UsePolicy]`, confirmed to also apply to `#[UseFactory]`
(see below). Config's `tenancy.tenant_model` etc. pointing at these concrete
classes is thin-app's job (Phase 7); nothing to point them at yet.

5.1: `NumerosisServiceProvider::configurePackage()` now declares config
files, views, translations, central-only discovered migrations
(`discoversMigrations(true, '/database/migrations/central')`,
auto-tagged `numerosis-migrations` by Spatie's own `{shortName}-migrations`
convention — matches the plan's required tag name for free), and both
commands. `packageRegistered()` registers `TenancyServiceProvider`/
`BillingServiceProvider` (each still merges its own
`config/numerosis-{tenancy,billing}.php`, unchanged). `packageBooted()`
runs the feature-bootstrap loop (moved from `AppServiceProvider` per the
plan — nothing else from that provider moved, matching 4.2's own "keeping
only app-specific bindings" line literally) and publishes tenant
migrations/assets/model-stubs via a small `PublishesPackageAssets` trait
(one of 5.3's requested traits — see below).

**Real, non-test-only factory-resolution gap found and fixed while wiring
this, not just a testing concern:** Laravel's default `Factory::
resolveFactoryName()` rebuilds the factory class under the *model's own*
root namespace — for a thin-app stub (`App\Models\Central\Tenant`) that
guesses `App\Database\Factories\Central\TenantFactory`, which doesn't
exist; the real factory lives in this package. This isn't limited to the
3 models that already carried `#[UseFactory]` (`Subscription`,
`SubscriptionItem`, `PendingTenantProvision`) — every one of the 9 stub
models hits it, since attributes on the abstract parent don't inherit to
the stub either. Fixed with one shared resolver,
`Numerosis::factoryNameFor()`, registered globally in
`NumerosisServiceProvider::packageBooted()` via `Factory::
guessFactoryNamesUsing()` — not just in `Tests\TestCase` (which used to
carry its own private copy; now calls the same method, so the two can't
drift the way `.claude/rules/testing.md`'s cache-key note warns about).
Confirmed via PHPStan that the existing `#[UseFactory]` attributes on
`Subscription`/`PendingTenantProvision` don't need re-adding to their
stubs: the global resolver computes the identical factory class either
way, so the attribute would be redundant, not required.

5.2: `numerosis:install` publishes config+model stubs, appends 6 missing
`.env` keys (`DOMAIN`, `CENTRAL_SUBDOMAIN`, `SESSION_DOMAIN`, `STRIPE_KEY`,
`STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` — never overwrites one already
set), then verifies every row `docs/host-requirements.md` documents
(central/tenant connections present, `session.domain` leading-dot,
both context guards resolve, `livewire` absent from
`tenancy.filesystem.disks`, `tenancy.migration_parameters['--path']`
absolute and existing, Stripe keys set) — fails loudly (non-zero exit,
one line per problem) rather than printing and moving on. Prints the
three manual steps (wildcard DNS, Filament plugin registration, dedicated
`provisioning` queue worker) only once verification passes.

5.3: added the 5 missing contracts (`Tenancy\TenantDatabaseManager`,
`Invitations\InvitationRepository`, `Modules\ModuleRegistry`,
`Auth\SocialAccountRepository`, `Notifications\NotifiesTenantOwner`) plus
default Eloquent-backed implementations, bound via
`numerosis-tenancy.implementations` alongside the two contracts already
wired there — same swap-by-config pattern as the existing 8
`Contracts\Billing\*`. `EloquentModuleRegistry` wraps
`InteractsWithTenantModules` rather than duplicating its request-memo +
pre-bootstrap-fallback-connection logic. **Existing call sites
(`Livewire\Invitations\Accept`, `CheckInvitationStatus`,
`Http\Controllers\Socialite\Login`, `ProvisionTenant`, the 4 notification
listeners) still query directly — not rewired to the new contracts this
pass.** The plan's own wording is "add," not "refactor callers," and
rewiring live, tested, business-critical call sites with numerosis's own
test harness not built yet (Phase 6) is exactly the kind of change that
needs a suite to catch a mistake, not a blind edit — follow-up, explicitly
deferred, not forgotten.

`Billable` and `TagsSentryScopeWithTenant` (2 of the 7 requested traits)
already shipped correctly from Phase 4, unchanged. Wrote all 6 of the
rest (`IsTenantModel`, `IsCentralUser`, `IsTenantUser`, `HasGlobalIdentity`,
`BelongsToTenant`, `HasTenants`) as standalone extractions of logic
duplicated across `CentralUser`/`Tenant\User` today, then **deleted all
six**: PHPStan (level 9, this package's own `paths`) reports "trait used
zero times, not analysed" for any trait composed nowhere, and
`phpstan-baseline.neon` is empty — there is no pre-existing-violation
context to grandfather a brand-new, self-authored error into, which is
what the baseline is *for* per `.claude/rules/static-analysis.md`.
Composing them into the 9 live models is the same "needs Phase 6's test
harness to verify safely" reasoning as the contracts above, just for
model internals instead of call sites — redo them then, verified by that
suite. `PublishesPackageAssets` (the 7th trait) shipped and is used
immediately by `NumerosisServiceProvider` itself, so it hit no such
problem. No `Chat\*` contracts added, per the plan.

Verified throughout via `php -l` (zero syntax errors) and PHPStan
`--debug` (the broken `turbo-ext` shared library from Phase 4 still
applies) on every touched file: zero new errors anywhere touched this
phase; the handful of pre-existing `class.notFound`/`generics.notSubtype`
errors in untouched `Contracts/Billing/Plan.php`,
`Contracts/Subscribable.php`, `Contracts/Tenancy/{HasTenants,
ModulePlugin}.php` are the same missing-dependency class from Phase 4
(Filament, mainly), not new. Pint clean. **Phase 5 complete.**

---

## Phase 6, first session — PAUSED mid-sweep
### Phase 6 status — PAUSED mid-session, 2026-08-05

Harness itself works now (MySQL, Testbench package discovery, activitylog
config, factory model-name resolution) — got here through several rounds of
real bugs, not just config gaps. `tests/Feature/Models` reached 11/11 passing
before a second, much bigger problem surfaced.

**Core discovery: the 9 abstract package models (Phase 4.4) are broken in
production, not just under test.** Any call written literally against one of
them — `Tenant::find(...)`, `CentralUser::create(...)`, `PaymentPlan::query()`,
etc — throws `Cannot instantiate abstract class`, because Eloquent's own
static methods do `new static`, and late static binding resolves `static` to
the literal class the call was written against, not to any host-configured
concrete class. This was never caught before because no test in the package's
own suite exercised these code paths until this session started writing them
— saas-m's `Tenant` etc were concrete before the Phase 4.4 split, so the old
suite never hit it either.

**Fix in progress, not finished.** Added `Nvade\Numerosis\Support\Numerosis::model(string $abstractClass): string`
(in `src/Support/Numerosis.php`) — resolves the abstract model to its
host-stub concrete class (`Nvade\Numerosis\Models\Central\Tenant` →
`App\Models\Central\Tenant`), same convention `modelNameFor()`'s fallback
already used for factories. Every literal static call on one of the 9
abstract models in `src/` must route through it:
`Numerosis::model(Tenant::class)::query()...` (or resolve to a local variable
first, both patterns used interchangeably so far — cosmetic, not a bug).

Grep for the full call-site list (re-run before resuming, this may drift):
```
for m in Tenant CentralUser Subscription PaymentPlan PendingTenantProvision Invitation Module Domain; do
  grep -rnE "\b${m}::(query|create|forceCreate|find|findOrFail|where|firstOrCreate|firstOrNew|make|withoutEvents|updateOrCreate|each)\(" src
done
grep -rnE "\bUser::(query|create|forceCreate|find|findOrFail|where|firstOrCreate|firstOrNew|make|withoutEvents|updateOrCreate)\(" src | grep -v CentralUser::
```

Progress against that list (task tracker in-session had 10 tasks, 1 per
model + 1 final sweep):

- ✅ **done**: `Tenant`, `Domain`, `CentralUser`, `Subscription`, `PaymentPlan`
- 🔶 **`PendingTenantProvision` partially done** — fixed: `InlineCheckoutGateway`,
  `MarkProvisionFailed`, `ReserveTenantDomain`, `ResolveSetupIntent`,
  `ResumeCheckout`, `WebhookController` (all 3 sites in it),
  `PruneStalledTenantProvisions` (both sites), `MarkTenantProvisioned`.
  **Still open**: `Livewire/Billing/Checkout.php` (3 sites — lines were
  ~191/258/276 pre-edit), `MarkProvisionInProgress.php` (1 site),
  `MarkProvisionCancelled.php` (1 site).
- ⬜ **`Invitation` not started** — `EloquentInvitationRepository.php` (3
  sites), `CheckInvitationStatus.php` (1 site) still open. (`Invitation::`
  sites inside `Livewire/Invitations/Accept.php` and
  `Http/Controllers/Socialite/Login.php` were already fixed as a side effect
  of the `CentralUser`/`Tenant` pass through those same files — don't
  re-fix.)
- ⬜ **`Module` not started at all** — `RecordModulePurchase`, `CancelModule`,
  `SynchronizeModules`, `PurchaseModule`, `ReconcileModuleSubscriptionItems`,
  `MigrateModules`, `InteractsWithTenantModules`, `Filament/TenantAdmin/Pages/Modules/ModuleDetail.php`,
  `Filament/TenantAdmin/Resources/Modules/ModuleResource.php`.
- 🔶 **`Tenant\User` mostly done as a side effect** (`AddTenantOwner`,
  `AcceptInvitation`, `Livewire/Invitations/Accept`) — **still open**:
  `PromoteFirstUserToAdmin.php`, `Filament/TenantAdmin/Resources/Invitations/Pages/CreateInvitation.php`.
  Also re-run the broader grep above before trusting this is the full list —
  the first pass only searched a few directories.
- ⬜ **Final sweep not started**: re-run all the greps above, confirm zero
  hits outside a small documented allowlist (type-hints/docblocks are fine —
  only the *call* is the bug), then re-run `tests/Feature/Models` and widen
  outward (`tests/Feature/Actions`, `Livewire`, `Filament`, `Http`, `Jobs`,
  `Billing`) — full suite (~130 files) not run since the harness came up.

**Resume here first.** Don't restart from Phase 6.1 — the harness works.
Pick up the model-by-model sweep above where it stopped (`PendingTenantProvision`
remainder → `Invitation` → `Module` → `Tenant\User` remainder → final sweep +
full pest run), then write the real Phase 6 status section (bugs found,
fixed, pass/fail counts) once green, replacing this pause note.

---

## Phase 6, model sweep finished
### Phase 6 status — model-sweep finished 2026-08-05, harness gaps remain

**The abstract-model instantiation bug (Phase 6's original target) is fully
resolved.** Zero `Cannot instantiate abstract class` errors anywhere in the
package — confirmed via a full-suite run (400 tests, `php -d
memory_limit=1G vendor/bin/pest --compact`, default 128M exhausts on
`ArchTest`'s AST scan). Finished the model-by-model sweep exactly where the
pause note left it (`PendingTenantProvision` remainder in `Checkout.php`/
`MarkProvisionInProgress`/`MarkProvisionCancelled`, `Invitation` in
`EloquentInvitationRepository`/`CheckInvitationStatus`, `Module` across 9
files, `Tenant\User` remainder in `PromoteFirstUserToAdmin`/
`CreateInvitation`), then a final sweep pass — twice, because the first
pass's grep (the exact method list the earlier pause note wrote down) was
itself incomplete:

1. **The enumerated-method grep can never be complete — Eloquent's
   `__callStatic` proxies *any* Builder method.** `PaymentPlan::available()`,
   `Subscription::select(...)`, `Tenant::firstWhere(...)`,
   `PaymentPlan::firstWhere(...)`, `Module::on(...)` all slipped through the
   original list (`query|create|find|where|...`) because none of those verbs
   were in it. Fixed by re-running the sweep twice more with a widened
   pattern (`\b${m}::[a-zA-Z_]+\(`, filtering only `::class` references and
   comments) instead of an enumerated list — that pattern is exhaustive by
   construction and is what should be reused if this class of bug
   resurfaces. Caught 2 more real sites (`InferTenantFromCurrentRequest`,
   `PaymentPlan::popular()`, `LinkSubscriptionToTenant`) and one in a
   Livewire registration step (`Plan.php`/`Payment.php` calling
   `PaymentPlan::available()`) the method-list pass had missed entirely.

2. **A second, structurally different source of the same bug: relationship
   definitions (`belongsTo`/`belongsToMany`/`morphMany`) passing a literal
   abstract class name.** `Eloquent\Concerns\HasRelationships::
   newRelatedInstance()` does `new $class` using the class name *given to
   the relation method*, not late static binding — so
   `$this->belongsToMany(Tenant::class, ...)` inside `CentralUser` fails
   exactly like a static call would, but no grep for `X::method(` ever
   finds it, because there's no `::` at all. Found by running the actual
   test suite, not by grepping harder: `tests/Feature/Models` surfaced
   `CentralUser::tenants()`, `Tenant::users()`, `Subscription::paymentPlan()`,
   `Feature::paymentPlans()`, and three relations on the non-abstract
   `Membership`/`PaymentPlanFeature`/`SocialiteLogin` pivot/join models that
   point *at* an abstract model. Every one fixed the same way: wrap the
   related-class argument in `Numerosis::model(X::class)`, same helper the
   static-call sites already use. **Grep is not a sufficient verification
   method for this bug class — relation definitions and vendor code that
   receives a model class string (see next point) both need to be checked
   by running code, not by pattern-matching source.**

3. **A third source, one level deeper: package code that hands a literal
   abstract class name to *vendor* code, which then does the instantiation
   itself, far from any file this package controls.** stancl's
   `UpdateSyncedResource` listener (`vendor/stancl/tenancy/src/Listeners/
   UpdateSyncedResource.php`) calls `$event->model->getCentralModelName()::
   where(...)`/`::create(...)`/`::withoutEvents(...)` — the class string
   comes from `Tenant\User::getCentralModelName()`, which returned
   `CentralUser::class` (abstract) literally, and symmetrically
   `CentralUser::getTenantModelName()` returned `Workspace\User::class`
   (also abstract; `Workspace` is a pre-existing namespace-collision
   alias — `CentralUser.php` already imports the shared abstract
   `Nvade\Numerosis\Models\User` as bare `User`, so `Tenant\User` is
   imported as `Nvade\Numerosis\Models\Tenant as Workspace` instead and
   referenced as `Workspace\User`). Same fix, `Numerosis::model(...)`
   wrapping the return value — `getCentralModelName()` itself (which
   returns `static::class`, i.e. reads `$this`'s own class rather than
   instantiating anything) needed no change, only the *other* direction.
   The general lesson: any package method whose contract is "return a
   model class name for someone else to instantiate" is a instantiation
   site by proxy and must route through `Numerosis::model()`, even if the
   method itself never writes `new` or `::`.

4. **Same lesson recurred in test files, at even larger scale: ~100 test
   files imported the *package's* abstract model classes
   (`Nvade\Numerosis\Models\Central\Tenant` etc.) instead of the Workbench
   concrete stubs (`App\Models\Central\Tenant`) that every passing test
   already used.** This wasn't a regression from this session's work — the
   whole test suite was written before Phase 4.4 made these 9 models
   abstract and was never updated. Confirmed via
   `grep -rlE '^use Nvade\\Numerosis\\Models\\...'  tests` (100 hits, no
   collisions with an existing `App\Models\...` import in the same file)
   and fixed with a single batch `sed` pass swapping the `use` line's
   namespace prefix, leaving 3 files deliberately untouched:
   `tests/TestCase.php` and `tests/Support/CloneTenantSchema.php` (test
   infrastructure, not ordinary tests — need individual review, not done
   this pass) and `tests/Feature/Models/Central/
   CentralModelPolicyResolutionTest.php` (asserts
   `Gate::getPolicyFor(CentralUser::class)` — needs a decision on which
   class the assertion should target, not a mechanical swap).

`tests/Feature/Models`: **11/11 passing** (was 9/11 mid-sweep, 2 failures
traced to the test importing the abstract model directly — same bug as
point 4 above, fixed the same way). `tests/Feature/Actions`: went from
86 failed/20 passed (before this session's fixes) to 58 failed/48 passed
after — every remaining failure re-checked and confirmed to be **not** an
abstract-instantiation error (see next paragraph).

**Full suite (400 tests, all directories): 139 passed, 259 failed, 1 risky,
1 skipped. Zero of the 259 are `Cannot instantiate abstract class`** —
confirmed by grepping the full run's output for that exact string. The
remaining failures are a different, larger body of work: the package's
Testbench harness doesn't yet register a default Filament panel (34
failures), doesn't fake Stripe (28, `Invalid API Key ... sk_test_*ummy`),
is missing artisan commands the tests invoke directly
(`tenants:migrate-module`, `tenancy:prune-stalled-provisions`,
`tenancy:prune-orphaned-databases` — 26 combined), is missing views/Blade
components for features whose Livewire components register views by
package-view-name rather than by class (`livewire.auth.passwordless-login.*`,
`livewire.billing.checkout`, `pages::tenant.mine`, `billing.plan-card`,
`layouts::header`), is missing `numerosis-billing.plans.*.monthly_id` /
`database.lock_wait_timeout` config values, and is missing the two
suggest-only packages (`spatie/laravel-livewire-wizard`,
`ryangjchandler/laravel-cloudflare-turnstile`) that several Livewire/auth
tests exercise directly rather than through a `class_exists()` guard. None
of this is Phase 6.1's "MySQL, Testbench discovery, activitylog config"
scope (already done) or this session's abstract-model scope (also done)
— it's the remaining part of "port the whole test-support layer" (6.2) and
building out the harness to cover routes/panels/config the way thin-app
eventually will. **Next session: pick one category at a time (panel
registration first — 34 failures, likely one `NumerosisServiceProvider`
or Workbench config fix), not all of them at once.**

Verified throughout: `php -l` on every touched file (zero syntax errors),
Pint (`--dirty`, clean — only import-ordering/spacing fixes across ~120
files), PHPStan (`--debug --memory-limit=1G`, since default 128M crashes
mid-run) dropped from 430 to 376 identifier-tagged findings over the
course of this session, purely from making `Numerosis::model()` generic
(`@template TModel of Model`) instead of hand-annotating each call site's
return type with `@var` — the generic fix alone resolved ~370 of the
~395 narrowing-loss errors the abstract-call conversion had introduced;
the 2 that remain (`Contracts\Tenancy\HasTenants`,
`Contracts\Subscribable`) are the same `generics.notSubtype` gap section
1.3 already documents as a deliberate non-fix. Remaining PHPStan noise
is the pre-existing missing-suggest-package class.notFound class (same
packages as the harness gap above) plus a handful of Cashier/Filament
framework-level items untouched by this session.

---

## Phase 6, panel registration + view-namespace bug
### Phase 6 status — panel registration fixed, view-namespace bug found and fixed, 2026-08-05

Picked up exactly where the previous status note said to: panel registration
first. Turned out to require the whole harness-gap list from that note to be
touched, not just `NumerosisServiceProvider`/Workbench config, plus one
systemic bug the earlier sweeps never had reason to find.

**Panel registration (the 34 `NoDefaultPanelSetException` failures).**
Nothing in the package ever registered a Filament panel for tests to render
against — `Filament\Panel::default()`/`->id('tenantAdmin')` etc are
thin-app's job (Phase 7/8), and no stand-in existed yet. Added two
Workbench-only classes, `workbench/app/Providers/Filament/
{Admin,TenantAdmin}PanelProvider.php`, deliberately **not** full parity with
saas-m's originals — no `openplain/filament-shadcn-theme`, no per-module
plugin loop (`config('modules.plugins')` doesn't exist in the package at
all, modules being app-side) — just enough to exercise the package's own
Filament resources/pages. Wired into `tests/TestCase::getPackageProviders()`
alongside `NumerosisServiceProvider`. Path to each panel's resource/page/
widget directories is computed as `dirname(__DIR__, 4).'/src/Filament/...'`
from the Workbench file itself, not `base_path()` or `app_path()` — those
resolve against Testbench's synced skeleton app, not this repo.

`TenantAdminPanelProvider::register()`'s central-domain gate used
`request()->isCentralDomain()`, a macro `AppServiceProvider` registers in
saas-m — but `AppServiceProvider` stays in thin-app (4.2's own table), so
the macro doesn't exist in the package at all. Inlined the same check
(`in_array($host, Config::array('tenancy.central_domains'))`) rather than
register a macro from test code — thin-app's real `AppServiceProvider` is
what should own it, same as saas-m.

**Real dependency-installation gap: four `suggest`-only packages had to
become `require-dev`, not just get a `class_exists()` guard.** Directory
discovery (`discoverResources()` etc) parses and autoloads *every* file
under a discovered path regardless of any feature flag — `ActivityResource`
extends `AlizHarb\ActivityLog\Resources\ActivityLogs\ActivityLogResource`
at the class-declaration level, so the moment discovery reaches that file,
the parent class must exist, whether or not `ActivityLogFeature` is on.
Same shape as the `ModuleResource`/`Marketplace` directory-discovery trap
Phase 2.1 already knew about, just for a *package* dependency instead of an
app-side one. Installed `alizharb/filament-activity-log`,
`spatie/laravel-livewire-wizard`, `ryangjchandler/laravel-cloudflare-turnstile`,
`internachi/modular` as `require-dev` — the package's own test suite has to
exercise every feature it ships, including the ones gated behind a
`suggest`, or discovery-time class loading breaks regardless of runtime
guards. `composer.json`'s `require`/`suggest` split is unaffected; this is
purely a test-harness addition.

**Real bug in `Numerosis::routes()`, not a harness gap: `base_path('routes/
web.php')` was moved verbatim from saas-m's `bootstrap/app.php` in Phase
3.1 and never adjusted for the fact that the routes files now live in the
*package*, not the host.** `base_path()` resolves against whatever app is
calling it — correct when this code lived in the monolith's own
`bootstrap/app.php`, wrong now that a *host* calls `Numerosis::routes()`
expecting it to load the *package's* route definitions. Fixed to
`dirname(__DIR__, 2).'/routes/{web,tenant}.php'` (package-relative, from
`src/Support/Numerosis.php`). This was untested by the Phase 3.1 "suite
stays at 9 failures" check because saas-m's own `routes/web.php` still
existed physically at that path at the time — the bug was invisible until
a *second* app (the package's own test harness) tried to call the same
method.

**Systemic bug across nearly every shipped view: package Blade files
reference their own views/components without the `numerosis::` namespace
prefix `loadViewsFrom()` registers them under.** Root cause: Phase 4.2's
copy was a straight file move, and Phase 4.3's namespace rewrite only
touched `src/tests/database/routes` — it never touched
`resources/views/**/*.blade.php` at all, for either PHP class references
(`\App\Enums\BillingCycle`, `\App\Support\Features`, 26 files, same
`App\` → `Nvade\Numerosis\` rewrite Phase 4.3 already does for `src/`, just
never run against blade files) or Blade view-name references (`view('livewire.
billing.checkout')`, `<x-billing.plan-card>`, `Route::livewire('/tenants/mine',
'pages::tenant.mine')`, `#[Layout('layouts.auth')]`, `@include('partials.head')`
— all resolve against the *host's* default view namespace unless explicitly
`numerosis::`-qualified, and none were). This was invisible until now
because nothing before this session ever rendered these views under the
package's own namespace — saas-m's original app had no namespace at all
(views were the app's own), so the bug only exists in extracted form.
Fixed via one Python pass (`/tmp/prefix_components.py`, not committed)
prefixing every known component tag (`<x-NAME>`/`</x-NAME>`, longest-name-first
to avoid `ui.avatar` clobbering `ui.avatar.fallback`) across `resources/views`
and `src`, plus individual fixes for patterns the enumerated-component-name
approach couldn't catch by construction: `Route::livewire()`'s second
argument, `#[Layout(...)]` attributes, `@include()`, `@livewire()`, a
`->layout()` call, `->view()` calls inside Filament Page classes (`protected
string $view = '...'`, 9 sites), and `<x-dynamic-component :component="'icons.'.
$key">`'s computed string. **Same lesson the earlier abstract-model sweep
already learned, recurring in a different shape: grep-by-known-pattern is
not exhaustive by construction — the two bare-component finds (`<x-ui.list>`,
`<x-ui.accordion>`, resolved via Blade's directory-index convention rather
than a literal file) and `@livewire('tenant.registration.registration')`
(a stale component name that was simply never `'tenant-registration'`,
the name `RegistrationWizardFeature` actually registers) only surfaced by
running the suite and reading each new error, not by pattern-matching
harder.** Two config keys also had to move from "assumed available" to
explicitly set in `tests/TestCase.php` for the same class of reason:
`livewire.component_namespaces` (Livewire's own default points `'pages'`/
`'layouts'` at `resource_path()`, correct for a plain app, wrong for a
package — now points at the package's own `resources/views/{pages,layouts}`)
and `app.central.default` (a `config/app.php` key `routes/auth.php` reads
directly; `Route::domain(null)` is a silent *getter* branch in
`Illuminate\Routing\Route`, not an error, so the missing key surfaced one
line later as `Call to a member function name() on string` — reads like a
routing bug, is a missing host config value). Both, plus `auth.social.providers`
(same `Config::array()`-throws-on-missing-key shape), written into
`docs/host-requirements.md` with the failure signature each one produces,
so a real host hitting the same gap doesn't have to re-derive it.

**A fourth instantiation-by-proxy site for the abstract-model bug, on top of
the three the earlier sweep already catalogued (static calls, relation
definitions, vendor code that receives a class-string to instantiate
itself).** Filament's `Resource::$model` static property is read by the
framework, not called by package code — `protected static ?string $model =
PaymentPlan::class` looks inert but crashes with `Cannot instantiate
abstract class` the moment Filament's own internals do `static::getModel()::
query()`. Fixed the same way as the vendor-callback case: removed the
property, overrode `getModel(): string { return Numerosis::model(X::class); }`
instead, across the 7 Resources that set it to one of the 9 abstract
models (`PaymentPlanResource`, `SubscriptionResource`, `TenantResource`,
central `UserResource`, tenant-panel `UserResource`, `ModuleResource`,
`InvitationResource` — the other 6 Resources setting `$model` to `Role`/
`Permission`/`Feature`/`ModuleOffering` are concrete package models,
untouched). Also found one literal `PendingTenantProvision::query()`/
`::where()` pair inside `resources/views/pages/tenant/⚡mine.blade.php` —
a fifth call site the original sweep's `grep ... src` could never have
reached, since it's a `.blade.php` file, not `.php`. Fixed with the same
`Numerosis::model(PendingTenantProvision::class)::query()` pattern.

**One test file crashed the entire suite (`Pest\Exceptions\FatalException`,
not a per-test failure) — `tests/Feature/Livewire/Invitations/AcceptTest.php`'s
anonymous class overriding `CreatesInvitedUser::create()` typed its
parameter against the *host stub* (`App\Models\Tenant\Invitation`) instead
of the interface's own abstract type (`Nvade\Numerosis\Models\Tenant\
Invitation`).** PHP allows covariant *return* types but not covariant
*parameter* types on an interface implementation, and a concrete subclass
parameter is strictly narrower than its abstract parent — so this is a
straightforward LSP violation, not a namespace issue, caught only by
running the full suite (a per-file/per-directory run never reaches it,
since PHP only checks this at the point the anonymous class is
instantiated). Fixed by importing the abstract type under an alias
(`Invitation as AbstractInvitation`) for that one signature only; every
other use of `Invitation` in the file correctly means the host stub
(fixture creation, `Invitation::factory()`, etc) and was left alone.

**Numbers.** The `tests/Feature/{Features,Filament,Feature}` batch (the
narrowest slice containing the original 34-failure panel-registration
bucket) went from 40 failed/30 passed at the start of this session to 9
failed/61 passed. Full suite: **139 passed → 236 passed, 259 failed → 162
failed** (400 total, 1 risky, 1 skipped, both unchanged). PHPStan
(`--debug --memory-limit=1G`): 376 → 309 identifier-tagged findings — a net
*decrease*, driven by the four now-installed dev packages resolving what
were previously `class.notFound` errors; confirmed no new errors in any
`src/` file this session touched (checked via `--error-format=raw` grepped
against the touched-file list). `workbench/` and `tests/` are outside
`phpstan.neon.dist`'s `paths` (`src`, `config`, `database` only), so the
new panel providers and the `AcceptTest.php` fix aren't analysed at all —
consistent with the rest of the package, not a new gap. Pint clean
throughout (`--dirty`, import-ordering only).

**Deliberately not chased further this session, for the next one to pick
up:**

- **~28 Stripe-API failures** (`Invalid API Key ... sk_test_*ummy`) — tests
  call real Cashier code with a dummy key instead of a fake; needs
  `Cashier::fake()` or an HTTP fake wired into the harness, not a per-test
  fix.
- **Vite manifest missing** (`ViteManifestNotFoundException`, at least 1
  failure, likely more once the Stripe/database issues below stop masking
  others) — `resources/views/partials/styles.blade.php` calls `@vite(...)`;
  Testbench never runs a build step. Expected, given Phase 9's "package
  ships sources, host owns the build" design — the open question is
  whether the harness should stub Vite's manifest lookup or skip
  asset-rendering tests entirely, not something to improvise mid-session.
- **A `PDOException: Unknown database 'tenant...'` in
  `Filament\App\RoleResourceUiTest`, reproduced even filtered down to a
  single test** — fails inside `parent::tearDown()`
  (`RefreshDatabase`'s own rollback), *after* the test body's own
  assertions already passed. Superficially matches `testing.md`'s
  documented "test ends inside tenant context" class, but that class is
  supposedly already handled by this harness's teardown ordering
  (`deleteTenantDatabases()` registered to run after rollback, same as
  saas-m) — worth checking whether the new Workbench panel provider's
  `->tenant(Tenant::class, 'id')` binding triggers a *second*, differently
  keyed tenancy bootstrap cycle that CloneTenantSchema's shortcut doesn't
  know about, rather than assuming it's the already-solved bullet.
- **`MarketplaceTest` (2 failures)** — module-registry business logic
  (`getModules()` not returning the `alerts` module), unrelated to panel
  registration; likely needs `config('app-modules.modules_namespace')`/
  module discovery wired into the harness, out of this session's scope.

Nothing in numerosis committed yet — the working tree still carries this
session's changes on top of Phase 5's `352b8de`, a large uncommitted diff.
**Next session: commit this once the four bullets above are triaged (or
explicitly deferred with reasons, the way this note does), then continue
down `docs/host-requirements.md`'s remaining harness-gap categories from
the previous status note** (missing artisan commands — now actually fixed
this session, all 7 registered via `hasCommand()`, worth confirming that
resolved its share of the original 26 combined "command does not exist"
failures — and the `numerosis-billing.plans.*.monthly_id`/
`database.lock_wait_timeout` config gaps, not yet touched).

**By the start of the next session that commit had in fact landed** as
`numerosis@ab29e89` — the "nothing committed yet" line above was already
stale when read back; check `git log`, not this note, for commit state.

---

## Phase 6, harness-gap sweep — PAUSED
### Phase 6 status — harness-gap sweep, PAUSED mid-session 2026-08-05 (second session)

Picked up exactly where the previous note said to. Baseline confirmed
first: full run **162 failed, 1 risky, 1 skipped, 236 passed** — matches
the previous session's numbers exactly, so nothing drifted between
sessions. Working tree at pause: **uncommitted**, on top of `ab29e89`.
Latest measured full run after the fixes below: **105 failed, 1 risky, 8
skipped, 286 passed** (skipped went 1→8 because the Stripe-price skip
guards, see fix 2, now actually trigger instead of throwing). One more
fix (URL::forceRootUrl, last item below) was made and syntax-checked but
its full-suite effect was **not yet measured** when the session paused —
verify that first thing next time, don't assume it landed clean.

Fixes made, all mechanical/high-confidence, each verified individually
before moving to the next:

1. **`database.lock_wait_timeout` config gap** — `tests/Feature/Database/
   LockWaitTimeoutTest.php` reads `Config::integer('database.lock_wait_timeout')`,
   which `tests/TestCase.php` never set (only the raw PDO init SQL string
   hardcoded `10`). Now both derive from one `$lockWaitTimeout` variable.
2. **`numerosis-billing.plans.*.monthly_id`/`yearly_id` had no default** —
   `env('STRIPE_STARTER_MONTHLY_PLAN')` (and the other 5 keys) returned
   `null` with nothing set, so `Config::string(...)` (no default arg, used
   by ~6 real-Stripe-integration tests that are *designed* to
   `markTestSkipped()` when empty) threw `InvalidArgumentException`
   instead of skipping. Fixed by adding `, ''` defaults to all 6 `env()`
   calls in `config/numerosis-billing.php` — saas-m's own copy is
   unchanged (frozen, Phase 4.1), this is a package-only divergence.
3. **`app.domain` config key never set** — `Config::string('app.domain')`
   (read by `DefaultTenantDomainPolicy`, `CreateTenantDomain`, two Filament
   Tenant resource/relation-manager files) is a host-owned
   `config/app.php` key saas-m sets via `env('DOMAIN', 'localhost')`;
   Testbench's skeleton `app.php` has no such key. 16 failures. Added to
   `TestCase::getEnvironmentSetUp()` next to the existing `app.central` block.
4. **Six missing contract bindings** — `ResolvesLoginCandidate`,
   `AuthenticatesLoginCandidate`, `ResolvesPostLoginRedirectUrl`,
   `CreatesRegisteredUser`, `SendsEmailVerificationNotification`,
   `CreatesInvitedUser` were bound only in saas-m's `AppServiceProvider`
   (thin-app's file, per the Phase 4.2 table's "app-specific bindings"
   line) — but they back the *package's own* `PasswordlessLogin`/
   `Register`/`Accept` Livewire components, so an unbound default left the
   package broken for any consumer, not just tests. Added all 6 to
   `config/numerosis-tenancy.php`'s `implementations` array, same
   swap-by-config pattern the 7 existing entries already use (9 failures
   fixed, `Target [...] is not instantiable`).
5. **`Register`/`ForgotPassword`/`ResetPassword` had no explicit
   `render()`** — same bug class `.claude/plans/package-extraction.md`'s
   own Phase 6 notes already catalogued for `ChatPanel`: relying on
   Livewire's naming-convention view lookup resolves against the *host's*
   default view namespace, not `numerosis::`. Added explicit `render():
   View` returning `numerosis::livewire.auth.{register,forgot-password,
   reset-password}` to all three (10 failures: `File does not exist at
   path .../workbench/.../livewire/auth/*.blade.php`).
6. **`<x-billing.plan-card>` un-namespaced in `PlanCardTest.php`** — the
   earlier session's systemic view-namespace sed pass only touched
   `resources/views/**/*.blade.php` and `src/`, never `tests/`. One test
   file, inline Blade string, fixed to `<x-numerosis::billing.plan-card>`
   (4 failures).
7. **`billing.checkout` Livewire component never registered by name** —
   `<livewire:billing.checkout />` (embedded in the wizard's Payment step,
   and the standalone `/checkout/{domain}` route) addresses it by dotted
   name, which Livewire's Finder can only resolve for *package* classes
   when explicitly registered (same reason `RegistrationWizardFeature`
   registers its own 4 step components) — nothing did, for this one,
   anywhere. `Checkout::render()` already correctly returned `numerosis::
   livewire.billing.checkout` (fixed in an earlier session), so only the
   name registration was missing. Added `Livewire::addComponent(name:
   'billing.checkout', class: Checkout::class)` to `NumerosisServiceProvider::
   packageBooted()` — always-on, not gated behind `RegistrationWizardFeature`,
   since the standalone route needs it with that feature off too.
8. **A fifth instantiation-by-proxy site for the abstract-model bug**
   (on top of the four the earlier session catalogued: static calls,
   Eloquent relation definitions, vendor callbacks, Filament
   `Resource::$model`) — **validation-rule strings**. `Register.php`'s
   `'unique:'.CentralUser::class` and `General.php`'s (a Filament *Page*,
   not Resource — the earlier `getModel()`-override sweep only covered
   Resources) `->model(TenantUser::class)` both feed the literal abstract
   class string to Laravel's `DatabaseRule`, which instantiates it. Both
   fixed with the same `Numerosis::model(X::class)` wrapper (6 failures:
   4× `CentralUser`, 2× `Tenant\User`).
9. **`laravel/socialite` and `sentry/sentry-laravel` needed `require-dev`,
   not just `suggest`** — same discovery-time-loading trap the earlier
   session already found for 4 other packages (`alizharb/
   filament-activity-log` etc): the package's own test suite exercises
   `SocialLoginFeature`'s controller and `TagsSentryScopeWithTenant`
   directly, so a `class_exists()` guard in the *app* code doesn't help a
   *test* file that imports the vendor class at the top. Added both,
   `composer update <pkg> --with-all-dependencies` for each (4 failures:
   2× `Laravel\Socialite\Two\User` not found, 1× `Sentry\configureScope()`
   undefined, plus this unblocked `MarketplaceTest`'s neighbour count
   indirectly — not separately verified).
10. **`Password::sendResetLink()` resolves its user model through the
    `auth.passwords` broker config, not `auth.providers` directly** —
    `TestCase` set up `central_users`/`tenant_users` guards/providers but
    never an `auth.passwords.users` broker entry, so Laravel fell back to
    its own generic `Illuminate\Foundation\Auth\User` (no `Notifiable`
    trait) — surfaced as `Call to undefined method ...User::notify()`,
    reads like a model bug, is a missing broker config. Added
    `auth.defaults.passwords` + `auth.passwords.users` (provider
    `central_users`, table `password_reset_tokens` — migration already
    present in `database/migrations/central/0001_..._create_users_table.php`)
    (2 failures).
11. **`assertDatabaseHas('socialite_logins', ...)` missing the `'central'`
    connection argument** — exactly the snapshot-isolation trap
    `.claude/rules/testing.md` already documents (added by the earlier
    session for `ConnectSocialAccountTest`/`RecordSubscriptionTest`); this
    is a *third*, previously-unnoticed site, `ProfileSocialAccountTest.php`.
    One-line fix, third arg `'central'` (1 failure).

**In progress when paused, not yet measured against the full suite:**
webhook-route 404s (`WebhookControllerLifecycleTest`,
`WebhookControllerSetupIntentTest`, `SubscriptionDualWriterTest`,
`Socialite\LoginTest` — ~13 failures). Root-caused via a debug-test
back-and-forth (temporary files, all deleted, none left in the tree) to a
genuine Laravel-13/Testbench interaction, not a package bug:
`MakesHttpRequests::prepareUrlForRequest()` builds the request URL with
the `url()` helper (not `$this->baseUrl` — that property is dead in this
Laravel version, checked the source directly), and `Illuminate\Routing\
UrlGenerator` prefers the `Request` Testbench's `SetRequestForConsole`
bootstrapper already bound into the container (`Host: localhost`) over
`config('app.url')` whenever one is already bound — so a plain
`$app['config']->set('app.url', ...)` (already in `TestCase`, added
earlier this session for the `app.domain` fix's neighbour) is silently
ignored by `postJson()`/`route()`/`url()` even though `config('app.url')`
reads back correctly. Confirmed by direct experiment: manually constructing
a `Request` and calling `Route::getRoutes()->match()` found the route
fine (domain matched); dispatching that same request through the real
`Kernel::handle()` also reached the controller; only the `postJson()`-built
URL resolved to `http://localhost/...` and 404'd. Fix applied —
`\Illuminate\Support\Facades\URL::forceRootUrl('http://central.numerosistest.test')`
added to `getEnvironmentSetUp()` right after the `app.url` config set,
with a docblock explaining why the config alone doesn't work — but the
session was interrupted before re-running either the single test file or
the full suite to confirm it actually clears those ~13 failures. **Resume
here**: `cd ~/repos/private/numerosis && php -d memory_limit=1G vendor/bin/pest
tests/Feature/Http/Controllers/Billing/WebhookControllerLifecycleTest.php --compact`
first (should go from 7 failed to 0 if the fix is right), then a full run.

**Not yet started, still open** (unchanged from the previous session's
list, re-confirmed present in this session's 105-failure run):

- **~29 Stripe-API failures** (`Invalid API Key ... sk_test_*ummy`) — NOT
  the same as fix 2 above. These test files (`AddVatNumberTest`,
  `ResolveSavedPaymentMethodTest`, `ResolveSetupIntentTest`,
  `FetchReusablePaymentMethodsTest`, `FetchSavedBillingDetailsTest`,
  `SyncBillingAddressTest`, `Livewire/Billing/CheckoutTest`,
  `InlineCheckoutGatewayTest`, parts of `PurchaseModuleTest`) call real
  Cashier/Stripe API methods directly with **no** skip guard at all — this
  is a different, undecided design question (real test-mode key vs.
  `Cashier::fake()`/HTTP fake vs. bulk-added skip guards), not a
  mechanical fix, and was deliberately not decided unilaterally this
  session.
- **Module system, ~24 failures across three shapes**: `tests/Feature/
  Modules/{NotesModuleTest,BrandingModuleTest,TasksModuleTest,
  AnnouncementsModuleTest,Branding/ApplyBrandingTest}` test app-side
  module packages (`Nvade\Branding\...` etc) that structurally don't exist
  inside this package per the plan's own Phase 6.3 table ("a module's own
  behaviour | that module's package under thin-app/app-modules/*") — these
  arguably shouldn't be in numerosis's test suite at all, but weren't
  deleted (Pest convention in this repo: "do NOT delete tests without
  approval"). `MigrateModulesTest`/`RollbackModulesTest`/
  `MigrateTenantModuleTest` hardcode a real module name (`'alerts'`)
  against the generic `MigrateModules` action — same root cause.
  `MarketplaceTest` (2), `PurchaseModuleTest`'s `ModuleNotInstalled`-vs-
  other-exception mismatches (4) likely same family. Needs either a
  synthetic test-only module fixture wired through `internachi/modular`'s
  path-repo mechanism, or moving these tests to thin-app once it exists —
  a real design decision, not touched this session.
- **~19 `SQLSTATE[HY000] [1049] Unknown database 'tenantX'` teardown
  failures** — same `Filament\App\RoleResourceUiTest` +
  `Actions/Modules/CancelModuleTest` + others the previous session flagged
  as "reproduced even filtered to a single test, fails inside
  `parent::tearDown()`, worth checking whether the Workbench panel
  provider's `->tenant(Tenant::class, 'id')` binding triggers a second
  tenancy bootstrap cycle rather than assuming it's the already-solved
  bullet" — not investigated this session either; count went 15→19
  between the two sessions' baselines (more tests reaching further now
  that other blockers cleared, not a regression from this session's
  edits).
- Small, not-yet-looked-at singletons: `Mockery::mock()` on final
  `Orchestra\Testbench\Console\Kernel` (`SeedTenantDatabaseTest`),
  `FailedJobsTableTest`'s sqlite-connection error, `TenantSuspended`
  notification-not-sent ×2, "unpaid workspace" quota test, two Blade
  HTML-snapshot mismatches (`RegisterTest`/`SocialLoginButtonsTest`?
  — not traced to a file this session).

**Nothing in numerosis committed this session.** Working tree carries 11
modified files (`composer.json`+`composer.lock`, `config/numerosis-{billing,
tenancy}.php`, `src/Filament/TenantAdmin/Clusters/Profile/Pages/General.php`,
`src/Livewire/Auth/{ForgotPassword,Register,ResetPassword}.php`,
`src/NumerosisServiceProvider.php`, `tests/Feature/Feature/Filament/
TenantAdmin/Pages/ProfileSocialAccountTest.php`, `tests/Feature/View/
Components/PlanCardTest.php`, `tests/TestCase.php`) on top of `ab29e89`.
Commit once the webhook-route fix above is confirmed and the full suite
re-measured — don't commit on the strength of the individual-file re-runs
alone, given how the previous session's "nothing committed yet" line went
stale by the time this one started.

---

## Phase 6, third session — webhook bucket closed
### Phase 6 status — webhook-route bucket closed, three package bugs, 2026-08-05 (third session)

Resumed at the exact pointer the previous note left: verify the unmeasured
`URL::forceRootUrl()` fix, then re-measure. It **was** correct — requests now
reach the controller instead of 404ing — but it only uncovered three further
failures underneath, all of them **package bugs that would hit a real consumer,
not harness gaps**. `WebhookControllerLifecycleTest` went 7 failed / 7
assertions / 116s → **7 passed / 20 assertions / 3.5s**, exact parity with
saas-m's own run of the same file (7 passed, 20 assertions).

1. **A sixth instantiation-by-proxy site: the package's own config defaults,
   handed to a vendor setter.** `config/numerosis-billing.php`'s
   `models.{tenant,subscription,subscription_item}` default to the *abstract*
   package classes, and `BillingServiceProvider::boot()` passed them straight
   into `Cashier::useCustomerModel()`/`useSubscriptionModel()`. Cashier then
   does `new $model` deep inside `findBillable()`, so the failure lands nowhere
   near this line. It also made `tests/TestCase.php`'s `cashier.model` override
   dead code — the provider overwrote it on every boot. Fixed by resolving all
   three through `Numerosis::model()` at the point of use, which is a no-op for
   a host that has already pointed those keys at a concrete class of its own.
   **This is the sixth distinct shape of this bug (static calls, relation
   definitions, vendor callbacks receiving a class-string, Filament
   `Resource::$model`, validation-rule strings, now config defaults) — see R1;
   the sweep is still not closed by construction.**

2. **`SubscriptionFactory` silently built Cashier's model, not ours — and the
   symptom was a lock-wait timeout, not a wrong-model error.** The factory
   extends `Laravel\Cashier\Database\Factories\SubscriptionFactory`, whose
   `protected $model = \Laravel\Cashier\Subscription::class` is **inherited**,
   and an inherited property still short-circuits `Factory::modelName()`'s
   resolver — so the file's own comment ("no `$model` override, so the global
   resolver applies") was false. Cashier's model does not compose stancl's
   `CentralConnection`, so every subscription fixture was written on the
   **default** connection inside `RefreshDatabase`'s open transaction, while
   the code under test reads and writes `subscriptions` on `central`. The
   fixture was therefore invisible to the application (snapshot isolation), the
   application inserted its own row with the same unique `stripe_id`, and that
   insert blocked on the uncommitted duplicate key: `SQLSTATE 1205`. **This is
   why the ~19-failure `Unknown database`/lock-wait bucket must not be assumed
   to be the already-documented `testing.md` contention class — at least part
   of it is this, a wrong-connection *write* masquerading as generic
   contention.** Diagnosed by instrumenting `DB::listen` with the connection
   name (temporary test file, deleted), not by reading the SQL — the insert
   naming `subscriptions` on `mysql` while every read logged `central` is the
   tell. Fixed with an explicit `modelName()` override routed through
   `Numerosis::model()`; also fixed the factory's `subscribable_type` default,
   which wrote the *abstract* class name into a morph column.

3. **Every listener this package ships was unregistered.** Laravel's event
   discovery only scans the **host application's** `app/Listeners`; it never
   sees a package's `src/Listeners`. Seven listeners
   (`LogSocialAccountConnected`, `LogSocialAccountDisconnected`,
   `SendPaymentConfirmedNotification`, `SendPaymentFailedNotification`,
   `SendTenantSuspendedNotification`, `SendInvitationNotification`,
   `QueueModuleMigration`) were relying on it — and
   `BillingNotificationsFeature`'s docblock still asserts, in writing, that
   "all three listeners are auto-discovered by Laravel's event discovery",
   which was true in the monolith and false the moment the code moved to
   `src/`. The events still fired and still drove local state, so a tenant
   really was suspended and only the notification never went out: **silent for
   any consumer, and invisible to any test that asserts state rather than
   side effects.** Registered explicitly in
   `NumerosisServiceProvider::registerEventListeners()`, unconditionally —
   each listener already self-gates on its feature with an early return, which
   is the arrangement those feature classes document. The three listeners that
   *were* already registered (`SyncTenantToStripeOnSave` in
   `BillingServiceProvider`, `UpdateSyncedResource` and
   `LogSyncedResourceChangedInForeignDatabase` in `TenancyServiceProvider`) are
   deliberately excluded, or they would fire twice.

**Measured, committed as `numerosis@7637f38`** — the first commit in three
sessions, per R6's "never end a session with an uncommitted tree":

| | Session start | Now |
|---|---|---|
| Full suite | 105 failed / 286 passed / 8 skipped / 1 risky | **89 failed / 302 passed / 8 skipped / 1 risky** |
| Duration | minutes (lock-wait stalls) | **63.6s** |
| PHPStan | 309 errors | **295 errors**, none in touched files |

Zero `Cannot instantiate abstract class` and zero `SQLSTATE 1205` in the whole
run. Pint clean.

Remaining 89, by cause (counts approximate — grouped from the run's output):

- **~29 Stripe** (`Invalid API Key … sk_test_*ummy`) — unchanged, still the
  undecided design question in R2's table.
- **~17 `Unknown database 'tenantX'`** — **do not assume this is the
  documented `testing.md` contention class.** Fix #2 above proves at least
  part of that bucket was a wrong-connection *write*, not generic contention.
  Re-diagnose with the `DB::listen`-plus-connection-name trick that found it.
- **~15 `ViteManifestNotFoundException`** — expected per Phase 9; R2 says stub
  the manifest in the harness rather than testing what the host owns.
- **~16 module tests** (`Modules\{Branding,Notes,Tasks,Announcements}`) —
  R2's "these structurally belong to thin-app" bucket, unchanged.
- **~11 + ~7 `AuthenticationException`** in `Actions\Billing` and
  `Livewire\Billing` — new to this list only because they were previously
  masked; not yet investigated.

**Generalise #3 before continuing:** discovery-based wiring is not the only
thing that stops working inside a package. Anything the monolith got from
convention over `app/` — event discovery, `Policy` auto-discovery (already
half-known: see `auth-guards.md` on `#[UsePolicy]` not being inherited),
Livewire's component-name convention (already hit twice), Blade's view
namespace (hit once, systemically), factory-name guessing (hit twice) — needs
an explicit registration in the package. **Add an audit of every
convention-based registration to the Phase 6 exit criteria (R2)** rather than
finding them one failing test at a time.

---

## Session 4 onwards — steps 2-5, and the handoffs they superseded
### Session 4 status — steps 2 and 3 done, 2026-08-05

**Step 2 (R7's PHPStan paths+baseline) done.** `phpstan.neon.dist`'s
`paths` gained `tests` and `workbench`. Full run at that scope: 414 errors,
none surprising (mostly workbench-stub `class.notFound` — Filament/Cashier
proxying to abstract-turned-concrete package models, plus ordinary
test-idiom noise). Baseline regenerated to freeze exactly that number;
verified `analyse` is clean against it. Committed `numerosis@a0909dc`.

**Step 3 (D10's thin-app, 7.1-7.4) done — booting app that passes
`numerosis:install`, verified through a real HTTP request, not just the
install command.** `~/repos/private/thin-app` created (`laravel new`),
path-repo'd onto `../numerosis`, given the thin-app-only/suggest packages
from `DEPENDENCIES.md` (Telescope, Reverb, Socialite, the six `nvade/*`
modules, Filament theme + activity-log, Turnstile, Sentry,
`laravel-livewire-wizard`, `laravel/sail`). Copied per Phase 4.2's table
(docker/, docker-compose.yml, .env.example, vite.config.js, package.json,
bootstrap/app.php, public/, app-modules/*, resources/views/{errors,vendor},
the host-owned framework configs, the two Filament panel providers,
`.claude/`, `CLAUDE.md`, `AGENTS.md`). `bootstrap/app.php` and
`AppServiceProvider` rewritten onto `Nvade\Numerosis\*`. The two Filament
panel providers are copied but **not** registered in
`bootstrap/providers.php` — they still reference `App\Filament\*`/
`App\Concerns\*` classes that only exist in the monolith; wiring them as
real plugins is Phase 8, explicitly deferred per D10's own text. Committed
`thin-app@d2f5262`.

Five bugs found by actually booting this app — every one only surfaces
once a second, real consumer exists, which is D10's whole rationale:

1. **`numerosis:install`'s `verifyDatabaseConnections()` required
   `config('database.connections.tenant')`, which never exists statically**
   — stancl builds that connection dynamically from
   `template_tenant_connection` at tenancy-bootstrap time (see
   `config/tenancy.php`'s own "don't name your template connection tenant"
   comment). Every fresh install failed this check unconditionally. Fixed
   in the package: checks `central` plus, if set, that
   `template_tenant_connection` names a real connection.
2. **`numerosis:install`'s `publishAssets()` never published the
   `numerosis-tenant-migrations` tag**, so `verifyTenantMigrationPath()`'s
   directory-exists check always failed — nothing had created the
   directory it was checking for. Fixed by publishing that tag too.
   Both fixed in `numerosis@085c18a`.
3. **Exact filename collision between `laravel new`'s stock migrations and
   the package's own central migrations** (`0001_01_01_000000_create_users_table.php`
   and the two after it). Laravel's migrator dedupes by filename, silently
   ran the host's version, skipped the package's — `users` came out without
   `global_id`, and the first migration referencing it
   (`create_tenant_users_table`) failed with a missing-column FK error.
   Fixed in thin-app by deleting the three stock files; the package's
   versions supersede them.
4. **`config/logging.php`'s `stack` channel hardcoded
   `['sentry_logs', 'daily']`.** sentry-laravel only registers the
   `sentry_logs` driver when a DSN is configured (`LogIntegration` feature
   no-ops without one) — without this guard every request 500s at boot,
   before the real exception is ever logged, with "Driver [sentry_logs] is
   not supported" masking whatever actually failed. Fixed in thin-app:
   conditional on `SENTRY_LARAVEL_DSN` being set.
5. **`config/livewire.php`'s `component_namespaces` pointed at
   `resource_path()`** — correct for a single-repo app, wrong once
   `layouts`/`pages` ship from the package. Exactly the trap
   `docs/host-requirements.md` already documents; thin-app just hadn't
   applied the documented fix yet. Pointed at
   `vendor/nvade/numerosis`'s copy instead.

Two more thin-app-only fixes, not package bugs: `app-modules/chat`'s
`ChatServiceProvider` imported `App\Enums\Tenant\DisplayStatus`, which only
ever existed in the monolith — repointed at the package's
`Nvade\Numerosis\Enums\Tenant\DisplayStatus`. `docker-compose.yml` only
mounted `.:/var/www/html`; the numerosis path-repo's symlink is relative
(`../../../numerosis` from `vendor/nvade/numerosis`) and resolved to
nothing inside the container since the sibling checkout wasn't mounted —
added a volume mounting it at the matching relative depth
(`../numerosis:/var/www/numerosis`).

**Verified end to end, not just "install passes":** `sail artisan migrate
--force` runs every central migration clean (users, tenants, domains,
subscriptions, payment plans, modules, activity log, telescope — the full
64-migration set); a real HTTPS request through Traefik to
`https://app.thinapp.dev/` resolves routing, middleware, auth guards,
models, and views, and stops **only** at "Vite manifest not found" — Phase
9's boundary (package ships sources, host owns the build), not a bug.
thin-app's dev stack uses its own domain (`thinapp.dev`) and non-default
ports specifically so it can run alongside saas-m's own already-running
`nvade.dev` containers; user approved the one system-level change this
required (`*.thinapp.dev` added to `/etc/dnsmasq.conf`, dnsmasq restarted).

Docker verification (Phase 7 step 4's literal `sail up -d` +
`numerosis:install`) is done; Phase 7 step 5 (publish/adjust model stubs
beyond the 9 already published) and the rest of Phase 7 (7.5) are not — the
amended order is `… → 7.1-7.4 → 6.3-6.6 → 7.5 → 8 → 9 → 10`, so **resume at
6.3-6.6 next**, not 7.5.

### Next session — start here (superseded — the live list is in `package-extraction.md`)

**Step 4 done, 2026-08-05 (fourth session) — `numerosis@307e95a`.** Ran the
package suite with `php -d memory_limit=1G` (default 128M exhausts mid-run,
not a regression). Isolated each module-adjacent failing file before touching
anything — this mattered: `CancelModuleTest`'s 2 failures turned out to be
step 6's `Unknown database` teardown bug, not a missing-module issue, so it
was left untouched; `PurchaseModuleTest` was flaky across reruns (different
tests failed each time, same underlying cross-test contamination step 6 is
about) but 10 of its 13 methods reliably die on `ModuleNotInstalled` first, so
the whole class got `#[Group('thin-app')]` rather than per-method tags.
Confirmed per-file with isolated runs before tagging, not from the full-run
summary alone. Tagged: `PurchaseModuleTest` (whole class), the 5
`tests/Feature/Modules/**` files (whole class each — they import
`Nvade\{Announcements,Branding,Notes,Tasks}\*` directly, which plainly don't
exist in numerosis's `composer.lock`), and single methods in `MarketplaceTest`
(2), `MigrateModulesTest` (1), `RollbackModulesTest` (1) — each file's other
methods pass today and stay in the numerosis run. `phpunit.xml.dist` excludes
the `thin-app` group. Stubbed `public/build/manifest.json` in
`TestCase::getEnvironmentSetUp()` (writes a fake manifest with `file`/`src`
entries for the 4 `@vite()` sources — tests render HTML server-side and never
fetch the asset, so a fake path is sufficient); zero
`ViteManifestNotFoundException` anywhere in the suite now. Also fixed 2
PHPStan errors on `src/Commands/InstallNumerosisCommand.php` (mixed-typed
`template_tenant_connection` config value used as an array key) — leftover
drift from the *previous* session's `085c18a`, landed after `a0909dc` froze
the baseline and never re-baselined; confirmed via `git log` that I hadn't
touched that file, so this was pre-existing, not new.

Result: **104 failed → 72 failed, 287 passed unchanged** (no regressions —
verified against the pre-change run's pass count, not just the new failure
count), 1 risky, 7 skipped (was 8 — one skip moved into the now-excluded
`PurchaseModuleTest`). PHPStan clean, Pint clean. Physical move into
`thin-app/tests/` **not done this session** — thin-app has no Pest, no MySQL
harness, and none of 6.2's test-support layer yet (default `laravel new`
`phpunit.xml`/sqlite/`ExampleTest.php`), so there is nowhere for these files
to actually run yet; that port is Phase 7.5, already next in the amended
order. The files stay in numerosis, quarantined by group, until then — moving
them into an app with no harness would just relocate the same failures.

**Step 5 started, not finished, 2026-08-05 (fourth session) —
`numerosis@ee77b3a`.** D9's literal wording ("`Http::preventStrayRequests()`
plus recorded fixtures") does not work as written: Stripe's SDK makes its own
HTTP calls through `Stripe\ApiRequestor` (default `CurlClient`), never through
`Illuminate\Http\Client`, so the `Http` facade's fake never sees them — found
this by trying it first, not by reasoning in advance. Built
`Nvade\Numerosis\Tests\Support\FakeStripeHttpClient` instead: implements
Stripe's own `HttpClient\ClientInterface`, swapped in via
`Stripe\ApiRequestor::setHttpClient()` (a `Nvade\Numerosis\Tests\Concerns\FakesStripe`
trait wires and unwinds it). Second correction, also found by running the
target test rather than assuming: "recorded fixtures" (static canned JSON per
endpoint) isn't enough either — `AddVatNumberTest` creates a customer, updates
its address, retrieves it back, attaches a tax id, then lists tax ids, and
each step reads state an earlier step in the *same test* wrote. The fake is
therefore a small stateful in-memory Stripe (in-memory `$customers`/`$taxIds`
arrays keyed by generated id), not a fixture player. An unhandled
`(method, path)` throws immediately naming both, so a test hitting a new
endpoint fails loudly rather than returning nothing.

Wired into **one file, `AddVatNumberTest`, both tests, fully green** —
verified in isolation and in the full suite, not just assumed from the
mechanism working once. covers `customers` create/retrieve/update and
`tax_ids` create/list. **Not done:** the other 8 files
(`SyncBillingAddressTest`, `FetchSavedBillingDetailsTest`,
`FetchReusablePaymentMethodsTest`, `ResolveSetupIntentTest`,
`ResolveSavedPaymentMethodTest`, `ResumeCheckoutTest`,
`InlineCheckoutGatewayTest`, `Livewire/Billing/CheckoutTest`) need more
resources added to `FakeStripeHttpClient` — at minimum `setupIntents`
(create/retrieve/confirm), `paymentMethods` (create/attach/retrieve/list),
`prices` (retrieve), and whatever `InlineCheckoutGatewayTest` and the
Livewire checkout flow turn out to need once actually read closely (not yet
done). Each new resource follows the same shape as `customers`/`tax_ids` —
add a `match` arm in `request()`, a handler method, extend the in-memory
store if the resource needs to persist across calls in one test. Read each
target file's Stripe calls (both the test's own setup calls *and* the
action/service code under test — both hit the fake once wired) before adding
handlers, the way `AddVatNumberTest` was read fully before writing any code
this pass.

Result: **72 failed → 70 failed, 287 → 289 passed** (both AddVatNumberTest
tests). Pint clean, PHPStan clean.

**Step 5, continued, 2026-08-05 (fifth session) — `SyncBillingAddressTest`
wired, not yet committed.** Extended `FakeStripeHttpClient`: added
`paymentMethods` create/retrieve/attach, and made `createTaxId` reject a
malformed `value` (`preg_match('/^[A-Z]{2}[A-Z0-9]+$/i', ...)`, else throws a
new `FakeStripeApiError(status, errorBody)` — caught in `request()` and
turned into a `{"error": {...}}` / non-2xx response so `Stripe\ApiRequestor::
handleErrorResponse()` raises the same `ApiErrorException` subclass real
Stripe would for a bad VAT number, which is what `AttachVatNumber::handle()`
catches and rethrows as `InvalidVatNumber`). `SyncBillingAddressTest` itself
was rewritten from "hits real Stripe test mode" (its old docblock) to use
`FakesStripe`, all 4 tests now call `$this->fakeStripe()`.

One bug caught only by running the test, not by reading the diff: the fake
payment method's `billing_details.address` initially only merged
caller-supplied keys, so `line2`/`state` were absent whenever the caller
didn't pass them — `SyncBillingAddress::handle()` reads
`$address?->line2`/`$address?->state` unconditionally, and `Stripe\
StripeObject` prints (not throws) `"Undefined property"` on an absent key,
which PHPUnit/Pest then flags as **risky** ("test printed unexpected
output"), not failed — all 4 tests showed 7/7 assertions green *and* risky
on the first run. Fixed by always giving the fake payment method's address
all 6 Stripe fields (`line1/line2/city/state/postal_code/country`), null or
not. **Any new fake resource needs every field the real Stripe object
exposes present (even as null), not just the ones a given test happens to
pass in** — a risky-not-failed result is the tell if this is missed again.

Verified in isolation: `SyncBillingAddressTest` 4/4 green, no risky, 7
assertions. Pint clean on the dirty set.

**Step 5 finished, 2026-08-06 (sixth session) — `numerosis@b06bcfc`,
`6f28fd1`, `d2b750d`.** Committed the `SyncBillingAddressTest` increment
above first (`b06bcfc`, after PHPStan-cleaning six mixed-typed-access errors
in `FakeStripeHttpClient` the previous session hadn't checked — added
`arrayParam()`/`stringParam()` narrowing helpers, now used everywhere the
fake reads a Stripe params array), then wired the remaining 8 files two
commits at a time, same discipline as before (read the action's real Stripe
calls before adding handlers, verify isolated before moving on, full suite
before committing):

- `6f28fd1`: `FetchSavedBillingDetailsTest`, `FetchReusablePaymentMethodsTest`,
  `ResolveSetupIntentTest`, `ResolveSavedPaymentMethodTest`. Added
  `setup_intents` create/retrieve (including auto-creating the underlying
  PaymentMethod for a bare test token like `pm_card_visa` on
  `confirm: true`, matching what real Stripe does), customer
  payment-method listing, and `tax_ids` expand on customer retrieve. **Three
  of these four files were originally Pest-functional (`test()`/`uses()`)
  and had to be converted to class-based `TestCase` subclasses** — this
  repo's PHPStan setup has no Pest plugin registered, so `$this->fakeStripe()`
  (a custom trait method) inside a functional closure is
  `method.notFound` on `Pest\PendingCalls\TestCall`; every existing
  Stripe-faked file was already class-based for exactly this reason, this
  session just hadn't noticed the pattern was load-bearing until PHPStan
  said so.
- `d2b750d`: `ResumeCheckoutTest`, `InlineCheckoutGatewayTest`,
  `Livewire/Billing/CheckoutTest`. Added `setup_intents/{id}/confirm`
  (separate from confirm-on-create — the cross-row-replay test in the
  Livewire spec calls it directly) and a `client_secret` field on setup
  intents. Caught one more instance of the address-defaulting bug
  `SyncBillingAddressTest` had already taught: `customers.update`'s
  `address` param, like the payment-method one, needs every one of Stripe's
  6 address fields present (null or not), not just whatever the caller
  passed — a partial address produced the same risky-not-failed
  "`Stripe Notice: Undefined property`" symptom, this time in
  `CheckoutTest`'s prefill test. Also switched the two remaining
  `RuntimeException`-on-missing-resource throws (`retrieveCustomer`,
  `retrieveSetupIntent`) to `FakeStripeApiError`, since two of the new
  tests (`cus_invalid`-triggered fetch-failure banners) depend on the
  action's `catch (ApiErrorException)` actually firing.

All 9 Stripe-dependent files now run against the fake. D9 is closed.

**Real bug found and fixed while running the full suite after `d2b750d`,
unrelated to the Stripe-fake work — `numerosis@92d120f`.**
`WebhookControllerLifecycleTest`, which makes no Stripe API calls at all,
had regressed from the 7/7 the Phase 6 log recorded to 1/7. Root cause:
D12's `Numerosis::model()` only redirects a call site to a host's model
class when `config('numerosis.models.<FQCN>')` is set, and
`tests/TestCase.php` never set it — every Workbench test creates rows via
the `App\Models\*` stub subclasses, while package code writing a
class-string through `Numerosis::model()`
(`LinkSubscriptionToTenant::subscribable_type`, most visibly) kept writing
the *abstract package class name*. A later polymorphic lookup filtering on
that column found nothing; Cashier's `updateOrCreate` fell through to an
`insert` colliding with the existing row's unique `stripe_id` —
`SQLSTATE 1205`/`1062` depending on timing, exactly the shape of
`testing.md`'s documented lock-wait bucket, which is why it read as
generic contention rather than a class-string mismatch. Fixed by setting
`numerosis.models` in `tests/TestCase.php` to the 9 Workbench stub
classes — the same override mechanism `config/numerosis.php`'s own
comment already describes as the intended host shape, just never wired
into the harness. **This is a real, load-bearing gap for any actual
consumer too**, not just a test artifact: `numerosis:install` publishes
the model stubs (`--tag numerosis-models`) but never points
`numerosis.models.*` at them, so a fresh thin-app install would hit the
identical mismatch the moment `LinkSubscriptionToTenant` or any other of
the ~108 `Numerosis::model()` call sites runs against a stub-created row.
**Not yet fixed in `InstallNumerosisCommand`/`numerosis:install`'s
verification step — follow-up, not done this session.**

Verified: `WebhookControllerLifecycleTest` back to 7/7 isolated. Full
suite **47 failed → 32 failed, 312 → 327 passed** (net +15; the failing-file
list is a strict subset of before — no new file appears). PHPStan clean,
Pint clean throughout.

Working state at handoff: `numerosis@92d120f` (clean tree); `thin-app@d2f5262`
(unchanged); `saas-m` — this file's edit is the only change, uncommitted.
Docker: `saas-m-mysql-1` was stopped at session start (needed by numerosis's
test suite, which points at `127.0.0.1:3306` — see `tests/TestCase.php`) and
was started; leave it running for the next session or restart it the same
way (`docker start saas-m-mysql-1`).

**Next session — two follow-ups before returning to the amended step
order:**

1. **`numerosis:install` should set `numerosis.models.*`, or at least warn
   it's unset, when it publishes model stubs** — the gap above is real for
   any consumer, not just this harness. Decide the shape (write `.env`
   entries the way the 6 existing keys already work? write directly to
   `config/numerosis.php`, which doesn't exist in a fresh host until
   published? something else?) before implementing — this wasn't part of
   D8/D12's original scope and deserves a quick look at what `numerosis:install`
   already does for its other publish steps before picking an approach.
2. **Re-run the 32-failure full suite and re-bucket it from scratch** — the
   old ~17 "`Unknown database`" estimate and the ~29 Stripe estimate are
   both stale now (Stripe is 0, since D9 just closed; the model-config fix
   likely absorbed some of what was filed under contention). Get a fresh
   per-cause breakdown before deciding whether step 6's `DB::listen` dance
   is still needed at all, or whether what's left is a smaller, different
   set of causes. Files seen failing in the last full run, for a starting
   point (do not assume these buckets without re-checking):
   `LoginUserTest`(?), `Actions\Invitations\A*`, `Actions\Modules\Cance*`
   (`CancelModuleTest`), `BotBlockingAuthTest`, `Console\Commands\{Migrate,Seed}TenantModule*`,
   `Database\FailedJobs*`, `Filament\App\RoleReso*`,
   `Http\Controllers\Socialite\{Login,Redirect}Test`,
   `Invitations\CheckInvitationStatusTest`, `Livewire\Auth\{Passwor*,SocialLoginButtonsTest}`,
   `Livewire\T*`, `TenantAdminAuthTest`, `View\Components\PlanCardTest`.
3. Then continue the amended order: **step 7**, audit convention-based
   registration (policies, Livewire component names, Blade view namespace,
   factory guessing — event discovery already fixed).

Superseded, kept for history only — state as of the *previous* session's
handoff, before step 4 above:

Working state at handoff: `numerosis@085c18a` (clean tree), `thin-app@d2f5262`
(clean tree, containers up under the `thinapp.dev` stack), `saas-m@a0909dc`-ish
(this file's edit is the only change, uncommitted until this session's commit
lands). Resume at **step 4** in the list below (steps 1-3 done: D8's model
pass, R7's PHPStan baseline, D10's thin-app).

Superseded, kept for history only — state as of the *previous* session's
handoff, before steps 2-3 above:

Working state at handoff: `numerosis@f665a96` (clean tree),
`saas-m@99aed72` (clean tree, this file's edit uncommitted). D8's model
pass (step 1 below) is **done** — see D12 above for what actually shipped
(not D8's literal option (b)). Package suite **~89-149 failed (flaky
range, see testing.md) / ~287-302 passed / 8 skipped / 1 risky**; PHPStan
**301 errors** (was 295; net +6 after fixing 3 and adding 9 — see D12),
empty baseline still. Resume at **step 2** (R7's PHPStan paths+baseline
change) below.

Superseded, kept for history only — the "89 failed / 302 passed" figure
below was this session's *starting* point, not its result:

Working state at handoff: `numerosis@7637f38` (clean tree),
`saas-m@99aed72` (clean tree). Package suite **89 failed / 302 passed /
8 skipped / 1 risky in 63.6s**; PHPStan **295 errors**, empty baseline.

Do these in order. Each is independently committable; commit at every
green-or-better point rather than carrying a large tree, per R6.

1. **D8's model pass** — the single biggest reduction in accidental
   complexity available, and everything else gets easier once literal model
   calls work again. Verify with the full suite, not with greps: the whole
   point of the decision is that grep was never a sufficient check. Expect
   PHPStan to drop.
2. **R7's PHPStan change** (paths + baseline) immediately after step 1, so the
   baseline freezes the *post-D8* number and every later session gets a real
   ratchet.
3. **D10's thin-app** (7.1-7.4). Stop at a booting app that passes
   `numerosis:install`; do not chase Phase 8's Filament plugins yet.
4. **Move the module tests** to thin-app (they now have a home) and **stub
   Vite** in the package harness. That is ~31 of the remaining 89.
5. **D9's Stripe fake** (~29). Largest remaining bucket, and the only one that
   needs new test infrastructure rather than moving or configuring something.
6. **Re-diagnose the ~17 `Unknown database`/teardown failures from scratch.**
   Do not assume they are `testing.md`'s documented contention class — this
   session proved at least part of that bucket was a wrong-connection *write*
   (Cashier's model, no `CentralConnection`) masquerading as contention. The
   tool that found it: `DB::listen` printing `$query->connectionName`, in a
   throwaway test. An insert logging one connection while every read logs
   another is the tell.
7. **Audit convention-based registration** (the generalisation below): event
   discovery is fixed, but policies, Livewire component names, Blade view
   namespace and factory guessing have each already bitten once or twice.
   Enumerate what the monolith got from `app/` convention and check each is
   explicitly registered by the package. This belongs in Phase 6's exit
   criteria, not in a future bug report.

Then write the Phase 6 completion status (numbers, what moved, what is
quarantined and why) and delete the four superseded Phase 6 pause notes into
the log file D11 sets up.

---

---

## 2026-08-06 — config merge finished, plan split, install fixed

Session started with numerosis carrying a broken half-executed tree: the
three-file config merge had deleted `numerosis-billing.php`/
`numerosis-tenancy.php` and rewritten both providers, while call sites still
read the dead keys. Finished it (`59f026f`), then worked down the review list.

- **A — merge finished.** Remaining stale reads were in `database/`, which the
  merge plan's enumerated call-site list never covered; its closing grep is
  what caught them. Targeted run over the five affected directories: 98
  passed, 6 skipped, 2 failed — both the known `CancelModuleTest` "Unknown
  database" teardown bucket. PHPStan clean, baseline 241 → 240.
- **B — thin-app repaired** (`thin-app@41bcd57`). Its three published config
  files were byte-identical to package defaults, so nothing had to be
  re-applied; deleted the two orphans and re-published. Still boots to the
  Vite boundary.
- **C — recorded as D13**, plus a correction: the reversal note told *saas-m*
  to re-publish, which is the one repo where it does not matter. The wozniak
  plan is committed (`6718d5d`) marked executed.
- **D — D11 and R6 executed** (`b445e4e`). Plan split into a 1397-line plan
  with an overwritten Live status, and a 1487-line log (this file). Verified
  the split loses nothing: every source line not carried over is blank or a
  `---`. R4-R7 closed. Rules stayed as byte-identical copies with a banner
  rather than pointers — deviation recorded under D11.
- **E — `numerosis:install` now sets `numerosis.models.*`** (`21c4b69`). The
  gap was live for thin-app, which had all 9 stubs published and zero keys
  set. Two things worth keeping:
  1. **phpdotenv throws `InvalidFileException` for the whole file on a
     double-quoted class-string** (`"App\Models\Central\Tenant"` — `\M` is an
     unrecognised escape), so a naive write here would have stopped the host
     booting entirely, with an error naming neither the key nor this package.
     Single-quoted and bare both parse correctly. Found by testing phpdotenv
     directly before writing the code, which is the only reason it did not
     ship.
  2. **`--verify-only` exists because the default run publishes files and
     writes `.env`** — neither belongs in a test process. It is also genuinely
     useful for re-checking an existing install.

Verified E end to end on thin-app rather than in the harness alone: 9 keys
written, app boots, `Numerosis::model()` resolves to `App\Models\Central\Tenant`,
second run idempotent.

## 2026-08-06 (second session) — R9 closed, failures re-bucketed

Continued the letter list from the review; F shipped, G stopped part-way and
is now step 1 of the plan's list.

**F — `docs/host-requirements.md` and `numerosis:install` now verify each
other** (`888d07a`). Every doc row carries a "Checked by" cell naming the
method that asserts it, or an em-dash plus a reason;
`tests/Feature/Docs/HostRequirementsTest.php` enforces both directions.
Ten checks were added to close the gap the column exposed.

Three things worth keeping from it:

1. **The expanded checks immediately found a real bug in thin-app**, which
   had passed every earlier install run: `config/tenancy.php` still listed
   both bootstrappers under `App\Services\Tenancy\Bootstrappers\*`, classes
   that only ever existed in the monolith. Tenancy would have fatalled on
   first initialisation, taking `AuthGuardBootstrapper` with it. Nothing in
   thin-app had initialised tenancy yet, so nothing had failed.
   (`thin-app@11b29d1`.)
2. **Two of the ten checks were stricter than the invariant, and the host is
   what proved it.** `seeder_parameters['--class'] => 'DatabaseSeeder'` is
   valid config — `SeedCommand::getSeeder()` resolves an unqualified name
   under `Database\Seeders` — and the lock-wait pair is *optional*; what is
   not optional is setting one without the other. Both check and doc row
   were corrected rather than the host being bent to fit them. **A check
   written from a doc row is a hypothesis until a real host runs it.**
3. **The drift test was verified by mutation, not by going green**: renaming
   a method in the doc, and adding an undocumented `verify*` method, each
   fail it.

Process note, for whoever hits it next: undoing that second mutation with
`git checkout src/Commands/InstallNumerosisCommand.php` also discarded the
ten uncommitted checks in the same file, which had to be rewritten. **Revert
a mutation with a targeted edit, never with `git checkout` on a file that
carries uncommitted work.**

**G — failures re-bucketed, largest bucket half-diagnosed.** Full run at
`888d07a`: 32 failed / 333 passed / 7 skipped / 1 risky in 52s. The
per-cause table is in the plan's step 1 and is not repeated here. The one
finding that changes what happens next: **`RoleResourceUiTest` and
`CancelModuleTest` both reproduce `Unknown database 'tenantX'` running
alone**, so the 15-failure bucket is not the cross-test contention it has
been filed under for three sessions — `testing.md`'s class only appears in a
full run. It is a real harness or package bug, reproducible in ~4.5s.
Session stopped before pulling the stack for a single test.

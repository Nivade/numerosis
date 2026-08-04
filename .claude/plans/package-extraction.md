# Plan: Split saas-m into `nvade/numerosis` (package) + `thin-app` (deployable)

**Audience: an executing agent with no prior context.** Every step states the
repo, the exact command, what "done" looks like, and what to do when it fails.
Do not improvise past a step's text. When a step says STOP, stop and ask.

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

---

## 0. Ground rules — read before touching anything

### 0.1 Which repo, which PHP

Three repos, three different ways to run commands. Mixing them is the single
most likely mistake.

| Repo | Path | How to run PHP | PHP version |
|---|---|---|---|
| saas-m | `~/repos/private/saas-m` | **Always** `vendor/bin/sail …` (Docker) | 8.5 in container |
| numerosis | `~/repos/private/numerosis` | **Never** sail. Plain `php`, `composer`, `vendor/bin/pest` on the host | 8.4.1 host |
| thin-app | `~/repos/private/thin-app` | `vendor/bin/sail …` once docker/ is copied in (Phase 7) | 8.5 in container |

Examples that are correct:

```bash
cd ~/repos/private/saas-m    && vendor/bin/sail artisan test --compact
cd ~/repos/private/numerosis && vendor/bin/pest --ci
cd ~/repos/private/numerosis && vendor/bin/phpstan analyse --no-progress
```

Never run `vendor/bin/sail` inside numerosis (there is no docker-compose there).
Never run bare `php artisan` inside saas-m.

### 0.2 Absolute prohibitions

1. **Never delete `~/repos/private/saas-m`.** It is archived at the very end,
   never deleted. Its commit hashes are cited by `.claude/rules/*`.
2. **Never `git push --force`** in any of the three repos.
3. **Never run destructive DB commands** (`migrate:fresh`, `DROP DATABASE`)
   against anything but the `testing` database. See
   `.claude/rules/testing.md` for the recovery procedure if this happens
   anyway.
4. **Never run two test suites at once.** `.claude/rules/testing.md`: two
   concurrent runs collide on token databases and look like a hang.
5. **Never add `--parallel`** to the saas-m suite. Removed deliberately.
6. **Never squash migrations** as part of this extraction (see 6.4).
7. **Never make numerosis `require` any `nvade/<module>` package** — the 6
   modules stay app-side path repos.
8. **Never edit files in two repos for the same change once Phase 4 starts.**
   After the freeze (4.1), saas-m is read-only.

### 0.3 Reference material that must be read, not guessed

`.claude/rules/` in saas-m holds hard-won facts. Read the file named before
touching its area; they are short:

| Area you are touching | Read first |
|---|---|
| tenant creation, provisioning chain | `tenant-provisioning.md` |
| guards, `web` vs `tenant`, policies | `auth-guards.md` |
| login screens, rate limiting | `auth-login.md` |
| checkout, Stripe, subscriptions | `billing-checkout.md` |
| Filament panels/tests, `{tenant}` param | `filament-tenancy.md` |
| modules, `$tenant->run()` | `module-marketplace.md` |
| caches, `global_cache()` | `tenant-caching.md` |
| uploads, disks | `tenant-filesystem.md` |
| exceptions, queue failures | `exception-handling.md` |
| running tests, teardown, baseline | `testing.md` |
| PHPStan level/baseline | `static-analysis.md` |

### 0.4 Known-good baselines (so you can tell your breakage from existing breakage)

- saas-m full suite: **0 failures, 1 skipped, 431 passed** (updated
  2026-08-05 — see Phase 3 status above for the fixes that got it here from
  the old 22/9-failure baselines). Any failure is yours.
- saas-m PHPStan: **red on master** — ~98 errors outside the 44-entry baseline
  (9 under `app/`, 89 under `tests/`). Compare counts before and after your
  change; do not try to reach zero.
- numerosis: `vendor/bin/pest` 2 passed; `vendor/bin/phpstan analyse` clean at
  level 9. These must stay green at every step.

---

## 1. What is being built

### 1.1 Three repos, final state

| Repo | Role | Fate |
|---|---|---|
| `numerosis` | The package: tenancy + billing core required, everything else opt-in via feature classes. Proprietary, private (`git@github.com:Nivade/numerosis.git`). | Survives |
| `thin-app` | The deployable Laravel app. Owns `bootstrap/app.php`, `docker/`, `.env`, vite build, the 6 app-modules, concrete models. Requires `nvade/numerosis`. | Survives, is deployed |
| `saas-m` | Today's monolith (`git@gitlab.com:nvade_/saas-m.git`). | **Archived read-only** at the very end |

A package cannot be deployed and a Testbench workbench app is a dev harness,
not a production target — that is why `thin-app` exists.

### 1.2 Decisions already made (do not re-open)

1. **Content copy, no git-history graft.** Fresh commits in numerosis and
   thin-app. saas-m archived so its history stays readable.
2. **Namespace `Nvade\Numerosis\` in `src/`.** thin-app keeps `App\`.
3. **Feature classes, not booleans.** `App\Contracts\NamedFeature` +
   `App\Features\*` listed in `config('numerosis.features')`. Already built.
4. **Config-driven models + contracts.** Package ships abstract bases and
   contracts; thin-app owns concrete classes; config points at them.
5. **Filament panels become plugins.** The package never owns a panel.
6. **Modules stay app-side.** numerosis ships the module *system* only. The 6
   modules (alerts, announcements, branding, **chat**, notes, tasks) move to
   `thin-app/app-modules/*` as symlinked path repos.
7. **Proprietary, private git only.** No Packagist. thin-app requires numerosis
   through a path repo during development.

### 1.3 Already landed inside saas-m (do not redo)

- `App\Contracts\{Feature,NamedFeature}`, `App\Support\Features`, 11
  `App\Features\*` classes, `config/numerosis.php` (feature list + `schedule`
  booleans + `routes.names.{home,tenants_mine}`), `App\Support\Routes\RouteNames`.
- `AppServiceProvider::boot()` bootstraps features via
  `Features::all()` → `$this->app->make($feature)->bootstrap()`.
- `bootstrap/app.php` no longer hardcodes hosts (bare `trustHosts()`).
- `App\Contracts\Auth\{CentralUserModel,TenantUserModel}` +
  `App\Services\Tenancy\UserModelResolver` reading
  `tenancy.central_user_model` / `tenancy.tenant_user_model`.
- Deliberate non-additions, with reasons recorded in code: `TenantModel`
  contract (stancl's `TenantWithDatabase` covers it),
  `SubscriptionModel`/`PaymentPlanModel` (already swappable),
  `HasTenants::tenants()` generic (breaks PHPStan; docblock explains).

---

## Phase 0 — Fix the package skeleton ✅ DONE 2026-08-04

Recorded for provenance; do not repeat.

- `phpstan.neon.dist`: level 5 → **9**, added `treatPhpDocTypesAsCertain: false`.
- `composer.json`: removed `minimum-stability: dev`; pinned
  `illuminate/contracts ^13.0`, `orchestra/testbench ^11.0`;
  `"license": "proprietary"`; homepage → `github.com/Nivade/numerosis`;
  workbench autoload paths; `build`/`serve`/`clear`/`lint` scripts.
- `LICENSE.md` MIT → proprietary. `README.md` rewritten. `FUNDING.yml` removed.
- `vendor/bin/testbench workbench:install` run → `workbench/` + `testbench.yaml`.
- `.gitignore` no longer ignores `testbench.yaml` (still ignores `phpstan.neon`
  and `phpunit.xml`; the committed configs are the `.dist` variants).
- CI `run-tests.yml` matrix narrowed to ubuntu / php 8.4+8.5 / Laravel 13 /
  testbench 11 / prefer-stable.
- Verified: `vendor/bin/pest --ci` 2 passed, `vendor/bin/phpstan analyse` clean.

**Outstanding in numerosis:** the working tree still holds the uncommitted
skeleton-configure diff (the one existing commit is raw generator output). Commit
it before Phase 4 — see step 4.0.

---

## Phase 1 — De-hardcode inside saas-m

All of Phase 1 happens in **saas-m**, with sail, while the app is still whole
and its tests still run. Nothing is copied yet.

After **every** step in this phase:

```bash
cd ~/repos/private/saas-m
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail exec -T laravel.test bash -lc "vendor/bin/phpstan analyse" | tail -5
vendor/bin/sail artisan test --compact --filter=<the tests for what you touched>
```

PHPStan must not report *more* errors than before your change. Tests you touched
must pass.

### 1.1 Create the `Numerosis` manager + facade

**Why:** several later steps need a single object to hold package-level
registrations (`addTenantColumns`, `routes`, `middleware`). It does not exist
yet — `app/Facades/` holds only `Billing.php`.

**Do:**
1. `vendor/bin/sail artisan make:class Support/Numerosis --no-interaction`
   (file: `app/Support/Numerosis.php`, namespace `App\Support`).
2. Give it: `private static array $tenantColumns = []`,
   `public static function addTenantColumns(array $columns): void`,
   `public static function tenantColumns(): array`.
3. `vendor/bin/sail artisan make:class Facades/Numerosis --no-interaction`,
   make it extend `Illuminate\Support\Facades\Facade`, accessor returns
   `App\Support\Numerosis::class`, `@method static` docblock like
   `app/Facades/Billing.php`.
4. Bind it in `AppServiceProvider::register()` the same way `Billing` is bound
   (read `app/Providers/BillingServiceProvider.php` first).

**Done when:** `vendor/bin/sail artisan tinker --execute 'Numerosis::addTenantColumns(["x"]); dump(Numerosis::tenantColumns());'` prints `["x"]`.

### 1.2 `Tenant::getCustomColumns()` reads the manager

**Read first:** `.claude/rules/tenant-provisioning.md`, bullet "`tenants` column
only exists if `Tenant::getCustomColumns()` names it".

**Do:** in `app/Models/Central/Tenant.php`, return the existing static list
merged with `Numerosis::tenantColumns()`. Add a docblock stating the ordering
rule: **`addTenantColumns()` must be called from a service provider's
`register()`, before any tenant model boots or saves.** A late call silently
folds the column into the `data` JSON blob — the exact bug this API prevents.

**Done when:** `vendor/bin/sail artisan test --compact --filter=TenantColumns`
passes (`tests/Feature/Models/Central/TenantColumnsTest.php`, which reads the raw
row — do not weaken it).

### 1.3 Finish route-name indirection

**Current state:** `config('numerosis.routes.names')` holds only `home` and
`tenants_mine`; `App\Support\Routes\RouteNames` reads them.

**Do:** add `invitation_show` (`invitation.show`) and `checkout_subscription`
(`checkout.subscription`) to the config array and to `RouteNames`, then replace
those literals at their call sites:

```bash
cd ~/repos/private/saas-m
grep -rn "'invitation\.show'\|\"invitation\.show\"" app resources routes
grep -rn "'checkout\.subscription'\|\"checkout\.subscription\"" app resources routes
```

Replace each hit with the `RouteNames` accessor. Leave the `route()` calls whose
names are never gated by a feature alone — only these four names matter.

**Done when:** both greps return only the route *definition* sites and
`RouteNames` itself; `vendor/bin/sail artisan test --compact --filter=Invitation`
passes.

### 1.4 Tenant domain pattern out of code

**Do:** in `app/Providers/Filament/TenantAdminPanelProvider.php`, replace
`->tenantDomain('{tenant}.nvade.dev')` with
`->tenantDomain(Config::string('numerosis.domains.tenant_pattern'))`, and add to
`config/numerosis.php`:

```php
'domains' => [
    'tenant_pattern' => env('NUMEROSIS_TENANT_DOMAIN', '{tenant}.'.parse_url((string) env('APP_URL'), PHP_URL_HOST)),
],
```

Add `NUMEROSIS_TENANT_DOMAIN` to `.env.example`.

**Verify no literals remain:**

```bash
grep -rn "nvade\.dev" app config routes bootstrap resources
```

Expect zero hits under `app/`, `config/`, `routes/`, `bootstrap/`.

**Done when:** `vendor/bin/sail artisan test --compact --filter=TenantAdmin`
passes. **If Filament tests fail with `Missing required parameter for [Route:
filament.tenantAdmin…]`,** that is the `{tenant}` trap, not your change — read
`.claude/rules/filament-tenancy.md` and use
`Tests\TestCase::actingAsTenantPanelUser()`.

### 1.5 Livewire wizard views must not use `resource_path()`

**File:** `app/Features/Tenancy/RegistrationWizardFeature.php` (lines ~42-57)
registers four components with `viewPath: resource_path('views/livewire/tenant/registration/…')`.
Those paths do not exist inside a package.

**Do:** register by *view name* instead
(`Livewire::component('tenant.registration.steps.plan', Plan::class)` with the
component rendering `view('numerosis::livewire.tenant.registration.steps.plan')`),
or keep `viewPath` but resolve it from a package-aware base path constant. Do not
hardcode `resource_path()`.

**Trap — read `.claude/rules/billing-checkout.md`:** the `Payment` step's alias
is `tenant.registration.steps.payment`, *not* `payment`, because Cashier's
published view already owns that name. Resolve aliases with
`app('livewire.finder')->normalizeName(Payment::class)`; never hardcode.

**Done when:** `vendor/bin/sail artisan test --compact --filter=Registration`
passes.

### 1.6 Cache-key prefix becomes configurable

**Read first:** `.claude/rules/tenant-caching.md`.

**Do:** in `app/Support/Cache/CacheKeys`, prefix every key with
`Config::string('numerosis.cache.prefix', 'numerosis')`. Do not change which
keys are tenant-scoped (`Cache::`) versus global (`global_cache()`) — that
distinction is the whole point of the class. Do not reintroduce
`rememberForever`.

**Done when:** `vendor/bin/sail artisan test --compact --filter=FindUserByGlobalId`
passes. That test needs `Tests\Concerns\PinsGlobalCache`; without the pin a
cross-tenant cache test passes against broken code.

### 1.7 Config split

**Do:** move keys out of `config/billing.php` into two new files:

- `config/numerosis-tenancy.php` ← `provisioning.steps`, `TenantDomainPolicy`,
  `ProvisionsTenant` binding.
- `config/numerosis-billing.php` ← plans, gateways, implementations (the rest of
  today's `billing.php`).

Keep `config/numerosis.php` for features, models, routes, domains, guards, cache.

Update every reader:

```bash
grep -rn "config('billing\.\|Config::[a-z]*('billing\." app routes database tests | wc -l
grep -rln "billing\." app routes database tests
```

Rewrite each hit to the new key. Leave `config/cashier.php` alone (Cashier owns
it).

**Done when:** `grep -rn "'billing\." app routes database tests` returns zero
hits and `vendor/bin/sail artisan test --compact --filter=Billing` passes.

### 1.8 Dependency triage — produces a document, not code

**Do:** create `~/repos/private/numerosis/DEPENDENCIES.md` listing every package
in saas-m's `composer.json` `require` block with a verdict:

- **require** (package cannot work without it): `stancl/tenancy`,
  `laravel/cashier`, `spatie/laravel-permission`, `spatie/laravel-data`,
  `lorisleiva/laravel-actions`, `spatie/laravel-package-tools`.
- **suggest + `class_exists()` guard** (feature-gated): `filament/filament`,
  `livewire/flux`, `laravel/socialite`, `ryangjchandler/laravel-cloudflare-turnstile`,
  `sentry/sentry-laravel`, `laravel/telescope`, `laravel/reverb`,
  `pusher/pusher-php-server`, `alizharb/filament-activity-log`,
  `openplain/filament-shadcn-theme`, `dompdf/dompdf`,
  `spatie/laravel-livewire-wizard`, `spatie/laravel-one-time-passwords`,
  `socialiteproviders/*`, `internachi/modular`, `mallardduck/blade-lucide-icons`.
- **thin-app only** (never in the package): `laravel/tinker`, `nvade/*` modules.

Every `suggest`ed package needs its guard written **before** its code is copied
in Phase 4, otherwise installing numerosis drags the whole stack in.

**Done when:** the file exists and every entry in saas-m's `require` block
appears exactly once.

---

## Phase 2 — Complete the feature layer (still in saas-m)

### 2.1 Feature classes for the surfaces that are still unconditional

Add, following the shape of `app/Features/Auth/PasswordResetFeature.php`
(a `NAME` const, `featureName()`, `bootstrap()`, and a docblock explaining what
turning it off removes):

| New class | Gates |
|---|---|
| `App\Features\Ui\AdminPanelFeature` | `AdminPanelProvider` registration |
| `App\Features\Ui\TenantPanelFeature` | `TenantAdminPanelProvider` registration |
| `App\Features\Tenancy\MembershipsFeature` | tenant membership UI + routes |

Register each in `config/numerosis.php`'s `features` array with a comment in the
same style as its neighbours. **Chat is not a feature class** — it is an
app-side module, gated by `ModuleSystemFeature` plus the module's own row.

### 2.2 Prove the flag actually gates everything

**Do:** add `tests/Feature/Features/FeatureIsolationTest.php`. For each feature
class: boot the app with that class removed from `Features::forceForTesting()`
(set **before** `parent::setUp()` — see the docblock on `App\Support\Features`),
then assert none of its routes resolve, its Livewire components are not
registered, and its policies are not bound.

**Done when:** the test passes with all features on *and* with each one
individually off.

---

## Phase 3 — Design the host-app seam (still in saas-m)

Everything in `bootstrap/app.php` cannot ship inside a package. Phase 3 builds
the API that thin-app will call; it does not create thin-app yet.

### 3.1 `Numerosis::middleware()` and `Numerosis::routes()`

**Do:** add to `App\Support\Numerosis`:

```php
public static function middleware(\Illuminate\Foundation\Configuration\Middleware $middleware): void
public static function routes(): void
public static function broadcasting(): array   // the channel-route middleware stack
public static function csrfExceptions(): array // ['stripe/*', 'billing/webhook', 'telescope/*']
```

Move the bodies out of `bootstrap/app.php` into these methods verbatim:

- aliases `invitation.status`, `tenancy.identification`, `tenancy.route`,
  `tenancy.session`;
- groups `tenant` (= `web`, `tenancy.identification`, `tenancy.route`,
  `tenancy.session`) and `universal` (empty on purpose);
- the `foreach (Config::array('tenancy.central_domains') …)` route loop and the
  `Route::middleware('tenant')` group.

Then make `bootstrap/app.php` call them. **Behaviour must not change** — this is
a pure move.

**Trap:** `TenancyServiceProvider::makeTenancyMiddlewareHighestPriority()` must
keep running; `.claude/rules/auth-guards.md` and `filament-tenancy.md` both
depend on identification running before everything else.
`EnsureSessionMatchesTenant` must stay registered **after** `StartSession`.

**Done when:** the full suite's failure count is still 9 (`vendor/bin/sail
artisan test --compact`, ~5 minutes; this is one of the few times a full run is
warranted).

### 3.2 Write down the config the package cannot own

**Do:** create `~/repos/private/numerosis/docs/host-requirements.md` listing,
for each file, the exact keys thin-app must provide:

| File | What thin-app must own |
|---|---|
| `config/tenancy.php` | stancl's own filename — publishing its stub silently drops our bootstrappers, because `mergeConfigFrom` merges one level deep. thin-app owns the whole file; the package only documents the required keys: `tenant_model`, `domain_model`, `central_user_model`, `tenant_user_model`, `bootstrappers` (incl. `SpatiePermissionsBootstrapper`, `AuthGuardBootstrapper`), `migration_parameters`, `seeder_parameters`, `central_domains`, `filesystem.disks` (must **not** contain `livewire`) |
| `config/database.php` | `central` + `tenant` connections, `DB_LOCK_WAIT_TIMEOUT` init statement setting **both** `lock_wait_timeout` and `innodb_lock_wait_timeout` |
| `config/auth.php` | `defaults.guards.context.central = 'web'`, `…context.tenant = 'tenant'`, both providers |
| `config/session.php` | `SESSION_DOMAIN` with a leading dot |
| `config/filesystems.php` + `config/livewire.php` | the dedicated `livewire` disk, absent from `tenancy.filesystem.disks` |
| `config/cashier.php`, `config/permission.php`, `config/broadcasting.php` | as published by their own packages |

Copy the *reasons* from `.claude/rules/{tenant-filesystem,auth-guards,testing}.md`
into that doc; a consumer without the reason will "fix" it wrong.

---

## Phase 4 — Freeze, copy, rename

This is the irreversible-feeling phase. Read it fully before starting.

### 4.0 Preconditions (all must hold)

```bash
cd ~/repos/private/saas-m    && git status --short           # empty
cd ~/repos/private/saas-m    && vendor/bin/sail artisan test --compact | tail -3   # 9 failures, no more
cd ~/repos/private/numerosis && git status --short           # empty (commit Phase 0 first)
cd ~/repos/private/numerosis && vendor/bin/pest --ci && vendor/bin/phpstan analyse --no-progress
```

If numerosis still shows the uncommitted Phase-0 diff, commit it now:

```bash
cd ~/repos/private/numerosis
git add -A && git commit -m "chore: configure package skeleton for numerosis"
```

### 4.1 Freeze saas-m

From this point saas-m is **read-only**. No feature work, no fixes there. If
something must change, change it in numerosis or thin-app. Announce the freeze
in the commit message of the last saas-m commit.

### 4.2 Copy — exact destinations

Run from `~/repos/private/saas-m`. Create destination directories as needed.

**`app/` → `numerosis/src/` (whole directories, no exceptions except Providers):**

| Source | Destination | Notes |
|---|---|---|
| `app/Actions` (69) | `src/Actions` | |
| `app/Concerns` (8) | `src/Concerns` | |
| `app/Console` (7) | `src/Console` | commands registered by the package provider |
| `app/Contracts` (26) | `src/Contracts` | |
| `app/Data` (10) | `src/Data` | |
| `app/Enums` (5) | `src/Enums` | |
| `app/Events` (10) | `src/Events` | |
| `app/Exceptions` (27) | `src/Exceptions` | |
| `app/Facades` (1) | `src/Facades` | plus the new `Numerosis` facade |
| `app/Features` (11+3) | `src/Features` | |
| `app/Filament` (96) | `src/Filament` | converted to plugins in Phase 8 |
| `app/Http` (15) | `src/Http` | |
| `app/Jobs` (1) | `src/Jobs` | |
| `app/Listeners` (10) | `src/Listeners` | |
| `app/Livewire` (18) | `src/Livewire` | |
| `app/Models` (20) | `src/Models` | become `abstract`; see 4.4 |
| `app/Notifications` (5) | `src/Notifications` | |
| `app/Observers` (9) | `src/Observers` | |
| `app/Policies` (6) | `src/Policies` | |
| `app/Rules` (1) | `src/Rules` | |
| `app/Services` (21) | `src/Services` | |
| `app/Support` (6) | `src/Support` | |
| `app/Testing` (1) | `src/Testing` | ship publicly |

**`app/Providers` (6) — split, do not bulk-copy:**

| File | Goes to |
|---|---|
| `TenancyServiceProvider.php` | `numerosis/src/Providers/` |
| `BillingServiceProvider.php` | `numerosis/src/Providers/` |
| `AppServiceProvider.php` | **thin-app** (`app/Providers/`), keeping only app-specific bindings; the feature-bootstrap loop moves into `NumerosisServiceProvider` |
| `TelescopeServiceProvider.php` | **thin-app** |
| `Filament/AdminPanelProvider.php` | **thin-app** (it will register the package plugin) |
| `Filament/TenantAdminPanelProvider.php` | **thin-app** (same) |

**Everything else:**

| Source | Destination |
|---|---|
| `routes/{web,auth,tenant,channels,console}.php` (206 lines total) | `numerosis/routes/` — loaded conditionally per feature |
| `database/migrations/*.php` (64 files) | `numerosis/database/migrations/central/` |
| `database/migrations/tenant/*` (25) | `numerosis/database/migrations/tenant/` |
| `database/seeders/{DatabaseSeeder,PaymentPlanSeeder,RoleAndPermissionSeeder,TenantDatabaseSeeder}.php`, `database/seeders/Central/`, `database/seeders/Tenant/` | `numerosis/database/seeders/` — namespace `Nvade\Numerosis\Database\Seeders`; thin-app keeps a `Database\Seeders\DatabaseSeeder` that calls them |
| `database/factories/**` | `numerosis/database/factories/` (namespace `Nvade\Numerosis\Database\Factories`) |
| `resources/views/{components,filament,flux,layouts,livewire,pages,partials}` (~105) | `numerosis/resources/views/` |
| `resources/views/errors` (10), `resources/views/vendor` (35) | **thin-app** `resources/views/` |
| `resources/{css,js}` | `numerosis/resources/` — sources only; thin-app owns the vite build (Phase 9) |
| `lang/en`, `lang/vendor` | `numerosis/resources/lang/` (`loadTranslationsFrom`, namespace `numerosis`) |
| `tests/**` (130 files) | `numerosis/tests/` except the ones listed in 6.3 |
| `app-modules/{alerts,announcements,branding,chat,notes,tasks}` | **thin-app** `app-modules/` |
| `docker/`, `docker-compose.yml`, `vite.config.js`, `package.json`, `bootstrap/`, `public/`, `artisan`, `.env.example` | **thin-app** root |
| `.claude/` (rules, plans), `CLAUDE.md`, `AGENTS.md`, `pint.json`, `rector.php`, `boost.json` | **both** numerosis and thin-app |
| `config/*.php` | see 3.2 — package configs to numerosis, framework/vendor configs to thin-app |

### 4.3 Namespace rewrite

After copying into `numerosis/src`:

```bash
cd ~/repos/private/numerosis
grep -rl 'App\\' src tests database routes | xargs sed -i \
  -e 's/namespace App\\/namespace Nvade\\Numerosis\\/g' \
  -e 's/use App\\/use Nvade\\Numerosis\\/g' \
  -e 's/\\App\\\\/\\Nvade\\\\Numerosis\\\\/g'
grep -rn "App\\\\" src | grep -v "Nvade" | head -40      # expect: only genuine app-side references
```

Then, one directory at a time (start with `src/Contracts`, `src/Enums`,
`src/Data` — the leaves), run:

```bash
composer dump-autoload
vendor/bin/phpstan analyse --no-progress | tail -20
```

**Rule:** do not proceed to the next directory while PHPStan reports unresolved
classes in the current one. `Class "…" not found` here almost always means a
missed `use` rewrite, not a broken autoloader — the same pattern
`.claude/rules/testing.md` documents.

**Factories cannot be checked statically.** Laravel resolves
`App\Models\Tenant\User` → `Database\Factories\Tenant\UserFactory` from a
runtime-built string. After the rewrite, `Nvade\Numerosis\Models\Tenant\User`
must find `Nvade\Numerosis\Database\Factories\Tenant\UserFactory`. Set the
resolver explicitly in the package `TestCase` (the skeleton already has a
`Factory::guessFactoryNamesUsing` call — extend it to keep sub-namespaces, not
just `class_basename`).

### 4.4 Models: abstract base in the package, concrete in thin-app

For each of `Tenant`, `Domain`, `CentralUser`, `Tenant\User`, `Subscription`,
`PaymentPlan`, `Invitation`, `Module`, `PendingTenantProvision`:

1. In numerosis, make the class `abstract` and keep all behaviour.
2. Ship a stub under `numerosis/stubs/` that thin-app publishes:
   `class Tenant extends \Nvade\Numerosis\Models\Central\Tenant {}`.
3. Point config at the concrete class (`tenancy.tenant_model`,
   `tenancy.central_user_model`, `tenancy.tenant_user_model`,
   `billing.models.*`).

**Traps that will bite here:**
- `#[UsePolicy]` attributes are **not inherited** — see `.claude/rules/auth-guards.md`.
  Whatever carries the attribute today must still carry it after the split.
- `Tenant` composes `VirtualColumn`; `getCustomColumns()` and `Fillable` must
  travel together (`.claude/rules/tenant-provisioning.md`).
- `CentralUser::guardName()` returns `'web'`, `Tenant\User::guardName()` returns
  `['tenant']`. Do not "fix" these to be context-dependent.

---

## Phase 5 — Wire the package

All in numerosis, host PHP.

### 5.1 `NumerosisServiceProvider`

Replace the skeleton's `configurePackage()` body so it:

- `mergeConfigFrom` for `numerosis`, `numerosis-tenancy`, `numerosis-billing`;
- `loadViewsFrom(__DIR__.'/../resources/views', 'numerosis')`;
- `loadTranslationsFrom(__DIR__.'/../resources/lang', 'numerosis')`;
- `loadMigrationsFrom(__DIR__.'/../database/migrations/central')` — **central
  only**; tenant migrations are run by stancl through
  `tenancy.migration_parameters`, which must use an **absolute** path
  (`--realpath`) because the files now live under `vendor/`;
- registers `App\Support\Features`' boot loop (moved from `AppServiceProvider`);
- conditionally registers `TenancyServiceProvider` and `BillingServiceProvider`;
- declares publish groups: `numerosis-config`, `numerosis-migrations`,
  `numerosis-tenant-migrations`, `numerosis-views`, `numerosis-assets`,
  `numerosis-models`, `numerosis-stubs`.

### 5.2 `numerosis:install`

An artisan command that publishes config + model stubs, appends env keys, and
then **verifies** (fails loudly, does not merely print):

- `central` and `tenant` connections exist in `config('database.connections')`;
- `config('session.domain')` starts with a dot;
- `config('auth.defaults.guards.context.central')` and `…tenant` resolve to real
  guards;
- `'livewire'` is **not** in `config('tenancy.filesystem.disks')`;
- `config('tenancy.migration_parameters')` path exists and is absolute;
- Stripe keys are set;
- prints the manual steps: wildcard DNS, panel plugin registration, and a queue
  worker on the dedicated `provisioning` queue (see
  `docker/8.5/supervisord.conf`'s `[program:queue-provisioning]` in saas-m).

### 5.3 Missing contracts

Add, mirroring the 8 that already exist under `Contracts/Billing`:

| Contract | Replaces |
|---|---|
| `Tenancy\TenantDatabaseManager` | direct stancl job-list coupling |
| `Invitations\InvitationRepository` | direct `Invitation` queries |
| `Modules\ModuleRegistry` | `InteractsWithTenantModules`' inline queries — must serve thin-app's 6 modules without the package knowing their names |
| `Auth\SocialAccountRepository` | `SocialiteLogin` direct use |
| `Notifications\NotifiesTenantOwner` | notification class hardcoding |

Traits to ship: `IsTenantModel`, `IsCentralUser`, `IsTenantUser`,
`HasGlobalIdentity`, `BelongsToTenant`, `HasTenants`, `Billable` (move as-is),
`TagsSentryScopeWithTenant`, `PublishesPackageAssets`.

Do **not** add `Chat\*` contracts — chat is an app-side module.

---

## Phase 6 — Test harness in the package

### 6.1 MySQL, not sqlite

Tenancy needs `CREATE DATABASE`. Point `testbench.yaml` at the same MySQL saas-m
uses, or a local one. Add a MySQL service to `.github/workflows/run-tests.yml`
(the matrix comment already says this is where it lands).

### 6.2 Port the whole test-support layer, not just the fast trick

From saas-m `tests/`, all of these are load-bearing (`.claude/rules/testing.md`):

- `Support/CloneTenantSchema` — the ~0.19s vs ~1.9s per-tenant difference. It
  must implement `ShouldQueue` (chain links must be real jobs) and must **never**
  touch the default connection.
- `TestCase::deleteCentralWrites()` and `deleteTenantDatabases()` — each in its
  **own** `try/finally`; sharing one block leaks tenant databases on the ~9
  lock-timeout tests.
- `TestCase::keepSchema()` pinning `RefreshDatabaseState::$migrated = true`.
- The `TenancyServiceProvider::$tenantCreatedJobs` override, assigned from
  `tests/Pest.php` — it must run before the first app boot; `setUp()` is too late.
- `Concerns/PinsGlobalCache`.
- `TestCase::actingAsTenantPanelUser()` — export it as
  `Testing\InteractsWithTenantPanel` for consumers.
- Session variables `lock_wait_timeout` **and** `innodb_lock_wait_timeout`.

### 6.3 Which tests go where

| Test subject | Repo |
|---|---|
| actions, models, policies, billing, provisioning, Livewire components, Filament resources | numerosis |
| `bootstrap/app.php` wiring, middleware group order, vite/asset presence, module install | thin-app |
| a module's own behaviour | that module's package under `thin-app/app-modules/*` |

### 6.4 Migrations — do not squash

The live deployment's `migrations` table records today's 64+25 filenames. A
squashed set under new package paths re-runs from zero against a populated
database. If squashing is ever wanted it is separate work that also ships a
schema dump plus pre-seeded `migrations` rows.

---

## Phase 7 — Create thin-app

```bash
cd ~/repos/private
laravel new thin-app --no-interaction
cd thin-app && git init && git add -A && git commit -m "chore: laravel new"
```

Then:

1. Add the path repo and require the package:
   ```jsonc
   "repositories": [{ "type": "path", "url": "../numerosis", "options": { "symlink": true } }],
   "require": { "nvade/numerosis": "@dev" }
   ```
   `composer update nvade/numerosis`.
2. Copy from saas-m (per 4.2): `docker/`, `docker-compose.yml`, `.env.example`,
   `vite.config.js`, `package.json`, `bootstrap/app.php`, `public/`,
   `app-modules/*`, `resources/views/{errors,vendor}`, the framework configs, the
   Filament panel providers, `.claude/`, `CLAUDE.md`, `AGENTS.md`.
3. Rewrite `bootstrap/app.php` to call `Numerosis::middleware()`,
   `Numerosis::routes()`, `Numerosis::broadcasting()`,
   `Numerosis::csrfExceptions()` (built in 3.1).
4. `vendor/bin/sail up -d` then `vendor/bin/sail artisan numerosis:install`.
5. Publish and adjust the model stubs (4.4).

**Local-environment trap:** `WWWUSER`/`WWWGROUP` must be set in `.env`, or the
container runs as uid 1337 and every `artisan make:*`, Pest cache write and
Playwright run fails with confusing permission errors
(`.claude/rules/tenant-provisioning.md`, "Local environment").

---

## Phase 8 — Filament as plugins

- `NumerosisAdminPlugin` and `NumerosisTenantPlugin` implement
  `Filament\Contracts\Plugin`; thin-app's panel providers register them.
- Resource discovery currently uses `app_path('Filament/…')` in both providers —
  becomes `__DIR__`-relative with explicit `for:` namespaces, and each cluster
  individually opt-in via `->resources([...])` rather than directory discovery.
- The plugin must **not** bridge stancl's tenant into `Filament::setTenant()` at
  bootstrap. `.claude/rules/filament-tenancy.md` explains why that cannot work:
  stancl's identification is forced ahead of Filament's panel setup, so there is
  no panel to set a tenant on at that moment.
- Panels must pin their guard explicitly (`->authGuard('tenant')` /
  central guard) — riding `auth.defaults.guard` is wrong now that it moves
  mid-request.

---

## Phase 9 — Assets

Package ships **sources**, thin-app owns the build.

- `resources/css/app.css`, `resources/js/{app,bootstrap,central,tenant,stripe-appearance,stripe-checkout,stripe-confirm}.js`
  publish under `numerosis-assets`.
- `resources/views/partials/styles.blade.php` calls
  `@vite(['resources/css/app.css','resources/js/app.js'])` plus `central.js` and
  (inside tenant context) `tenant.js`. Document these as the exact vite entry
  points thin-app must declare.
- The three `stripe-*.js` files are load-bearing for checkout, not decoration —
  a missing entry point breaks payment, not styling.
- Prerequisites documented for consumers: Tailwind v4, `livewire/flux` (58 views
  use `flux:` components), `openplain/filament-shadcn-theme`.

---

## Phase 10 — Verification gate, then archive

Every item must pass. STOP and ask the user before the archive step — retiring a
repo is confirmed in the moment, never pre-authorised by this document.

```bash
# 1. package
cd ~/repos/private/numerosis
composer install && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress && vendor/bin/pest --ci

# 2. app boots
cd ~/repos/private/thin-app
vendor/bin/sail up -d && vendor/bin/sail artisan numerosis:install && vendor/bin/sail artisan route:list | head

# 3. end-to-end
#    - register a tenant through the wizard (or StartLocalCheckout on local)
#    - confirm the provisioning chain ran on the `provisioning` queue
#    - confirm tenants.provisioned_at is set (NOT just that a tenants row exists)
#    - log into the tenant panel on the tenant subdomain

# 4. modules
vendor/bin/sail artisan tenants:migrate-module branding   # then check modules.migrated_at is stamped
```

Then, and only then: archive `git@gitlab.com:nvade_/saas-m.git` read-only on
GitLab, and add to `.claude/rules/INDEX.md` in **both** new repos a line stating
that bare commit hashes in the rules refer to that archived repo.

---

## Traps that will bite, with their tells

| Symptom | Real cause | Where it is written down |
|---|---|---|
| `Missing required parameter for [Route: filament.tenantAdmin…]` | Filament's tenant not set (tests only) | `filament-tenancy.md` |
| 404 from a tenant panel | tenant `id` was generated, not set (`Fillable`) | `tenant-provisioning.md` |
| `Unknown column 'last_seen_at'` on connection `central` | ambient guard moved; tenant-only write hit central | `auth-guards.md` |
| `There is no permission named …` (500, not 403) | permissions not seeded | `auth-guards.md` |
| Upload rejected as wrong mimetype, file exists on disk | Livewire temp disk vs tenant-suffixed `local` root | `tenant-filesystem.md` |
| A user resolves with **zero** `select … from users` queries | cached Eloquent model leaking across tenants | `tenant-caching.md` |
| `Module [x] not found` after adding a module | stale in-process module registry; restart the queue worker | `module-marketplace.md` |
| Job succeeded but did nothing | `Artisan::call()` exit code discarded | `exception-handling.md` |
| Suite hangs forever | stranded MySQL session / two runs at once | `testing.md` |
| `Class "…" not found` after a rename | missed reference, not autoloader | `testing.md` |

## Order of execution

Phase 1 → 2 → 3 (all in saas-m, suite stays green) → 4 (freeze + copy + rename)
→ 5 (wire package) → 6 (harness) → 7 (thin-app) → 8 (Filament plugins) →
9 (assets) → 10 (gate, then archive).

Phases 1-3 ship value even if the extraction stalls: saas-m ends up
de-hardcoded either way.

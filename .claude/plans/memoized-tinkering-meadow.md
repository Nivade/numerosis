# Numerosis: dual-version tenancy, customizable wizard, package split

> Revised 2026-08-29 after auditing the original plan against the code, then
> **re-audited the same day** against a fresh measurement. Claims that turned
> out to be wrong are corrected inline and marked **[was wrong]** so nobody
> re-derives them from the old version. Supporting detail lives in
> `.claude/rules/stancl-tenancy-v4.md` and `.claude/rules/package-boundaries.md`
> — read both before starting, they are not summaries of this file, they carry
> facts this file only references.
>
> **Status 2026-08-30: Phases 0, 0.3, 1, 2, 3, 4 and 5 are all done.** Both
> `stancl/tenancy` matrix legs pass their test suites (v3.10.1, after Phase 5:
> 603 passed, 7 skipped, 1 pre-existing unrelated failure — `RegisterTenantTest`'s
> `assertSee('livewire.js')`, reproduced with the whole branch stashed;
> dev-master measured at Phase 2: 590 passed, 7 skipped, 0 failed, **not
> re-run since Phase 5** — do that before trusting the matrix again).
> **Both legs re-measured 2026-08-30, after Phases 5 and 6, and they agree:
> 1 failed / 7 skipped / 607 passed on each** (the failure is
> `RegisterTenantTest`'s `assertSee('livewire.js')`, pre-existing and
> unrelated). **PHPStan is now clean on both** — 0 outside a 221-entry
> baseline on stable, 0 outside a 228-entry one on dev-master; see Phase 2's
> "PHPStan on dev-master" section, which is closed.
>
> One caveat on the suite number: a single stable-leg run reported a second
> failure that did **not** recur across three further full runs and three
> targeted ones, and was never identified. Treat 607 as the baseline and that
> flake as open, not as noise to ignore.
>
> **Phase 6 agreed 2026-08-30**: six packages, adding `numerosis-ui` as a leaf;
> decisions D1–D4 and the one open item (no central-seeder seam) are in that
> section. **Phases 7–8 remain. Start at Phase 7.1 (scaffold four… now five
> satellite repos).** One prerequisite inside Phase 7 itself:
> `Numerosis::addCentralSeeder()`/`addPermissionContext()` must exist before
> 7.2 moves the modules package.
>
> **Re-audit result:** every structural claim in Phases 1, 3, 4, 6 and 7 was
> re-verified against the code and holds, line numbers included. Both baselines
> reproduced exactly at the time of the audit (4 failed / 7 skipped / 580
> passed / 83s; PHPStan 28) — see Phase 0 for what they are now.
> Six things changed: the PHPStan src/tests split (0.2), a dead `try`/`catch`
> the Phase-0.1 fix must also remove, the config-key counts and the fact that
> **the writes are the dangerous half** (1.2, which merges into 1.3), the
> current constraint being `^v3.9` rather than `^3.10` (2.1), a 14th
> subdomain-assumption site (Phase 5), and 9 rather than 7 `activity_log`
> migrations (Phase 6). Nothing found invalidates the phase ordering.

## Project constraint: breaking changes are free

**Confirmed by the maintainer 2026-08-29: there are no existing installs and
the app is not live. Backwards compatibility is not a constraint on any phase
of this plan.**

That is load-bearing, not a footnote — several decisions in this repo (and in
`.claude/rules/`) were made *on the opposite assumption* and should be revisited
rather than inherited:

- **Migrations may be rewritten in place, renamed, squashed, or deleted.** The
  usual objection — "the `migrations` table records the old name, so a renamed
  file re-runs and fails on an existing table" — has nobody to hurt. This
  directly reopens the three colliding stock-Laravel basenames
  (`0001_01_01_00000{0,1,2}_*`) that `.claude/rules/host-integration-quickstart.md`
  records as *"Still not fixed, deliberately"*, and the dropped
  `jobs`/`cache`/`cache_locks`/`sessions` tables recorded in
  `.claude/rules/testing.md`.
- **Public API may be narrowed, renamed, or removed** — contracts, `Numerosis::*`
  statics, config keys, model classes — without a deprecation cycle. Phases 3
  (contribution seams), 5 (identification modes) and 7 (the split) all get
  cheaper: prefer the right shape over an additive one.
- **Composer constraints may be raised freely.** Phase 2.1's `^v3.9` → `^3.10`
  floor raise needs no `UPGRADING.md` ceremony; just state it.
- **`CHANGELOG.md`/`UPGRADING.md` still get written**, because they are how the
  *first* real host will read this history — but they are documentation, not a
  compatibility contract, and nothing here should be shaped to keep them short.

Where a rule file argues "we can't do X because it breaks existing installs",
treat the *mechanism* it documents as still true and the *conclusion* as void.

## Toolchain (this repo, not the saas-m host)

`CLAUDE.md`'s `vendor/bin/sail …` guidance does **not** apply here — there is
no Sail in this repo. See `.claude/rules/testing.md`'s first bullet.

```bash
docker compose ps                                    # numerosis-mysql-1 must be healthy
php -d memory_limit=1G vendor/bin/pest --compact     # full suite, ~82s
vendor/bin/pest --compact --filter=SomeTest          # one file
vendor/bin/pint                                      # format
# PHPStan needs a writable tmpDir — see .claude/rules/static-analysis.md
printf 'includes:\n  - %s/phpstan.neon.dist\nparameters:\n  tmpDir: /tmp/phpstan-audit\n' "$PWD" > /tmp/phpstan-audit.neon
php -d memory_limit=2G vendor/bin/phpstan analyse -c /tmp/phpstan-audit.neon --no-progress
```

## The hard constraint, stated precisely

`stancl/tenancy:dev-master` must be supported. The constraint that delivers
this is **`^3.10 || dev-master`**, and it is important to be honest about what
that does and does not buy:

- It **does** mean this package's code, tests, and CI run against dev-master.
- It **does not** mean hosts get dev-master. Composer stability flags come
  from the root package only, so every ordinary host resolves to `v3.10.1`.
  A host reaches v4 only by adding `minimum-stability: dev` **and** explicit
  requires for both `stancl/tenancy: dev-master` and
  `stancl/jobpipeline: 2.0.0-rc7`. Nothing on this side can change that
  until stancl tags a release.

There is no v4 tag and `dev-master` has no `branch-alias`, so the constraint
string is literally `dev-master`. Full reasoning: `.claude/rules/stancl-tenancy-v4.md`.

---

## Phase 0 — repair the baseline ✅ DONE (2026-08-29)

**Both gates are green. Measured on branch `package-scope-reduction`:**

| | plan's starting point | now |
|---|---|---|
| `php -d memory_limit=1G vendor/bin/pest --compact` | 4 failed, 7 skipped, 580 passed | **0 failed, 7 skipped, 584 passed** (5394 assertions, ~91s) |
| PHPStan (`tmpDir` invocation) | 28 errors outside baseline | **0 errors**; baseline 230 → 216 entries |
| `vendor/bin/pint --dirty --test` | — | passes |

Numbers recorded in `.claude/rules/testing.md` and `.claude/rules/static-analysis.md`
as the plan required. **Phase 1 may start from here.**

### 0.1 — `FreshHostTest`: four causes, not one

The plan predicted "4 failures, one cause". Wrong — the SQLite index fix only
unmasked the next one. Each was a real defect that only this harness can see:

1. **Index dropped before the column it names.** Fixed in
   `2025_06_25_105704_update_payment_plan_features.php`. The plan's re-audit
   note was right that the two `try { dropColumn() } catch (Exception)` blocks
   are a lie — `Blueprint::dropColumn()` only queues a command that executes
   after the closure returns — and they were deleted with the fix.
2. **`dropForeign('constraint_name')` is unsupported on SQLite.**
   `2026_01_07_001248_unfuck_payment_plans_and_features.php` now uses the
   column-array form behind `Schema::hasForeignKey()`.
3. **`QUEUE_CONNECTION`/`CACHE_STORE` are `database`, not `sync`/`array`.** The
   test's own comments asserted otherwise; Testbench's skeleton `.env` matches
   a fresh Laravel install. Both now set explicitly in `setUp()`.
4. **`Illuminate\Support\Env::$repository` is static and memoized**, wrapped in
   phpdotenv's `ImmutableWriter` whose `$loaded` set accumulates across app
   boots — so a test's `putenv()` only wins on the *first* boot in a process
   and testbench's `.env` silently reverts it on every boot after. This is why
   the file passed under `--filter` and failed in the full suite. Fixed with
   `Env::enablePutenv()` (public; nulls the repository) before and after boot.
   Full mechanism in `.claude/rules/testing.md`.

### 0.2 — PHPStan: 28 → 0

Causes fixed, not symptoms: missing `@throws ApiErrorException` (both "dead"
catches are live at runtime); `readonly` pinning a collection to
`Collection<*NEVER*,*NEVER*>`; `SubscriptionFactory` inheriting Cashier's
non-generic parent so every fixture typed as bare `Model`; an over-wide
`Stancl\Tenancy\Contracts\Tenant` hint on `FinalizeTenantProvisioning` that
forced a dead `instanceof`; three tautological `assertInstanceOf` calls; a
`?->` chained onto an action's `::run()`. 16 stale baseline entries removed,
driven from PHPStan's own `ignore.unmatched` output.

**Two baseline entries were added deliberately**, against this plan's "do not
widen the baseline" instruction. Both are artifacts of the app Larastan boots
for analysis differing from the one tests run, not code defects — reasoning and
evidence in `.claude/rules/static-analysis.md`. Registering the package's
provider in `testbench.yaml` fixes 16 view-string errors and stales 36 more
entries, but surfaces ~66 false positives because Larastan cannot follow
`Factory::guessFactoryNamesUsing()`; tried and reverted.

**`ArchTest` is a real gate on this kind of work.** Narrowing
`Contracts\Billing\SubscriptionRepository` to the package's own model fixed
three type errors and broke *"billing contracts do not depend on app models"*.
Run `--filter=ArchTest` after any type-narrowing pass over `src/Contracts`.

### 0.3 — reopened by "breaking changes are free" ✅ DONE (2026-08-29)

Deferred during Phase 0 **only** because shipping migrations to existing
installs was assumed costly. That assumption is now void (see "Project
constraint" above). All three items done; suite (0 failed/7 skipped/584
passed) and PHPStan (0 outside baseline) re-verified green after:

- **Recreate the `jobs` table.** `2026_01_07_195854_remove_redundant_tables.php`
  drops `jobs`/`job_batches`/`cache`/`cache_locks`/`sessions` on a Redis
  assumption `docs/host-requirements.md` never states, while a fresh Laravel
  `.env` defaults queue, cache and session to `database`. A stock host dies on
  `Base table or view not found: 1146 Table 'jobs' doesn't exist` at its
  **first** tenant creation, because the `TenantCreated` pipeline is
  `shouldBeQueued(true)`. `failed_jobs` was already recreated for exactly this
  reason (`2026_07_28_233114`). Simplest correct fix now: edit
  `remove_redundant_tables` in place to stop dropping `jobs`.
  - `cache`/`cache_locks` are **not** a straight add-back:
    `CacheTenancyBootstrapper` isolates tenants with cache *tags* and the
    `database` store is not taggable, so `CACHE_STORE=database` is unsupported
    regardless (`.claude/rules/tenant-caching.md`). Either document Redis as a
    host requirement or make `numerosis:install` fail on a non-taggable store.
  - `job_batches` has no first-party consumer (nothing uses `Bus::batch()`,
    only `Bus::chain()`). Leave dropped.
- **Rename the three colliding migration basenames.**
  `0001_01_01_00000{0,1,2}_*` are byte-identical in name to the stock Laravel
  migrations every fresh app ships, so the migrator dedupes by filename and
  silently skips one copy. `.claude/rules/host-integration-quickstart.md`
  records this as *"Still not fixed, deliberately"* purely because renaming
  breaks existing installs. Rename them.
- **Raise the `stancl/tenancy` floor without ceremony** — folds into Phase 2.1.

## Phase 1 — dual-version compat layers, built against v3 only ✅ DONE (2026-08-29)

**All three sub-phases done, verified green on the currently-installed
`v3.10.1`: suite 0 failed/7 skipped/584 passed, PHPStan 0 outside baseline
(216 → 218, two new deliberate entries documented in
`.claude/rules/static-analysis.md`-style reasoning inline in
`phpstan-baseline.neon`), ArchTest passes. No behaviour change — this phase
is pure refactor, by design.**

- **1.1** — `Support\Compat\Tenancy\*` (7 files: SyncMaster, Syncable,
  ResourceSyncing, TenantPivot, TenantWithDatabase,
  UpdateOrCreateSyncedResource, HasTenantOptions) + `Support\Tenancy\TenancyVersion`
  for the two sync events and two `::class`-string sites. Shim count matches
  the plan's "9 eager symbols" exactly — no growth, no re-run of the symbol
  diff needed. One real surprise: PHPStan resolves a conditionally-declared
  class/interface to a single canonical branch for LSP/generic checking
  **regardless of which real version is installed** — an `if
  (TenancyVersion::isDevMaster()) {...} else {...}` gate (a custom method
  call) let it flatten to the wrong branch and cascade into ~68 unrelated
  errors; switching the gate to the literal, inlined
  `class_exists(\Stancl\Tenancy\Enums\RouteMode::class)` (matching the
  existing Filament/activitylog shims' own idiom exactly) fixed the
  cascade, since PHPStan specifically recognises that literal-call form and
  picks the branch matching the real environment. `.phpstan/stancl-tenancy-dev-master.stub.php`
  (registered via `phpstan.neon.dist`'s `scanFiles`) gives PHPStan the
  dev-master-only symbols' shapes so it can resolve both branches with only
  v3 actually installed. Two narrow, documented baseline entries remain
  (`tests/Support/CloneTenantSchema.php`) — a real vendor v3 job's
  constructor, two files from the model that nominally satisfies it.
  `UpdateSyncedResource`/`LogSyncedResourceChangedInForeignDatabase` no
  longer override `handle()` with a version-typed parameter at all — an
  untyped-native, `@param`-narrowed parameter sidesteps the same
  canonical-branch problem instead of fighting it.
- **1.2/1.3** — `Support\Tenancy\TenancyConfigKeys` is the only place either
  reads or writes the 4 moved keys, in `src/` *and* `tests/` (the plan's own
  "was wrong" note that two-thirds of the sites are in tests held up —
  every one of them needed converting too, not just `HostConfig`).
  `::set()` does the read-modify-write of the whole parent array on
  dev-master, never a multi-segment dotted `Config::set()` — exactly the
  fix `.claude/rules/package-host-bootstrap.md` suggested for the
  `tenancy.database` truncation bug, now not-optional the moment a write
  targets a dev-master key.

**Checkpoint result: shim count did not grow beyond nine. Proceed to Phase 2.**

---

## Phase 1 (historical framing, kept for reference)

No constraint change in this phase. Everything here is a refactor that must
stay green on the currently-installed `v3.10.1`. Splitting it this way means
Phase 2's version bump is a small, reversible change instead of a large one.

Keep a reference checkout to verify against — never guess a v4 symbol:

```bash
git clone --depth 1 --branch master https://github.com/archtechx/tenancy.git /tmp/v4
```

**1.1 — `Support\Compat\Tenancy\*` shims for the 9 eager symbols.**
Same conditional-definition pattern as the existing `src/Support/Compat/*`
files (`.claude/rules/optional-dependencies.md` explains why `implements` /
`use <Trait>` cannot be `class_exists`-guarded at the call site and the target
symbol has to be made conditional instead). One file per symbol.

Version sentinel, used once, in one place:

```php
class_exists(\Stancl\Tenancy\Enums\RouteMode::class)   // true ⇒ dev-master
```

v3 has no `Enums` namespace at all. Do **not** parse `composer.lock` or
`InstalledVersions` — a dev-master install reports a branch name, not a
comparable version.

The nine, with their v3 → dev-master targets and the declaration sites that
must switch to the shim:

| shim covers | v3 | dev-master | declaration site |
|---|---|---|---|
| `SyncMaster` | `Contracts\SyncMaster` | `ResourceSyncing\SyncMaster` | `src/Contracts/Auth/CentralUserModel.php:14` |
| `Syncable` | `Contracts\Syncable` | `ResourceSyncing\Syncable` | `src/Contracts/Auth/TenantUserModel.php:14`, `src/Models/User.php:57` |
| `ResourceSyncing` (trait) | `Database\Concerns\ResourceSyncing` | `ResourceSyncing\ResourceSyncing` | `src/Models/Tenant/User.php:77`, `src/Models/Central/CentralUser.php:85` |
| `TenantPivot` | `Database\Models\TenantPivot` | `ResourceSyncing\TenantPivot` | `src/Models/Central/Membership.php:47` |
| `TenantWithDatabase` | `Contracts\TenantWithDatabase` | `Database\Contracts\TenantWithDatabase` | `src/Models/Central/Tenant.php:72` + 5 type-hints |
| `UpdateOrCreateSyncedResource` | `Listeners\UpdateSyncedResource` | `ResourceSyncing\Listeners\UpdateOrCreateSyncedResource` | `src/Listeners/Tenancy/UpdateSyncedResource.php:14` |
| `HasTenantOptions` (trait) | `Concerns\HasATenantsOption` | `Concerns\HasTenantOptions` | 3× `src/Console/Commands/*TenantModule.php` |

Two more are `::class` strings, not eager — they need the right name chosen,
not a shim: `Middleware\PreventAccessFromCentralDomains` →
`PreventAccessFromUnwantedDomains` (4 sites:
`src/NumerosisServiceProvider.php:384`, `src/Support/Numerosis.php:241`,
`src/Providers/TenancyServiceProvider.php:275`,
`src/Filament/NumerosisTenantPlugin.php:182`), and `UUIDGenerator` →
`UniqueIdentifierGenerators\UUIDGenerator` (`tests/TestCase.php:34`).
`tests/Feature/Support/NumerosisSeamTest.php` asserts on the middleware class
name and has to resolve it the same way rather than hardcoding either.

The two sync **events** are lazy too but need name resolution:
`Events\SyncedResourceSaved` → `ResourceSyncing\Events\SyncedResourceSaved`,
and `Events\SyncedResourceChangedInForeignDatabase` →
`ResourceSyncing\Events\SyncedResourceSavedInForeignDatabase` (renamed, not
just moved). Both appear in `TenancyServiceProvider`'s event map and in the
listener signatures.

Good news that shrinks this: `UpdateOrCreateSyncedResource` keeps
`handle(SyncedResourceSaved $event): void` and
`public static bool $shouldQueue`, so
`src/Listeners/Tenancy/UpdateSyncedResource` ports by swapping its parent —
its `$tries = 20` / `$backoff = 20` behaviour is untouched.

**1.2 — `Support\Tenancy\TenancyConfigKeys` for the 4 moved config keys.**
A moved config key is invisible to both autoloading and PHPStan; it surfaces
as a `null` far from the read. Route every `tenancy.*` **read and write**
through one class that answers with the right key for the installed version:

| v3 | dev-master | sites (re-counted 2026-08-29) |
|---|---|---|
| `tenancy.central_domains` | `tenancy.identification.central_domains` | 30 — 10 `src/`, 20 `tests/` |
| `tenancy.tenant_model` | `tenancy.models.tenant` | 19 — 5 `src/`, 14 `tests/` |
| `tenancy.domain_model` | `tenancy.models.domain` | 4 — 1 `src/`, 3 `tests/` |
| `tenancy.id_generator` | `tenancy.models.id_generator` | 1 |

**[was wrong] — the counts were low (25/17/4), and "route every read" is the
wrong framing.** Two things the original numbers hid:

1. **Two thirds of the sites are in `tests/`, not `src/`** (34 of 54 across
   the top three keys). A shim class that only `src/` consults leaves the
   suite pinned to v3 key names, so the dev-master CI leg of Phase 2 goes
   green while asserting against keys nothing reads.
2. **Six of them are writes, not reads** — `HostConfig.php:120`
   (`self::set('tenancy.central_domains', …)`), plus `Config::set(...)` /
   `config([...])` in test setup (`tests/Feature/HostConfigTest.php:67`,
   `tests/Feature/Filament/TenantAdmin/Pages/BillingPlanVisibilityTest.php:24`,
   and others). **A mis-targeted write is the silent half**: a read of a moved
   key at least yields `null` at some point, while a write to `tenancy.central_domains`
   on dev-master lands in a key nothing reads, and the test that set it up
   passes or fails for reasons unrelated to what it claims to assert.

**Consequence: 1.2 and 1.3 are one change, not two.** On dev-master the write
target is `tenancy.identification.central_domains` — three segments under
`tenancy.identification`, a sub-array stancl's own `mergeConfigFrom()` still
populates. That is exactly the `Arr::set()` auto-vivification hazard 1.3 is
about, so `TenancyConfigKeys` cannot expose a naive `Config::set($key, $value)`
helper; its write path has to be the read-modify-write below from day one.

`tenancy.central_user_model` / `tenancy.tenant_user_model` are **this
package's own** keys (`src/Support/HostConfig.php:88-89`), present in no
stancl stub — leave them alone.

**1.3 — Fix `HostConfig`'s multi-segment writes while you are in there.**
`.claude/rules/package-host-bootstrap.md` already documents that a dotted
`Config::set()` into a namespace another package's `mergeConfigFrom()` also
populates hits `Arr::set()`'s auto-vivification hazard and truncated
`tenancy.database` once. v4 moves these keys a segment deeper, so do that
rule's own suggested fix now: read the parent with `Config::array($parent, [])`,
merge, write once.

**Verification for Phase 1:** full suite still green on v3, PHPStan still 0.
This phase changes no behaviour — a behaviour change here is a bug.

**Checkpoint — stop and report before Phase 2.** If the shim count grew
beyond the nine above, something else moved; re-run the symbol diff in
`.claude/rules/stancl-tenancy-v4.md` and say so rather than shimming ad hoc.

---

## Phase 2 — widen the constraint, add the CI matrix ✅ DONE (2026-08-30)

**Both matrix legs are green. Measured on branch `package-scope-reduction`:**

| | v3.10.1 (stable) | dev-master |
|---|---|---|
| `php -d memory_limit=1G vendor/bin/pest --compact` | 1 failed (pre-existing, unrelated — see below), 7 skipped, 589 passed | 0 failed, 7 skipped, 590 passed |
| PHPStan (`tmpDir` invocation) | 0 errors outside baseline (218 → 221 entries) | not clean — see "PHPStan on dev-master" below, tracked as follow-up, not blocking |
| `vendor/bin/pint --dirty` | passes | — |

`composer.json` is back to the real target constraint,
`"stancl/tenancy": "^3.10 || dev-master"`, with no `minimum-stability`/
`stancl/jobpipeline` pinned at the root — those only apply inside the
dev-master CI leg (`.github/workflows/run-tests.yml`, done in 2.2) or a
host that opts in per the top-of-file dev-master block.

### 2.3/2.4 — what was actually wrong, beyond the prior session's list

The prior session (handoff below, kept for the mechanism) had already found
and fixed 8 real dev-master incompatibilities and landed at 24 failures
seen once, un-reproduced. Resuming from a fresh measurement found **21**
failures on that same uncommitted tree, all newly diagnosed this session:

1. **`InitializeTenancyByDomainOrSubdomain`'s own constructor never called
   `parent::__construct()`.** On dev-master this leaves `$tenancy`/
   `$resolver` — typed properties promoted by the real parent
   (`InitializeTenancyByDomain`) — uninitialized, throwing the moment
   `parent::handle()` touches them. But v3's version of the same class has
   **no constructor at all** (a standalone dispatcher, not a subclass), so
   calling `parent::__construct()` unconditionally is *itself* fatal on
   v3 ("Cannot call constructor") — this is a real per-version shape
   difference, not the same bug on both legs. Fixed by gating the
   `parent::__construct()` call on `TenancyVersion::isDevMaster()`.
2. **`HostConfig` never added this package's identification middleware
   subclass to dev-master's own `tenancy.identification.middleware` /
   `.domain_identification_middleware` config.** dev-master derives a
   route's tenant/central/universal mode from an exact-string
   `in_array()` check against those two arrays
   (`Concerns\DealsWithRouteContexts::routeHasMiddleware()`); this
   package's middleware is a subclass, not the literal class stancl's
   stub lists, so every tenant-panel route silently fell through to
   `RouteMode::CENTRAL` and 404'd as "central route from a tenant
   domain". New `HostConfig::tenancyIdentificationMiddleware()`, no-op on
   v3 (neither key exists there).
3. **dev-master's `CacheTenancyBootstrapper` hard-throws on any
   `array`-driver store named in `tenancy.cache.stores`** (defaults to
   `[env('CACHE_STORE')]`), which `FreshHostTest` hits for real — v3's
   bootstrapper has no such check. New `HostConfig::cacheTenancyStores()`
   filters `array`-driver entries out; no-op on v3 and a no-op whenever
   the store isn't actually `array`.
4. **`Tenant::unsetEventDispatcher()` (used by several tests to skip
   provisioning overhead) now silently produces a tenant with no physical
   database on dev-master**, because dev-master's
   `DatabaseTenancyBootstrapper` eagerly checks `databaseExists()` before
   every `tenancy()->initialize()` — v3 doesn't. `EnsureTenantSubscriptionActiveTest`
   (calls `$tenant->run()` directly) and
   `WebhookControllerLifecycleTest::tenantWithStripeCustomer()` (reached
   indirectly through `ReconcileModuleSubscriptionItems`) both stopped
   disabling events, so `CreateDatabase`/`CloneTenantSchema` actually run.
5. That fix's own side effect: with events no longer suppressed, the real
   `TenantSaved` → `SyncTenantToStripeOnSave` → `SyncTenantToStripe` chain
   fired for any tenant with a `stripe_id`, hitting the real Stripe API
   with a dummy test key. `Bus::fake([SyncTenantToStripe::class])`
   **does not catch this** — laravel-actions dispatches a `JobDecorator`
   wrapper, not the action class itself, so a class-keyed queue fake never
   matches. Fixed with the package's own fake,
   `SyncTenantToStripe::mock()->shouldReceive('handle', 'configureJob')->andReturnNull()`
   (both methods need stubbing — `JobDecorator` calls `configureJob()`
   unconditionally on every dispatch).
6. **`tests/Feature/Listeners/Tenancy/LogSyncedResourceChangedInForeignDatabaseTest.php`**
   hardcoded the v3-only event class directly (`use Stancl\Tenancy\Events\SyncedResourceChangedInForeignDatabase`)
   instead of resolving through `TenancyVersion::syncedResourceChangedInForeignDatabaseEventClass()`
   — the same class of bug `.claude/rules/tenant-registration-wizard.md`
   already documents for hardcoded values in test setup.
7. **`Artisan::call('tenants:migrate', ['--tenants' => $tenant->id])`**
   (two sites in `FinalizeTenantProvisioningTest`) passed a bare string for
   an option Symfony declares `InputOption::VALUE_IS_ARRAY`. Under
   dev-master's `HasTenantOptions::getTenants()` this reaches
   `$query->whereIn($key, $this->option('tenants'))` with a string, not an
   array, throwing `count(): Argument #1 must be of type Countable|array,
   string given`. Fixed by passing `[$tenant->id]`.

None of these seven were in the prior session's list — all found via
`Bus::fake`/`TenancyVersion`-style tracing rather than assumed. Also fixed,
found by PHPStan rather than the test suite:

8. **`src/Livewire/Tenant/Registration/Registration.php`** referenced bare
   `Plan::class` after a concurrent Phase 4 edit (same working tree, not
   this session's own work) removed the `use ...Steps\Plan;` import —
   resolved to the wrong FQCN silently (no fatal, since `::class` on an
   unqualified name never triggers autoload), so `stateToPersist()`'s
   Stripe-secret-stripping never ran. Real bug, unrelated to tenancy
   version; fixed by restoring the import.

### PHPStan on dev-master ✅ CLOSED (2026-08-30)

**Both legs are now clean and both run in CI.** Full mechanism in
`.claude/rules/static-analysis.md`; the short version, because the first
diagnosis was wrong in an instructive way:

The dev-master run reported 49 "real" errors, and **most of them were the
harness, not the code**. A `scanFiles` stub *shadows* the real class rather
than merging with it, so analysing with dev-master installed while still
loading `.phpstan/stancl-tenancy-dev-master.stub.php` replaced the real
`Stancl\Tenancy\Database\DatabaseConfig` with the stub's trimmed copy —
every `->database()->getName()`/`->manager()` in the package then read as
`method.notFound`. Fixed structurally rather than by baselining: the config
is split into `phpstan-common.neon` + one leaf per leg
(`phpstan.neon.dist`, `phpstan-dev-master.neon.dist`), each loading the stub
for the version that is **not** installed, and a new
`.phpstan/stancl-tenancy-v3.stub.php` mirrors the existing dev-master one.
The dev-master leg deliberately does not include the stable baseline.

Two genuine findings came out of it, neither reachable by the test suite:

1. **`PreservingPathTenantResolver` would have fatalled on dev-master.** It
   read `PathTenantResolver::$tenantParameterName`, a v3 static *property*
   that dev-master replaced with a static *method* — `Error: Access to
   undeclared static property`, not a type complaint. Only a real path-mode
   HTTP request reaches it, which Phase 5 already documented as untestable
   from console. Now `TenancyVersion::pathTenantParameterName()`, and the
   dev-master branch delegates to `parent::resolveWithoutCache()` instead of
   reimplementing it (dev-master only forgets the parameter in `resolved()`,
   which this class already overrides — so delegating also keeps the
   binding-field and `allowedExtraModelColumns()` handling the old body
   silently dropped). `tests/Feature/Resolvers/PreservingPathTenantResolverTest`
   drives the resolver directly and was verified to fail against the
   pre-fix file.
2. **`global_cache()` is `: mixed` on dev-master.** Ten call sites became
   `Cannot call method remember() on mixed`. New
   `Support\Cache\GlobalCache::store()` narrows once; no behaviour change
   (stancl binds `globalCache` to a `CacheManager`, whose `__call` already
   forwarded to `->store()`).

Measured after: stable leg 0 outside a 221-entry baseline, dev-master leg 0
outside a 228-entry one. CI gained a `Static analysis` step that picks the
config matching its `stancl` axis value.

### Three new deliberate baseline entries (stable leg)

Same shape as `static-analysis.md`'s existing two: code that's correct on
dev-master and unreachable on v3, checked by PHPStan against v3's real,
differently-shaped classes because that's what's actually installed when
the baseline was generated. All three sit inside a
`TenancyVersion::isDevMaster()` branch:

- `InitializeTenancyByDomainOrSubdomain::__construct()`'s
  `parent::__construct()` call (v3's real parent has none)
- `TenancyServiceProvider`'s dev-master branch of
  `registerCachedDomainResolver()` (checked against v3's real
  `DomainTenantResolver` constructor, which takes `Cache\Factory` not
  `Application`)
- `TenancyVersion::resolverShouldCache()`'s `$resolverClass::shouldCache()`
  call (v3's real class has no such static method)

A fourth dev-master-only symbol, `Stancl\Tenancy\ResourceSyncing\PivotWithCentralResource`,
could not go in the baseline at all — PHPStan reports `interface.notFound`
as **non-ignorable**. Added to `.phpstan/stancl-tenancy-dev-master.stub.php`
instead (safe: v3 has no `ResourceSyncing` namespace at all, so there's no
real class to conflict with).

### Two new host-config rows

`docs/host-requirements.md` §2 gained rows for
`tenancy.identification.middleware` / `.domain_identification_middleware`
and `tenancy.cache.stores`, both dev-master-only — `tests/Feature/Docs/HostRequirementsTest.php`
enforces every `HostConfig::applied()` key has a row, and both new
`HostConfig` methods above trip it.

**Checkpoint result: both matrix legs pass their test suites. Proceed to
Phase 3/4 (already done, see below) or Phase 5.**

---

## Phase 2 (prior-session handoff, kept for the mechanism — historical)

> `composer.json` sat on `dev-master` forced (not the final
> `"^3.10 || dev-master"`) mid-investigation; that has since been restored.
>
> 2.2 (CI matrix in `.github/workflows/run-tests.yml`, two-leg `stancl`
> axis) was done and committed-worthy in that session — still true.
>
> 2.3 (make the dev-master leg green) was **in progress, not done**. Real
> bugs found and fixed in that session, all now confirmed still correct
> and still in the working tree:
> - `src/Concerns/HasGlobalIdentity.php` — dev-master's `ResourceSyncing`
>   trait now declares `getGlobalIdentifierKeyName()`/`getGlobalIdentifierKey()`
>   itself (v3's didn't), fatal trait-collision with this trait in
>   `CentralUser`/`Tenant\User`. Made version-conditional (empty on
>   dev-master).
> - `src/Providers/TenancyServiceProvider.php` — `CachedTenantResolver::
>   __construct()` signature changed (`Cache\Factory $cache` on v3 vs
>   `Application $app` on dev-master, which resolves `globalCache`
>   internally). Added `TenancyVersion::isDevMaster()` branch in
>   `registerCachedDomainResolver()`.
> - `src/Support/Tenancy/TenancyVersion.php` — added
>   `setResolverShouldCache()`/`resolverShouldCache()`, since
>   `DomainTenantResolver::$shouldCache` (v3 public static property) became
>   `shouldCache(): bool` reading `tenancy.identification.resolvers.<class>.cache`
>   on dev-master. Updated the two call sites
>   (`TenancyServiceProvider.php`, `InstallNumerosisCommand.php`) and the
>   two `InstallNumerosisCommandTest.php` tests that poked the property
>   directly.
> - `tests/Support/CloneTenantSchema.php` — was importing raw
>   `Stancl\Tenancy\Contracts\TenantWithDatabase` (v3-only) instead of the
>   `Support\Compat\Tenancy\TenantWithDatabase` shim — the 6th
>   `TenantWithDatabase` site Phase 1.1's table missed (only found 5 +
>   the declaration site).
> - `src/Support/Compat/Tenancy/PivotWithCentralResource.php` (new file) +
>   `src/Models/Central/Membership.php` — dev-master's `TenantPivot` now
>   throws `CentralResourceNotAvailableInPivotException` when attached from
>   the tenant side (`$tenant->users()->attach($user)`, used by ~35 test
>   call sites) rather than the central side. `Membership` now implements
>   the (shimmed) `PivotWithCentralResource` interface with
>   `getCentralResourceClass(): string` returning `CentralUser`, which
>   makes both directions work rather than rewriting every test call site.
> - `tests/TestCase.php` — dev-master's `CacheTenancyBootstrapper` throws
>   on an `array`-driver cache store (v3's didn't check). Test harness
>   pins `cache.default`/`session.driver` to `array`, so every tenant-context
>   test hit this. Added `tenancy.cache.scope_sessions = false` (no-op key
>   on v3).
> - `tests/Feature/FreshHostTest.php` — its `putenv()` calls (setting
>   `CACHE_STORE=array` etc for its own scenario) were never actually
>   reversed in `tearDown()` (only Laravel's *cached* view of env was
>   reset via `Env::enablePutenv()`, not the real process env) — so once
>   this test ran once in a process, `CACHE_STORE=array` silently became
>   real/global for every later test, which is what surfaced the
>   `CacheTenancyBootstrapper` bug above process-wide rather than just in
>   this one test. Added `$envKeysSet` tracking + real `putenv($key)`
>   (unset form) + `unset($_ENV[$key], $_SERVER[$key])` in `tearDown()`.
> - `src/Models/Tenant/User.php` + `src/Models/Central/CentralUser.php` —
>   added `getCreationAttributes()` overrides (`[...getSyncedAttributeNames(), getGlobalIdentifierKeyName()]`).
>   Root cause: dev-master's `UpdateOrCreateSyncedResource` listener
>   creates the counterpart record via `$model::withoutEvents(fn () =>
>   $model::create($this->parseCreationAttributes($event->model)))` —
>   `withoutEvents` suppresses the `creating` hook that would otherwise
>   auto-generate `global_id`, and the default `getCreationAttributes()`
>   (= `getSyncedAttributeNames()`) never included `global_id` in the
>   first place — so a central/tenant record created *by the sync path*
>   (not directly) got `global_id = null`, and this package's own
>   `LogSyncedResourceChangedInForeignDatabase` listener threw a
>   `TypeError` reading it straight back off the model. This is the
>   "is_bot-column crash… re-test against `UpdateOrCreateSyncedResource` +
>   `ParsesCreationAttributes`" item 2.4 called for — turned out to be a
>   *different* symptom (`global_id` null) than the original bug, not the
>   original bug recurring.
> - `tests/Feature/ProfileSyncTest.php` — two `'id' => 'test'.uniqid('',
>   true)` sites produced dots in the tenant id (`uniqid` with
>   `more_entropy=true`), which dev-master's new
>   `ValidatesDatabaseParameters` rejects (`Forbidden character '.' in
>   parameter`) — v3 had no such validation. Fixed by stripping the dot.
>
> **Confirmed NOT dev-master-related, left alone:**
> `tests/Feature/Filament/Admin/RegisterTenantTest.php`'s
> `assertSee('livewire.js', ...)` fails identically under a clean v3
> install too (verified by temporarily swapping `composer.json` back and
> re-running just that filter) — Livewire's asset filename is
> `livewire.min.js`/hashed now, unrelated to tenancy version. Pre-existing
> breakage, not in scope here, don't waste time on it again.
>
> **Last full run before the interrupt**: 24 failed / 7 skipped / 566
> passed, trending down each fix (was 90+ failed at the start of hunting).
> The `testing` MySQL database needed `DROP DATABASE; CREATE DATABASE …`
> between several of these runs — it kept ending up half-migrated
> (0 or ~8 stock tables only) after fatal-error runs; see
> `.claude/rules/testing.md`'s recovery recipe if this recurs. **Next
> step: rerun the full suite fresh (`docker compose exec -T mysql mysql
> -uroot -proot -e "DROP DATABASE IF EXISTS testing; CREATE DATABASE
> testing CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"` then `php -d
> memory_limit=1G vendor/bin/pest --compact`) against the *current*
> dev-master install to see what's left of the 24, then fix or triage each
> before declaring 2.3 done.** After that: 2.4 (re-verify `JobPipeline`
> vs `2.0.0-rc7` calling convention — not yet checked this session, only
> the sync-listener half of 2.4 was); then restore the real `^3.10 ||
> dev-master` constraint, re-verify the **stable** leg is still green
> (should be — all fixes above are additive/version-branched, but hasn't
> been re-run since `HasGlobalIdentity`/`PivotWithCentralResource`
> landed), run PHPStan on both, then commit.
>
> Nothing here was committed. `git status`/`git diff` shows every change
> above, uncommitted, in the working tree — check it's all still there
> before resuming, and check for the other agents' Phase 3/4 work
> (`src/Support/Numerosis.php`, `src/Support/Features.php`,
> `tests/Feature/Support/PackageContributionSeamsTest.php`, etc — also
> uncommitted) that shares this same working tree; don't clobber it.

**2.1** — `composer.json`: `"stancl/tenancy": "^3.10 || dev-master"`.

**[was wrong] — the current constraint is `"^v3.9"`, not `^3.10`.** The lock
does resolve `v3.10.1`, so everything Phase 1 is verified against is accurate;
but the Phase 2.1 edit is a floor *raise* as well as a widening, and the
compat layers of Phase 1 are only proven against 3.10.1 — nothing in this repo
has ever been run against a real 3.9.x. Either state the raise deliberately in
`CHANGELOG.md`/`UPGRADING.md` (a host pinned below 3.10 stops resolving), or
keep `^3.9` and accept that the v3 leg of the matrix covers a range you have
not tested the low end of. Do not leave it implicit.

**2.2** — `.github/workflows/run-tests.yml` gains a `stancl` axis
(`^3.10` and `dev-master`). The dev-master leg needs `--with` overrides or a
scratch `composer.json` carrying `minimum-stability: dev` plus
`stancl/jobpipeline: 2.0.0-rc7`.

**Dual support that is not in CI is not dual support.** A v4 code path nobody
runs rots exactly like the vacuous `assertDontSee` in
`.claude/rules/testing.md` — green, and measuring nothing.

**2.3** — Make the dev-master leg green. Expect real work in:
- `src/Providers/TenancyServiceProvider.php` (~15 stancl imports, the event
  map, `makeTenancyMiddlewareHighestPriority()`)
- `src/Support/HostConfig.php` (every normalized key)
- `tests/Feature/Support/NumerosisSeamTest.php`, `tests/TestCase.php`
- `src/Jobs/SeedTenantDatabase.php` — dev-master **fixes**
  `Stancl\Tenancy\Commands\Seed`, but the container-resolve workaround must
  stay for the v3 leg. Do not delete it.

**2.4** — Re-verify, with tests rather than assumption, two rules the sync
rework may have changed:
- the `is_bot`-column crash in `.claude/rules/tenant-provisioning.md`, against
  `UpdateOrCreateSyncedResource` + `ParsesCreationAttributes`
- the `JobPipeline` calling convention in the same file, against
  `stancl/jobpipeline: 2.0.0-rc7` (dev-master's stub still uses `JobPipeline`,
  so the rule is probably intact — probably is not verified)

**[was wrong]** The original plan expected a `CachePrefixingBootstrapper` to
replace tag-based cache isolation, and a new `database(): Connection` on the
`TenantDatabaseManager` contract. Neither exists. `.claude/rules/tenant-caching.md`
does **not** need the rewrite the old plan scheduled — check
`/tmp/v4/src/Bootstrappers/` and `/tmp/v4/assets/config.php` before touching it.

**Verification:** both matrix legs green; both PHPStan runs clean.

---

## Phase 3 — contribution seams ✅ DONE (2026-08-29)

**All three seams landed. New test file
`tests/Feature/Support/PackageContributionSeamsTest.php` (6 tests) proves
each is actually reached; `--filter=PackageContributionSeamsTest` and
`--filter=HostConfigTest` (38 tests) both green, `ArchTest` green, PHPStan 0
new errors in any touched/new file (verified with the `tmpDir` invocation).
Full-suite run was not usable to re-verify globally at landing time — a
concurrent Phase 2 edit to `TenancyServiceProvider.php`
(`CachedTenantResolver`'s dev-master branch) was mid-flight in the same
working tree and threw an unrelated `TypeError` across ~130 unrelated tests;
confirmed unrelated by filtering to the specific suites this phase touches.**

- **3.1 Routes.** `Numerosis::addCentralRoutes(Closure)` /
  `addTenantRoutes(Closure)` (`src/Support/Numerosis.php`). Each callback
  runs inside `routes()`'s existing groups — central ones once per
  configured central domain, under that domain's own
  `Route::middleware('web')->domain($domain)` group, tenant ones under the
  single `Route::middleware('tenant')` group — by switching those groups
  from `->group($file)` to `->group(function () { require $file; foreach
  ($callbacks as $cb) { $cb(); } })`. `registerRoutesUsing()` still replaces
  `routes()` wholesale and bypasses this entirely.
  `resetRouteContributionsForTesting()` clears both lists for tests; a real
  host registers once and it lives for the app's lifetime, same as
  `$registerRoutesCallback`.
- **3.2 Features.** `Features::register(class-string<Feature>)` appends to
  a package-contributed list, separate from `config('numerosis.features')`;
  `Features::all()` returns the deduplicated union. Duplicate registration
  is a no-op. `NumerosisServiceProvider::packageBooted()`'s feature-boot
  loop now does `class_exists($feature)` before `$this->app->make($feature)`
  and logs a warning + `continue`s on a missing class, rather than a hard
  boot failure — `Log::warning(...)`, import added.
  `Features::resetRegisteredForTesting()` for tests.
- **3.3 Migration paths + seed data.** `Numerosis::addTenantMigrationPath(string)`
  appends to a list `tenantMigrationPaths()` returns alongside the
  package's own `tenantMigrationPath()`; `HostConfig::tenantMigrationParameters()`
  now loops that list instead of appending the single vendor path. Same
  shape for seed data: `Numerosis::addTenantSeeder(class-string<Seeder>)` /
  `tenantSeeders()`, and `TenantDatabaseSeeder::run()` calls
  `$this->call(Numerosis::tenantSeeders())` after its own two seeders — a
  satellite package's tenant tables get seeded without publishing/editing
  that file. `resetMigrationAndSeederContributionsForTesting()` for tests.

Original Phase 3 brief, kept for reference:

**3.1 — Routes.** `Numerosis::routes()`'s own docblock says "there is no hook
to append to the defaults", and the central group is not reproducible from
outside — it loops `tenancy.central_domains` and wraps `routes/web.php` in
`Route::middleware('web')->domain($domain)` once per domain. Add
`Numerosis::addCentralRoutes(callable)` / `addTenantRoutes(callable)`,
invoked inside those groups. `registerRoutesUsing()` keeps its
replace-everything semantics.

**3.2 — Features.** `Support\Features::all()` reads a plain config array and
`NumerosisServiceProvider` calls `$this->app->make()` on each entry, so a
listed-but-missing feature class is a hard boot failure. Add
`Features::register(class-string<Feature>)` so a second package can contribute
without the host editing config, and make the boot loop skip (with a reported
warning) a configured class that does not exist.

**3.3 — Migration paths.** `HostConfig` already treats
`tenancy.migration_parameters['--path']` as an appendable array
(`src/Support/HostConfig.php:154-177`), but `Numerosis::tenantMigrationPath()`
is singular. Add `Numerosis::addTenantMigrationPath(string)`. Same shape for
tenant seed data, which currently funnels through the single
`TenantDatabaseSeeder`.

**Verification:** a test per seam proving a second registrant's route /
feature / migration path is actually reached, plus the existing suite green.

---

## Phase 4 — config-driven wizard steps ✅ DONE (2026-08-29)

Independent of Phases 1–3; can run in parallel with them.

**Status: 4.1/4.2/4.3 all landed.** `php -l` clean on every changed file,
`pint` passes. **Full suite not re-verified after landing** — at the time
this phase finished, Phase 2/3 work running concurrently in the same working
tree had `CentralUser` in a broken state (`Trait method
Nvade\Numerosis\Support\Compat\Tenancy\ResourceSyncing::getGlobalIdentifierKeyName
has not been applied ... because of collision with
Nvade\Numerosis\Concerns\HasGlobalIdentity::getGlobalIdentifierKeyName`),
unrelated to any Phase 4 file. **Before trusting Phase 4 green, run:**

```bash
vendor/bin/pest --compact --filter="Registration|Wizard|CompanyInfo|TechnicalSetup"
php -d memory_limit=1G vendor/bin/pest --compact   # full suite, once Phase 2/3 lands
```

What landed:

- **4.1** — `Registration::steps()` (`src/Livewire/Tenant/Registration/Registration.php`)
  reads `Config::array('numerosis.tenancy.registration.steps')` instead of a
  hardcoded array. New key added under `tenancy` in `config/numerosis.php`,
  nested to mirror `tenancy.provisioning.steps` (not top-level
  `numerosis.registration.steps`, which the original plan text said and this
  file already flagged as wrong). Default unchanged:
  `[CompanyInfo, TechnicalSetup, Plan, Payment]`.
- **4.2** — `RegistrationWizardFeature::bootstrap()` (`src/Features/Tenancy/RegistrationWizardFeature.php`)
  now loops the configured step list against a `SHIPPED_STEP_ALIASES` map
  (`company-info`, `technical-setup`, `plan`) instead of four hardcoded
  `Livewire::addComponent()` calls. `Payment` is deliberately **not** in the
  map — it keeps resolving by its full FQCN, avoiding the `payment` alias
  collision with Cashier's own published `payment.blade.php`
  (`.claude/rules/billing-checkout.md`) that a naive
  `Str::kebab(class_basename($step))` loop would have reintroduced. A
  host-supplied step present in config but absent from the map is skipped —
  the package only auto-registers steps it ships a view for.
  `getCurrentStepState()`'s `livewire.finder`-based alias resolution
  (already fixed pre-Phase-4, see `.claude/rules/tenant-registration-wizard.md`)
  is untouched and still correct regardless of alias source.
- **4.3** — New `Nvade\Numerosis\Contracts\Tenancy\ProvidesTenantIdentity`
  (`tenantIdentityStateKeys(): array` — wizard-state field name(s) a step
  contributes). `CompanyInfo` implements it returning `['company_name']`,
  `TechnicalSetup` returning `['domain']`. `RegistrationWizardFeature::bootstrap()`
  asserts at least one configured step implements it and throws
  `LogicException` at boot if not — before this, a misconfigured step list
  with no identity source would only surface as a missing tenant
  name/domain deep inside the queued `ProvisionTenant` chain. The contract
  is declarative only in this pass — nothing yet rewires
  `TechnicalSetup::continue()`/`Plan::continue()`'s hand-written
  `$this->state()->get('company_name')` / `get('domain')` lookups to read
  through it; that's a follow-up, not required by this phase's plan text.

**4.1** — `Registration::steps()` (`src/Livewire/Tenant/Registration/Registration.php:80`)
reads `config('numerosis.tenancy.registration.steps')` instead of its
hardcoded array. **Note the nesting**: under `numerosis.tenancy.*`, beside the
existing `numerosis.tenancy.provisioning.steps` this deliberately mirrors —
the original plan said `numerosis.registration.steps`, which mirrors nothing.
Default stays `[CompanyInfo, TechnicalSetup, Plan, Payment]`.

**4.2 — `RegistrationWizardFeature::bootstrap()` must loop, and the naive loop
is a known bug.** It currently hardcodes an alias *and* a `viewPath` per step
across four `Livewire::addComponent()` calls, and deliberately omits `Payment`.
Deriving the alias as `Str::kebab(class_basename($step))` would register
`payment` — which collides with Cashier's published
`resources/views/vendor/cashier/payment.blade.php`, the exact collision
`.claude/rules/billing-checkout.md` documents. Whatever you build must:
- keep `Payment`'s existing full-FQCN alias
- resolve aliases through `app('livewire.finder')->normalizeName()`, never a
  hardcoded string (`.claude/rules/tenant-registration-wizard.md` records that
  a hardcoded `wizardClassName` silently broke every wizard transition, and
  that four test files reproduced the bug as if it were correct input)
- decide a convention for a host-supplied step whose view is not under
  `config('numerosis.views.path')` — simplest is: register your own component,
  the package only auto-registers steps it ships
- keep `Registration::getFormalStepNameFor()` (`:180`-ish) agreeing with
  whatever alias mechanism you pick; it independently derives display names
  via `Str::kebab(class_basename())` today

**4.3** — `Contracts\Tenancy\ProvidesTenantIdentity`: a step contributes an
identifier + display name into wizard state. `CompanyInfo` and
`TechnicalSetup` implement it. `Registration` (or the Feature's `bootstrap()`)
asserts at least one configured step satisfies it and fails loudly at boot —
not deep inside a queued `ProvisionTenant` chain.

**Verification:** `--filter=Registration`, `--filter=TechnicalSetup`,
`--filter=Provision`, plus a new test that a **reordered / substituted** step
list still completes provisioning end to end.

---

## Phase 5 — identification modes ✅ DONE (2026-08-30)

**All three modes implemented. Measured on branch `package-scope-reduction`
against `v3.10.1`:**

| | before | after |
|---|---|---|
| `php -d memory_limit=1G vendor/bin/pest --compact` | 1 failed, 7 skipped, 589 passed | **1 failed, 7 skipped, 603 passed** (589 + 14 new) |
| PHPStan (`tmpDir` invocation) | 0 outside baseline | **0 outside baseline** (one `count:` bump, 22 → 23, on the pre-existing `config/numerosis.php` env-call entry — not a new suppression) |
| `vendor/bin/pint --dirty` | — | passes |

The single failure is `RegisterTenantTest`'s `assertSee('livewire.js')`,
which this file's own Phase 2 handoff already records as pre-existing and
unrelated (Livewire's asset filename is hashed now) — reproduced identically
with the whole branch stashed.

**Full mechanism, and the four things that are non-obvious enough to
rediscover the hard way, are in `.claude/rules/identification-modes.md`.**
Summary of what landed:

- **`Enums\Tenancy\IdentificationMode`** (`Subdomain`/`CustomDomain`/`Path`),
  read via `::current()` from `numerosis.tenancy.identification.mode`,
  default `subdomain` — behaviour unchanged for every existing host.
  **`Path` is not v4-only after all**, contrary to this plan's original text:
  `InitializeTenancyByPath` and `PathTenantResolver` both exist in v3.10.1
  (verified in `vendor/`); it is `RouteMode` that is dev-master-only, and
  nothing in path mode needs it. No v3 fail-loudly branch was written,
  because there is nothing to fail on.
- **`TENANCY_IDENTIFICATION` const → `TenancyServiceProvider::identificationMiddleware()`**,
  plus a new `::tenancyRouteMiddleware()` (returns `Http\Middleware\NullMiddleware`
  under path mode, where tenant routes deliberately live on the central
  domain and the usual `PreventAccessFromCentralDomains` would 404 all of
  them). All 6 const sites converted, plus the 2 `NumerosisSeamTest`
  assertions.
- **All 14 subdomain-assumption sites** from the list below, plus **two the
  list missed**: `NumerosisTenantPlugin::shouldRegisterPanel()` (its
  central-domain skip makes the panel unreachable under path mode) and
  `HostConfig::tenancyIdentificationMiddleware()` (registered only the
  subdomain class, so dev-master's route-mode `in_array()` would 404 the
  other two modes).
- **`Nvade\Numerosis\Resolvers\PreservingPathTenantResolver`** — stancl's
  `PathTenantResolver` calls `$route->forgetParameter('tenant')`, which runs
  before Filament's `IdentifyTenant` reads that same parameter, so
  `Filament::setTenant()` was silently never called. Bound over the parent
  in `register()`.
- **Identifier and domain kept separate**: `TenantRegistrationData::$custom_domain`,
  a new `pending_tenant_provisions.custom_domain` column
  (`2026_08_30_000000_*`), `CreateTenantDomain::run($tenant, $slug, $customDomain)`
  returning `?Domain` (null under path mode), and a second
  `TenantDomainPolicy::assertCustomDomainAvailable()` contract method with
  its own rule (`Rules\CustomDomainIsAvailable`). The slug can never *be*
  the domain — it is also the physical database name.
- **`tests/Feature/Providers/IdentificationModeTest`** (15 tests). Note the
  load-bearing one is the *negative* case: a policy that checked
  `tenants.id` in every mode passes "rejects a taken subdomain" perfectly
  and only fails
  `test_default_policy_ignores_a_tenant_id_collision_under_subdomain_mode`.

**Not covered, deliberately and documented:** path mode's full HTTP round
trip. `.claude/rules/filament-tenancy.md`'s `shouldRegisterPanel()` console
exemption means a Pest-dispatched request always sees the tenant panel
registered, so route-match outcome is unassertable from console — it needs a
browser test, exactly as this plan's own Verification note anticipated.

### Original brief, kept for reference

Depends on Phase 2 (v4's `identification.*` and `RouteMode` are the real
mechanism) and Phase 1.2 (`TenancyConfigKeys`).

`Enums\Tenancy\IdentificationMode { Subdomain; CustomDomain; Path; }`,
selected by `numerosis.tenancy.identification.mode`, default `subdomain`
(today's behaviour, unchanged). **`Path` is v4-only** — `RouteMode` does not
exist on v3; say so in the config comment and fail loudly if selected on v3.

**[was wrong] — the subdomain assumption is in 14 places, not 4** (13 as of
the first revision; a 14th turned up on re-audit, marked below). The original
plan listed four. Full list:

- `src/Actions/Tenancy/CreateTenantDomain.php:20` — `$subdomain.'.'.apex`,
  **and** `firstOrCreate(['id' => $subdomain], …)`. It sets the `domains.id`,
  not only the domain string, so a `BuildsTenantDomainString` contract alone
  does not cover it — decide what `domains.id` is per mode.
- `src/Models/Central/Domain.php:60` — `getHost()`, same concatenation
- `src/Services/Tenancy/DefaultTenantDomainPolicy.php:31,45` — no-dots regex,
  and a uniqueness check that appends the apex itself
- `src/Filament/Admin/Resources/Tenants/TenantResource.php:75` and
  `.../RelationManagers/DomainsRelationManager.php:33` — `->suffix('.'.apex)`
- `src/Commands/InstallNumerosisCommand.php:427-430` — **fails verification**
  if `tenant_pattern` lacks a literal `{tenant}`, which custom-domain and path
  modes will
- `src/Commands/InstallNumerosisCommand.php:778` — **the 14th site, missed on
  the first pass.** The post-install instructions print
  `'  1. Wildcard DNS: point *.'.Config::string('numerosis.domains.tenant_pattern', …)`.
  Wrong-but-plausible operator instructions are worse than a crash: custom-domain
  mode needs per-tenant DNS, path mode needs none at all, and this line would
  confidently tell the host to set up a wildcard either way.
- `resources/views/livewire/tenant/registration/wizard/steps/technical-setup.blade.php:25,53`
  and `resources/views/components/billing/order-summary.blade.php:36`

**[was wrong] — `NumerosisTenantPlugin.php:106` is already config-driven.**
It reads `Config::string('numerosis.domains.tenant_pattern')`; it does not
hardcode `{tenant}.<apex>`. The real work at that seam is
`Tenant::resolveRouteBinding()` — custom-domain mode has to resolve a captured
host against `domains.domain` rather than by `id`, and path mode likely drops
`->tenantDomain()` for Filament's path-prefixed routing. Treat each of the
three bindings as its own spike with its own checkpoint; do not assume one
pattern with a variable.

`TENANCY_IDENTIFICATION` becoming a method is a 5-site change, not a
one-liner: `src/Providers/TenancyServiceProvider.php:72,255`,
`src/Support/Numerosis.php:240`, `src/NumerosisServiceProvider.php:383`,
`src/Filament/NumerosisTenantPlugin.php:177`, plus 2 assertions in
`NumerosisSeamTest`. Line 383 is `Route::aliasMiddleware()` at boot, so the
mode must resolve that early.

**Verification:** `tests/Feature/Providers/IdentificationModeTest` — all
available modes produce a working tenant and a Filament panel that resolves
it, driven through `actingAsTenantPanelUser()` rather than raw route dispatch
(`.claude/rules/filament-tenancy.md` explains why a console-dispatched HTTP
request always sees the tenant wildcard win).

---

## Phase 6 — package map ✅ AGREED (2026-08-30)

**Six packages, decided with the maintainer 2026-08-30.** The four decisions
that were open are recorded in "Decisions taken" below, with the evidence each
was made against. Phase 7 may start from this table.

| Package | Depends on | Owns |
|---|---|---|
| **numerosis-ui** | — (leaf) | `resources/views/{components/ui,components/icons,layouts,partials,flux}`, the `livewire/flux` require. No tenancy, billing or Filament references at all |
| **numerosis** (this repo) | numerosis-ui | Tenancy engine (bootstrappers, guards, identification modes, `HostConfig`, the Phase-1 compat layers), billing/subscription engine, provisioning pipeline, auth contracts + guard mechanics, central Eloquent models, the Feature mechanism and the Phase-3 seams, the `panels.*` config section incl. the two new seam keys, `activity_log` migrations + `LogsActivity` shim, `database/seeders/*`, `stubs/`, `components/billing` + marketing views |
| **numerosis-filament** | numerosis, numerosis-ui | Both panels in full — `Filament/{Admin,TenantAdmin,App,Concerns}`, `NumerosisAdminPlugin`/`NumerosisTenantPlugin`, `Providers/Filament/*`, theme/asset registration, `Testing/InteractsWithTenantPanel`, the `Support/Compat/Filament*` shims, `ActivityResource` + `ActivityLogFeature`, `resources/views/filament/*` |
| **numerosis-onboarding** | numerosis, numerosis-ui | `Livewire/Tenant/Registration/*`, `RegistrationWizardFeature`, `Support/State/RegistrationState`, the Phase-4 step config and identity contract, `resources/views/livewire/tenant` + `components/registration` |
| **numerosis-auth-ui** | numerosis, numerosis-ui | `Livewire/Auth/*`, `routes/auth.php`, `SocialLoginFeature`, `TurnstileFeature`, `Http/Controllers/Socialite/*`, the `one_time_passwords` migrations, `resources/views/livewire/auth` + `components/auth` + `layouts/auth` |
| **numerosis-modules** | numerosis, numerosis-filament | `ModuleSystemFeature`, `ModuleRegistry` + `EloquentModuleRegistry`, `Actions/Modules/*`, `Console/Commands/*TenantModule`, `Concerns/Modules/PurchasesModules`, `Contracts/Tenancy/ModulePlugin`, all module Filament UI (both `Filament/Admin/Resources/Central/Modules/` and `Filament/TenantAdmin/{Resources,Pages}/Modules`), the 4 module migrations |

Star, not a graph: **only numerosis-modules depends on another satellite**, and
it must (its own UI is Filament resources, and `ModulePlugin extends
Filament\Contracts\Plugin`). Every other satellite reaches core and ui only.

### Decisions taken (2026-08-30)

**D1 — numerosis-filament's two satellite edges become config-bound. Star.**
Both edges were measured first, and they are different shapes:

- **login is hard and fails late.** `NumerosisTenantPlugin:121`
  `->login(PasswordlessLogin::class)`. `::class` on an imported name never
  autoloads, so the panel *registers* fine without auth-ui; the fatal arrives
  at the first `/login` hit on a tenant subdomain, from Filament's route
  resolution, nowhere near the cause.
- **the register page is soft.** `Filament/Admin/Pages/RegisterTenant` is a
  ~20-line shell whose Blade is `@livewire('tenant-registration')` — an alias
  string, no class reference. Without onboarding it renders and throws
  `Unable to find component: [tenant-registration]`, reached only via
  `ListTenants`' "New Tenant" action.

**There is no cycle in either direction** — verified, not assumed:
`src/Livewire/Auth/` and `src/Livewire/Tenant/Registration/` contain **zero**
`Filament\` references. `PasswordlessLogin` extends spatie's
`OneTimePasswordComponent`; Filament merely accepts it as a login page. So
"graph" would have been acyclic and structurally fine — this was a
dependency-weight call, not a correctness one.

What Phase 7 must build for it:

- Two new **core**-owned config keys, in core's existing `numerosis.panels.*`
  section (core owns that section already — a satellite writing three segments
  deep into another package's namespace is exactly the `Arr::set()`
  auto-vivification hazard `.claude/rules/package-host-bootstrap.md` records):
  `numerosis.panels.tenant.login` and `numerosis.panels.admin.register_tenant_page`,
  both `class-string|null`, both defaulting to `null`.
- numerosis-filament reads them and **skips gracefully on null** — no
  `->login()` call at all (Filament falls back to its own login page), and no
  `RegisterTenant` page registered, with `ListTenants`' action conditional on
  the same key rather than on `RegisterTenant::class` existing.
- numerosis-auth-ui / numerosis-onboarding each register themselves into their
  key at boot, the same shape as `Features::register()` from Phase 3.

**D2 — per-package config, merged.** Core keeps `config/numerosis.php` with
core keys only; each satellite ships its own file and `mergeConfigFrom`s it,
and registers its feature through Phase 3's `Features::register()` seam rather
than appearing in core's `features` array. This is what removes the hard boot
failure `.claude/rules/package-boundaries.md` documents (a listed-but-missing
feature class reaching `$this->app->make()`) **by construction** rather than by
the warn-and-continue guard Phase 3.2 added — keep that guard anyway, it now
covers host-authored entries only.

**D3 — activity_log splits: recording in core, UI in filament.** The 9
migrations and the `LogsActivity` compat shim stay in core, because core's own
models (`Tenant\User`, `Invitation`) compose the trait — the tables must exist
wherever core does. `ActivityResource` and `ActivityLogFeature` move to
numerosis-filament, which is what the feature's own docblock already says it is
("the audit-log UI in the tenant panel"). `alizharb/filament-activity-log`
moves to numerosis-filament's `require-dev` + `suggest`.

**D4 — numerosis-ui exists, as a leaf.** Measured consumption of
`components/ui` (29 files): `components/billing` (8 files, core),
`livewire/auth` (5, auth-ui), `livewire/tenant` (2, onboarding),
`livewire/settings` (2), plus layouts, partials and the marketing pages. It is
a genuine leaf — nothing in it references tenancy, billing or Filament — and
every other package depends on it, **including core**, so it is not optional
for anyone. `livewire/flux` moves out of core's `require` into it, which does
not weaken `.claude/rules/testing.md`'s "a package rendering another package's
components must `require`, not `suggest`" rule: the requirement moves with the
views.

Marketing pages (`welcome`, `about`, `terms`, `privacy`, `features`) stay in
**core**, not ui — they are host-facing sample content, not shared primitives.

### Phase-7 prerequisites, built in this repo first ✅ DONE (2026-08-30)

Both are additive against the current single-package layout, and both are
verified by the existing suite here — the same reasoning
`.claude/rules/package-boundaries.md` gives for adding seams *before* moving
files, rather than rewriting the extension model and moving 400 files at once.
Suite after: 1 failed (the known `livewire.js` one) / 7 skipped / **615
passed**, on both matrix legs; PHPStan 0 outside baseline on both.

**D1's two seams.** `numerosis.panels.tenant.login` (class-string|null,
defaulting to the shipped `PasswordlessLogin`) and
`numerosis.panels.admin.tenant_registration_component`. Both read defensively:
null, empty, or a class that isn't installed all mean "skip that wiring".

**One deviation from D1 as written, deliberate:** the second key names the
wizard's **Livewire alias**, not the `RegisterTenant` page class. The page
belongs to the Filament layer and the wizard to onboarding, so neither may
name the other's class — and the alias is what the wizard is genuinely
addressed by anyway (`.claude/rules/tenant-registration-wizard.md` records
what using the raw FQCN cost last time). Because Filament *discovers* every
page in that directory, opting out happens in `RegisterTenant::canAccess()` /
`shouldRegisterNavigation()` rather than at the plugin's registration call;
`ListTenants` drops its "New Tenant" action to match, with no fallback create
action, since inserting a tenant row directly produces one with no database
and no owner. `tests/Feature/Filament/PanelUiSeamsTest` (6 tests) covers both
edges at the seam, because both fail *late* — a missing login component
registers cleanly and only fatals at the first `/login` on a tenant subdomain.

**The central-seeder seam.** `Numerosis::addCentralSeeder()` (mirror of
Phase 3.3's tenant one, called by `DatabaseSeeder`) **and**
`Numerosis::addPermissionContext()`, which is the one that actually solves the
open item below: a satellite contributes the noun half of a permission name
(`modules`) and `RoleAndPermissionSeeder` creates a row per default action
under guard `web` and grants them to `admin`. A whole-seeder seam alone would
have made each satellite duplicate that role wiring. Two tests in
`PackageContributionSeamsTest`; the permission one was verified to fail with
the contribution line removed.

### Open item this map creates ✅ CLOSED by the above

**There was no central-seeder contribution seam.** Phase 3.3 added
`Numerosis::addTenantSeeder()` but no central equivalent, and
`RoleAndPermissionSeeder` is a single core file that seeds the `modules`
permission context under guard `web` — a context that belongs to
numerosis-modules once it moves. A missing permission there 500s *every page*
in the panel, not just its own (`.claude/rules/auth-guards.md`), so this cannot
be left to the host. **Both now exist** (see the section above) — `addCentralSeeder()` for the
general case and `addPermissionContext()` for this one specifically, since
a satellite shipping only its own seeder would have had to duplicate the
role-granting half.

### Corrections against the original map (all still hold)

1. **`PurchaseModule`/`CancelModule` move to numerosis-modules, not core.**
   `src/Actions/Modules/PurchaseModule.php:9` hard-uses
   `InterNACHI\Modular\Support\Facades\Modules`. Keeping them in core while
   dropping `internachi/modular` from core is not possible.
2. **`src/Concerns/Modules/PurchasesModules.php` is not core.** It does
   `use Nvade\Numerosis\Filament\Concerns\NotifiesUser;`, returns
   `Filament\Actions\Action`, and its only consumers are two Filament pages.
   Left in core it creates core → filament → core.
3. **numerosis-filament is not a leaf.** `NumerosisTenantPlugin:121` does
   `->login(PasswordlessLogin::class)` (auth-ui) and
   `Filament/Admin/Pages/RegisterTenant.php` exists to host the wizard
   (onboarding). **Resolved by D1 above** — both become config-bound, filament
   requires core + ui only. (Line was 110 in the original note; it is 121
   post-Phase-5.)
4. **`src/Filament/Admin/Resources/Central/Modules/` (6 files) was claimed
   twice** — by "all `Admin/` resources" and by "all module Filament UI". The
   table above assigns it to numerosis-modules. Its `modules` permission
   context is seeded by `RoleAndPermissionSeeder` under guard `web`; see
   `.claude/rules/auth-guards.md` for why a missing permission there 500s
   every page in the panel, not just its own.

**Everything unassigned in the original now has a home** (D2/D3/D4 above, plus:
`database/seeders/*` and `stubs/` stay in core — subject to the central-seeder
seam noted above; `Contracts/Tenancy/ModulePlugin` goes to numerosis-modules,
since it `extends Filament\Contracts\Plugin` and that package depends on
filament anyway). `config/numerosis.php` is 907 lines, not 847 — recount before
quoting it again.

**[was wrong] — there are 9 activity_log migrations, not 7, and they are not
symmetrical.** 4 central (`create`, `add_event_column`, `add_batch_uuid_column`,
`add_attribute_changes_to_central_…`) and 5 tenant (the same first three, plus
`2026_05_01_000003_add_attribute_changes_…` and
`2026_05_06_145658_upgrade_activitylog`). `.claude/rules/package-boundaries.md`
says 7 and `.claude/rules/optional-dependencies.md` says 8 — both are wrong and
should be corrected when those files are next touched. The asymmetry matters
for whoever moves them: the two sides are not copies of each other, so "move
the activity_log migrations" is not a single mechanical operation, and the
tenant side carries an upgrade migration with no central counterpart.

---

## Phase 7 — scaffold and move

**In progress. `numerosis-ui` is extracted and green (2026-08-30);
four packages remain.** Mechanism learned doing it is in
`.claude/rules/package-split.md` — read that before the next one.

**Two decisions changed the plan text below**, both taken with the
maintainer: the satellites live as **siblings in `~/repos/private/`**, not in
a `numerosis-split/` wrapper; and 7.1/7.2 run **one package at a time**
(scaffold, move, green on its own, then re-verify core on both matrix legs)
rather than scaffolding all five first. The slice ordering paid for itself
immediately — see the `layouts`/`partials` correction below, which would
otherwise have been baked into four more packages before anyone noticed.

### 7.1/7.2 — numerosis-ui ✅ DONE (2026-08-30)

`~/repos/private/numerosis-ui`, wired into core through a `path` repository
with `symlink: true`, discovered normally via `extra.laravel.providers`.
`livewire/flux` moved out of core's `require` into it.

**Contents are narrower than D4 said, on evidence.** `resources/views/layouts`
and `resources/views/partials` were moved and moved straight back: they name
`Nvade\Numerosis\Support\{Numerosis,Features,Routes\RouteNames}`,
`Models\Central\CentralUser`, and call `tenancy()`, so a leaf package
shipping them would depend on core — the one property `numerosis-ui` exists
to have. It holds `components/ui`, `components/icons`, `flux`, and
`placeholder-pattern` (pulled in because `ui/card` renders it), and pins that
property with a test of its own.

**Zero view-reference edits**, because the package registers
`->hasViews('numerosis')` — the same namespace core uses. `addNamespace()`
appends rather than replaces, so both packages serve it.

Two things this surfaced that the remaining four will hit as well:

- **A directory-scanning test goes vacuous, not red, when what it guards
  moves.** The suite stayed at 615 passed while assertions fell 5561 → 5325.
  `DesignLanguageGuardTest`/`RegisteredComponentTagsTest` now enumerate paths
  from `FileViewFinder::getHints()['numerosis']` instead of one hardcoded
  directory, so they stay correct as further packages split out. **Diff the
  assertion count on every future move, not just the pass count.**
- **The Testbench harness overrides package registration**, because
  `getEnvironmentSetUp()` runs after every provider registers. It set
  `livewire.component_namespaces` as a whole array; setting the dotted key
  instead preserves siblings and lets the owning package's registration
  stand.

Verified after: core 1 failed (the known `livewire.js` one) / 7 skipped /
**615 passed, 5564 assertions** on **both** matrix legs, PHPStan 0 outside
baseline on both, and `numerosis-ui`'s own suite 4 passed standalone.

### Remaining: numerosis-filament, -onboarding, -auth-ui, -modules

Original brief, still accurate for those four:

**7.1** — Each repo on the
`spatie/laravel-package-tools` skeleton this repo uses
(`NumerosisServiceProvider::configurePackage()` is the template). Each
`composer.json` requires `numerosis` through a `path` repository pointing at
this repo, so all five compose and test locally without publishing.
`numerosis-ui/composer.json` is now the worked example of that wiring.

**History:** `git subtree split` preserves file-level blame and is worth it
for numerosis-filament and numerosis-onboarding. Note the original
justification was partly false — `.claude/rules/INDEX.md` states the bare
commit hashes cited throughout `.claude/rules/` belong to the **archived
saas-m repo**, so a subtree split does not recover those references. File
blame is the real benefit; decide on that basis.

**7.2** — Move per the Phase-6 table, fix namespaces, and use the
`Support/Compat/*` pattern for any newly-optional `extends`/`implements`/`use`
this surfaces (`.claude/rules/optional-dependencies.md`) rather than inventing
a second mechanism.

**7.3 — composer.json cleanup.** `livewire/flux` is done (it moved with the
views that render it, which is what keeps `.claude/rules/testing.md`'s
require-not-suggest rule satisfied). Still to move: `laravel/socialite`
(+ `socialiteproviders/discord`, `socialiteproviders/zoho` — the original plan
missed both), `internachi/modular`, `spatie/laravel-livewire-wizard`,
`ryangjchandler/laravel-cloudflare-turnstile` out of this repo's `require`.
**[was wrong]** `alizharb/filament-activity-log` is already `require-dev` +
`suggest`, not `require`. Each satellite must prove its own `suggest` list
degrades cleanly — a Blade tag rendering as literal text is a silent pass, not
clean degradation (`.claude/rules/testing.md`).

---

## Phase 8 — docs and verification

**8.1** — `docs/host-requirements.md` and `DEPENDENCIES.md` rewritten
per-package. Add the dev-master opt-in block from the top of this file to the
host requirements — a host cannot discover those two stability flags on its
own.

**8.2** — `.claude/rules/` files describing moved code
(`module-marketplace.md`, `filament-tenancy.md`,
`tenant-registration-wizard.md`, most of `auth-login.md`) move to their new
repo, and `INDEX.md` gains a pointer saying which repo now holds them —
otherwise a future session hunts a module-marketplace bug in a package that no
longer contains that code. `package-boundaries.md` should be rewritten or
deleted once the seams it argues for exist.

**8.3** — Full suite green in all five repos, on **both** matrix legs where
tenancy is involved.

**8.4** — One host (reuse `thin-app`) wiring all five, through
`numerosis:install --verify-only` plus a browser smoke test: central login →
registration wizard (each shipped identification mode) → tenant panel loads →
module marketplace purchase → admin panel shows the new tenant. This is the
check that proves the split composes; per-package suites do not.

---

## Ordering summary

```
0  baseline repair            ── ✅ DONE 2026-08-29 (suite + PHPStan green)
0.3 reopened migration fixes  ── ✅ DONE 2026-08-29
1  compat layers (v3 only)    ── ✅ DONE 2026-08-29 (1.1/1.2/1.3 all landed)
2  constraint + CI matrix     ── ✅ DONE 2026-08-30 (both legs green; dev-master PHPStan is a tracked follow-up)
3  contribution seams         ── ✅ DONE 2026-08-29
4  wizard step config         ── ✅ DONE 2026-08-30 (re-verified green now Phase 2/3 landed)
5  identification modes       ── ✅ DONE 2026-08-30 (all 3 modes; path mode's HTTP round trip needs a browser test)
6  package map agreed         ── ✅ DONE 2026-08-30 (six packages incl. numerosis-ui; D1–D4 recorded)
7  scaffold + move            ── IN PROGRESS: numerosis-ui done 2026-08-30; 4 packages left; next up
8  docs + verification        ── depends on 7
```

Phases 3 and 4 are the only ones that can run in parallel with the version
work. Everything else is a real dependency, not a preference.

# Config consolidation: thirteen partials → one publishable file

Written 2026-09-04. Not yet executed.

## Why

Two things are tangled together and both are worse for it.

**The config surface.** `config/numerosis.php` assembles thirteen partials
from `config/numerosis/` by `require __DIR__`. Because those paths would
resolve against a host's own config directory the moment the file were
copied there, the file can never be published. So a stub
(`config/stubs/numerosis.php`) is published instead. So partial host files
are the norm. So `HostConfig::numerosisConfig()` deep-fills them. So
`schema_version` exists to guard the one case deep-fill cannot see — a key
the host still names in an outdated shape. So `numerosis:install` carries a
version check for it. Five mechanisms, all descended from one `require
__DIR__`.

**`HostConfig`.** 594 lines, 20 methods, normalizing config that belongs to
stancl/tenancy, Fortify, Laravel's own `auth`/`session`/`queue`/`database`,
and spatie/laravel-activitylog. Most of the bulk is not the writing — it is
each method asking "is this key still holding the stock value its own
package shipped?" before deciding whether it may write. That question is
answered eight lines at a time, per key, and in five cases it is asked
redundantly: the method already knows the value it wants from *our* config
and sniffs the destination anyway.

Scope reduction did not touch any of this. Filament and the module system
leaving removed zero `HostConfig` methods — all twenty target tenancy, auth,
sessions, queues, filesystems and Fortify, every one of which is still here.

## Decisions

Recorded so they are not re-litigated. Each was considered and settled.

**Publish nothing but `config/numerosis.php`.** Not stancl's `tenancy.php`,
not Fortify's, not Laravel's. Publishing pre-filled vendor config was
seriously considered and rejected: a published `config/tenancy.php` is a
fork frozen at whatever stancl shipped the day it was published — new keys
never arrive, changed defaults never arrive, and nothing detects it. Today
`HostConfig` writes eleven keys into whatever stancl ships, so their
upgrades flow through untouched. It would also require committing
`workbench/config/tenancy.php` and hand-syncing it against what hosts are
told to publish, which makes `FreshHostTest` vacuous.

Publishing `config/auth.php`, `session.php`, `queue.php`, `database.php` or
`filesystems.php` is worse still: a Laravel app already ships all five, and
`vendor:publish` would overwrite the host's.

**Three things can never be static config, whatever else changes.**

1. `tenancy.migration_parameters.--path` — must contain an absolute path
   into this package's `database/migrations/tenant`, plus whatever any host
   or package registers at runtime through
   `Numerosis::addTenantMigrationPath()`. Hardcoding the vendor path breaks
   under path repositories and relocated vendor directories, and kills the
   extension seam outright.
2. `fortify.features` derived from `numerosis.features` — config files
   cannot read other config at load time and cannot hold closures, so a
   static copy is a second list free to disagree with the first.
3. `tenancy.{tenant,domain,central_user,tenant_user}_model` and
   `auth.providers.users.model` from `numerosis.models` — `Numerosis::model()`
   is the single place a host swaps a model.

**Preference versus correction** is the axis `HostConfig` gets rebuilt on —
not "which vendor file does this land in".

*Preference*: a host might legitimately want another value. Gets a key in
`config/numerosis.php`; `HostConfig` projects it onto the vendor key. Five of
these already work this way (`domains.central` → `tenancy.central_domains`,
`domains.apex` → `session.domain`, `models.*` → four tenancy model keys and
`auth.providers.users.model`) — they just carry redundant stock-value
sniffing on the destination on top.

*Correction*: the package does not work otherwise and there is no meaningful
alternative value. Gets **no config key anywhere**. Lives as a `const` table
or a short method in `HostConfig`. Exposing these as host surface invites
someone to set them wrong.

**Corrections stay in code, not in a package-internal config file.** A
never-published config file is the exact shape this plan is deleting; do not
reintroduce it under a new name.

**`schema_version` goes.** A package signals config changes with a version
constraint and a CHANGELOG entry; asking the consumer to hand-maintain an
integer duplicating the package version is the consumer's job
re-implemented badly. It is also self-refuting: the value is still `1`,
never bumped once, across Filament removal, module removal, three packages
folding into core, and the Fortify migration — every one of which
restructured top-level keys. It has never fired.

**`billing.plans` goes entirely**, with `ConfigPaymentPlanRepository` and
`ConfigPlan`. See Phase 1 for the evidence.

## Target

- One `config/numerosis.php`, ~420 lines, publishable, eleven top-level keys
- `HostConfig` ~180 lines, ~17 methods, no stock-value sniffing anywhere
- `config/numerosis/` and `config/stubs/` deleted
- Install contract unchanged: `composer require` + DB credentials + `APP_URL`

---

## Phase 1 — delete `billing.plans` and the config plan source

**The evidence.** `PaymentPlanSeeder` reads config for exactly two keys per
plan — `monthly_id` and `yearly_id` (`database/seeders/PaymentPlanSeeder.php:93`,
then `:136-137`). Of the ~11 keys per plan in config, two are consumed.

The other nine feed only `ConfigPaymentPlanRepository`, which is bound
nowhere (`billing.implementations` names `EloquentPaymentPlanRepository`;
nothing in `src`, `tests`, `config` or `workbench` references the Config one
outside its own file), has zero tests, and whose `ConfigPlan::price()` is
literally `return null;` against plans that carry no price key at all. The
"zero-migration quickstart" it advertises does not work today.

The two copies have already drifted. Starter is
`'Perfect for small teams getting started.'` in config and
`'Everything a small team needs to get going.'` in the seeder. Features are
modelled incompatibly: config uses marketing strings with a `--` prefix
meaning unavailable, the seeder uses an integer N into an ordered ten-item
`FEATURES` list with a pivot `available` flag.

The Stripe price ids are example data too — test-mode products in this
project's own Stripe account. No `.env` or `.env.example` in this repo or in
`../numerosis-thin-app` sets any `STRIPE_*_PLAN`; every one resolves to `''`
and all five tests that read them already `markTestSkipped`.

**Do:**

- `config/numerosis/billing.php` — delete the `plans` key (~110 lines) and
  the unused `use` imports it leaves behind
- Delete `src/Services/Billing/Plans/ConfigPaymentPlanRepository.php` and
  `src/Services/Billing/Plans/ConfigPlan.php`
- `database/seeders/PaymentPlanSeeder.php` — delete the `$configured` read
  at `:93`, drop the third parameter of `seedPlan()`, write
  `'monthly_id' => null, 'yearly_id' => null`. Keep `PLANS` and `FEATURES`;
  they are the single source for the three example plans the onboarding
  wizard renders, and the wizard reaches them through
  `EloquentPaymentPlanRepository`, not through config.
- Five tests read `Config::string('numerosis.billing.plans.0.monthly_id')` —
  switch to `env('STRIPE_STARTER_MONTHLY_PLAN', '')` and keep their existing
  `markTestSkipped` guards:
  - `tests/Feature/Actions/Billing/Checkout/CreateInlineSubscriptionTest.php:31`
  - `tests/Feature/Actions/Billing/Checkout/CompleteRedirectCheckoutTest.php:32`, `:110`
  - `tests/Feature/Http/Controllers/Billing/WebhookControllerSetupIntentTest.php:132`, `:184`

**Gotcha — `PlanMetadata` does not exist.** It is referenced in docblocks in
`Contracts/Billing/Plan.php:25`, `Models/Central/PaymentPlan.php:41,158`,
and both files being deleted, but no such class or PHPStan type alias is
defined anywhere. Twenty entries in `phpstan-baseline.neon` exist purely to
suppress "unknown class" errors for it. Deleting `ConfigPlan` and
`ConfigPaymentPlanRepository` removes roughly thirteen of them. Either
define a real `@phpstan-type PlanMetadata` alias for the remaining seven or
leave them baselined — but do not let the count silently grow.

## Phase 2 — delete `schema_version`

- `config/numerosis/schema-version.php`
- `src/Commands/InstallNumerosisCommand.php` — `verifyConfigSchemaVersion()`
  at `:739-760` and its call site at `:86`
- `tests/Feature/Console/Commands/InstallNumerosisCommandTest.php:565-597` —
  both tests
- `docs/host-requirements.md:118` (table row), `:268` (the caveat paragraph)
- `docs/architecture.md:195`, `:202`, `:218`

**Gotcha.** `HostRequirementsTest` asserts a pairing in both directions:
every `verify*()` method in `InstallNumerosisCommand` needs an `@verifies`
tag naming it in `InstallNumerosisCommandTest`. Deleting the method without
its tests, or the reverse, fails that test rather than passing quietly.

## Phase 3 — delete `config/numerosis/views.php`

Dead. `NumerosisServiceProvider.php:231` sets `numerosis.views.path`
unconditionally on every boot, so the partial's value is never observable.
The key itself stays — `Features/Tenancy/RegistrationWizardFeature.php:82`
reads it. Only the partial goes.

## Phase 4 — collapse the eleven remaining partials into one file

Eleven, after Phases 2 and 3 remove `schema-version` and `views`.

- Write a single `config/numerosis.php`: all `use` imports at the top, one
  short comment line per key group. The prose paragraphs move to
  `docs/host-requirements.md` §2 (Phase 7), which is already the documented
  reference and is already test-enforced.
- Delete `config/numerosis/` and `config/stubs/numerosis.php`
- `NumerosisServiceProvider::packageRegistered()` — replace the
  `mergeConfigFrom` at `:146` and the "must never be published" comment
  block above it (`:138-145`) with a recursive merge using
  `fillMissingKeys` (moved here from `HostConfig`). Laravel merges published
  config only one level deep, so a host file naming `billing` at all would
  otherwise shadow every sibling key the package later adds under it — and
  `HostConfig` reads deep keys.
- `NumerosisServiceProvider::packageBooted()` — the publish group at
  `:277-281` publishes the stub; point it at `config/numerosis.php` itself
  and rewrite the comment. Keep the `numerosis-config` tag.

Budget, from measured non-comment line counts: billing 166 (→ ~50 after
Phase 1), tenancy 69, social 43, features 41, models 28, auth 21, domains
20, routes 19, broadcasting 17, schedule 13, cache 12. Minus per-file
boilerplate, plus ~55 `use` lines and terse comments: **~420 lines.** Same
order as stancl's own `config/tenancy.php`.

## Phase 5 — four new keys

- `numerosis.tenancy.central_connection` → `'central'`
- `numerosis.tenancy.seeder` → `TenantDatabaseSeeder::class`
- `numerosis.auth.manage_fortify_features` → `true`
- `fortify.features` derived wholly from `numerosis.features`, replacing the
  current half-derivation

## Phase 6 — rewrite `HostConfig` on the preference/correction split

**Preferences** — project from `numerosis.*`, drop the destination sniffing:

| numerosis key | projects onto |
|---|---|
| `domains.central` | `tenancy.central_domains` |
| `domains.apex` | `session.domain` |
| `models.*` | `tenancy.{tenant,domain,central_user,tenant_user}_model`, `auth.providers.users.model` |
| `tenancy.central_connection` | `tenancy.database.central_connection` |
| `tenancy.seeder` | `tenancy.seeder_parameters.--class` |

**Corrections** — a `private const CORRECTIONS` table of plain key → value
pairs, applied by one loop with `if (Config::get($key) === null)`:

```
auth.guards.tenant
auth.providers.tenant
auth.passwords.tenant
activitylog.table_name
tenancy.filesystem.root_override.local
queue.failed.database
```

Five corrections are not plain key → value and stay as short methods:

- `tenancy.bootstrappers` — append our three if missing
- `tenancy.migration_parameters.--path` — append
  `Numerosis::tenantMigrationPaths()`, force `--realpath`
- `tenancy.filesystem.disks` — *remove* `livewire` (Livewire's temporary
  upload route is never tenant-identified; suffixing that disk surfaces as a
  bogus "invalid file type")
- `database.connections.central` — clone the host's `database.default`
- `auth.passwords.{default}` — the key name itself is dynamic, read from
  `auth.defaults.passwords`

**Delete outright:**

- `databaseLockOptions()` (43 lines) — regex-rewrites the host's own PDO DSN
  options string to append `innodb_lock_wait_timeout`. Silently edits
  something the host wrote. If the behaviour is still wanted, it belongs as
  a `verify*()` warning in `numerosis:install`, not a silent rewrite.
- `fortifyStockFeatures()` (15 lines) — reads Fortify's own shipped config
  out of `vendor/` by reflection at boot purely to answer "has the host
  chosen?". Phase 5's explicit `manage_fortify_features` key replaces it.
- `numerosisConfig()` — the deep-fill moves to the merge in Phase 4.

Target ~180 lines.

## Phase 7 — docs and the tests that pin them

- `docs/host-requirements.md` §2 — absorb the prose from the deleted
  partials. Corrections get a row each with "not overridable" in the
  override column, and a sentence saying why. Someone debugging "why is
  `session.domain` set to this" has to be able to find the answer even when
  they cannot change it.
- `tests/Feature/Docs/HostRequirementsTest.php:150` — the failure message
  currently reads "Add a §2 row saying what it is set to **and how to
  override it**", which stops applying to corrections. Reword it.

  The mechanism itself needs no change: the test regexes quoted dotted keys
  out of `HostConfig.php`'s **source text** (`:123`, `:126`), so keys moved
  into a `const CORRECTIONS` array are still caught. Verify this holds after
  the rewrite rather than assuming it.
- `docs/architecture.md` — the config section, including the "thirteen
  partials" description and the deep-fill/`schema_version` paragraphs
- `docs/extending.md` — the Fortify customization table, now that
  `fortify.features` derives from `numerosis.features`
- `CLAUDE.md` and `.ai/rules/index.md` — both describe thirteen partials;
  `.ai/rules/index.md`'s header says `config/numerosis.php` assembles
  thirteen and `numerosis.models` lists eight models

## Phase 8 — verify

Run in this order. Pint first: it edits files, so testing before formatting
means testing twice.

```
composer format
composer test
composer analyse
```

The four suites that actually exercise this work:

- `tests/Feature/FreshHostTest.php` — the load-bearing one. Boots without
  the pre-set config `TestCase` normally supplies, so `HostConfig` has to
  prove itself rather than being handed already-correct values.
- `tests/Feature/HostConfigTest.php` — asserts idempotency and, in several
  places, that a key is **not** in `HostConfig::applied()` when the host set
  it. Those assertions encode the stock-value sniffing being deleted; expect
  to rewrite them against the new preference/correction semantics rather
  than to keep them green as-is.
- `tests/Feature/Support/PackageContributionSeamsTest.php` — calls
  `HostConfig::apply()` directly at `:113`
- `tests/Feature/Console/Commands/InstallNumerosisCommandTest.php`

**Gotchas:**

- `tests/TestCase.php:183-200` pre-sets keys that `HostConfig::apply()` would
  also set, because Testbench runs `RegisterProviders` before
  `getEnvironmentSetUp()`. The docblock records that deleting the block was
  already tried once and does not hold for anything `HostConfig` computes
  from `numerosis.*`. Read it before touching it.
- `tests/TestCase.php:396` sets `'central'` explicitly, not `'mysql'`, and
  warns against "fixing" it — doing so breaks `HostConfigTest`'s idempotency
  assertion.
- The static analysis config is `phpstan.neon.dist`; there is no
  `phpstan.neon`. Level 9 with a baseline. A warm result cache hides errors,
  so compare cold-vs-cold when regenerating the baseline
  (`.ai/rules/static-analysis.md`).
- `workbench/` has no `config/` directory — app config comes from Testbench's
  skeleton in `vendor/orchestra/testbench-core/laravel/config/`. Nothing in
  this plan should change that.

## Phase 9 — record the rule

Add the preference/correction split to `.ai/rules/` with the
`codebase-learnings` skill. It is the non-obvious part: the axis is what a
host may legitimately choose, not which vendor file the key lands in, and a
future session will otherwise re-derive it from scratch or reintroduce
stock-value sniffing one key at a time.

## Out of scope

- Publishing any vendor config. Settled above; do not revisit mid-execution.
- Splitting `numerosis.*` into separate config namespaces.
- Typed config objects.
- Anything in `packages/ui`.

# Config consolidation: thirteen partials → one publishable file

Written 2026-09-04. Audited against the code 2026-09-05; the corrections from
that pass are folded in below. Not yet executed.

**Line numbers in this file drift.** Every reference was re-checked on
2026-09-05, but the tree moves. Grep for the symbol rather than seeking to a
line, and treat a mismatch as drift rather than as a missing thing.

**Start from a clean tree.** At audit time `package-scope-reduction` carried
18 uncommitted files — `spatie/laravel-activitylog` and
`spatie/laravel-one-time-passwords` moving `suggest` → `require`, both
`Support\Compat\*` shims deleted, `DEPENDENCIES.md` and three `docs/` files
mid-edit. None of it conflicts with this plan (`activitylog.table_name`
remains a valid correction either way), but commit or stash it first or the
config commits swallow it.

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

**`HostConfig`.** 554 lines, 21 private normalizers plus `apply()`,
`applied()`, `set()` and `fillMissingKeys()`, normalizing config that belongs to
stancl/tenancy, Fortify, Laravel's own `auth`/`session`/`queue`/`database`,
and spatie/laravel-activitylog. Most of the bulk is not the writing — it is
each method asking "is this key still holding the stock value its own
package shipped?" before deciding whether it may write. That question is
answered eight lines at a time, per key, and in five cases it is asked
redundantly: the method already knows the value it wants from *our* config
and sniffs the destination anyway.

Scope reduction did not touch any of this. Filament and the module system
leaving removed zero `HostConfig` methods — every one targets tenancy, auth,
sessions, queues, filesystems or Fortify, all of which are still here.

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
`config/numerosis.php`; `HostConfig` projects it onto the vendor key. Several
already work this way (`domains.apex` → `session.domain`, `models.*` → four
tenancy model keys and `auth.providers.users.model`) — they just carry
redundant stock-value sniffing on the destination on top.

*Correction*: the package does not work otherwise and there is no meaningful
alternative value. Gets **no config key anywhere**. Lives as a `const` table
or a short method in `HostConfig`. Exposing these as host surface invites
someone to set them wrong.

**A correction still has to sniff, and that is not the same thing as the
sniffing being deleted.** The original draft targeted "no stock-value
sniffing anywhere"; that is unreachable and the class docblock already says
why — *"A key is written only while unset or still holding the stock value
Laravel or stancl/tenancy shipped, several of which never resolve to null."*
What this plan actually removes is sniffing on the **preference** side, where
the method already knows the value it wants from `numerosis.*` and asks the
destination anyway. Corrections keep a guard; see Phase 6 for its shape.

**Corrections stay in code, not in a package-internal config file.** A
never-published config file is the exact shape this plan is deleting; do not
reintroduce it under a new name.

**`schema_version` goes.** A package signals config changes with a version
constraint and a CHANGELOG entry; asking the consumer to hand-maintain an
integer duplicating the package version is the consumer's job
re-implemented badly.

The original argument here — "the value is still `1`, never bumped once" — no
longer holds: `config/numerosis/schema-version.php` names `2`. The decision
survives on a stronger fact the bump itself created. The stub still publishes
`schema_version => 1` (`config/stubs/numerosis.php`), so a host that runs
`vendor:publish --tag=numerosis-config` and then `numerosis:install` **fails
today**, told to compare their file against a `config/numerosis.php` that
cannot be published. Two hand-maintained integers in two files, one of which
is generated from the other by copy-paste, is the mechanism failing exactly
the way a hand-maintained version always does.

**`billing.plans` goes entirely**, with `ConfigPaymentPlanRepository` and
`ConfigPlan`. See Phase 1 for the evidence.

## Target

- One `config/numerosis.php`, ~420 lines, publishable, eleven top-level keys
- `HostConfig` ~200 lines: one `CORRECTIONS` table, five methods for the
  corrections that are not plain key → value, and no stock-value sniffing on
  the preference side
- `config/numerosis/` and `config/stubs/` deleted
- Install contract unchanged: `composer require` + DB credentials + `APP_URL`

## How to execute this

**Commit per phase.** Phases 1–5 are deletions and a file move; Phase 6 is
the only genuinely risky one.

**Get green after Phase 5, before starting Phase 6.** That separates "did
collapsing the config break anything" from "did rewriting `HostConfig` break
anything". Run together, the two are indistinguishable in one red suite and
you will bisect by hand.

Phase 2 has an ordering constraint of its own: the `verify*()` method and
its two tests must go in the **same commit**, or the `@verifies` pairing
test in `HostRequirementsTest` fails on the intermediate state.

---

## Phase 1 — delete `billing.plans` and the config plan source

**The evidence.** `PaymentPlanSeeder` reads config for exactly two keys per
plan — `monthly_id` and `yearly_id` (`database/seeders/PaymentPlanSeeder.php:88`,
then `:131-132`). Of the ~11 keys per plan in config, two are consumed.

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
and all six tests that read them already `markTestSkipped`.

**Do:**

- `config/numerosis/billing.php` — delete the `plans` key (`:91` to the entry
  before the file's closing `];`, ~163 lines) and the unused `use` imports it
  leaves behind. Also delete the sentence at `:84-87` describing
  `ConfigPaymentPlanRepository` as the zero-migration quickstart.
- Delete `src/Services/Billing/Plans/ConfigPaymentPlanRepository.php` and
  `src/Services/Billing/Plans/ConfigPlan.php`
- `database/seeders/PaymentPlanSeeder.php` — delete the `$configured` read
  at `:88`, drop the third parameter of `seedPlan()`, write
  `'monthly_id' => null, 'yearly_id' => null`. The class docblock at `:26`
  also names `numerosis.billing.plans.starter`; drop that clause. Keep
  `PLANS` and `FEATURES`; they are the single source for the three example
  plans the onboarding wizard renders, and the wizard reaches them through
  `EloquentPaymentPlanRepository`, not through config.
- **Six** tests read `Config::string('numerosis.billing.plans.0.monthly_id')`
  — switch to `env('STRIPE_STARTER_MONTHLY_PLAN', '')` and keep their existing
  `markTestSkipped` guards:
  - `tests/Feature/Actions/Billing/Checkout/CreateInlineSubscriptionTest.php:31`
  - `tests/Feature/Actions/Billing/Checkout/CompleteRedirectCheckoutTest.php:32`, `:110`
  - `tests/Feature/Http/Controllers/Billing/WebhookControllerSetupIntentTest.php:134`, `:191`, `:252`

**Gotcha — `PlanMetadata` now exists, and the original instruction here would
undo it.** The draft said the alias was undefined and told you to replace it
with `array<string, mixed>`. That was true when written and is not any more:
`phpstan.neon.dist` declares it under `typeAliases` (added 2026-09-04), and
`grep -c PlanMetadata phpstan-baseline.neon` returns **0**.
`.ai/rules/static-analysis.md` records what declaring it bought — 23 baseline
entries cleared, and one live defect surfaced in `SeatLimitPlanPolicy` that
the baseline had been hiding. Widening the type back to `array<string, mixed>`
re-hides that class of error.

**Do: keep the alias.** After the two deletions, `PlanMetadata` survives in
`Contracts/Billing/Plan.php:25` and `Models/Central/PaymentPlan.php:41,158`
only. Leave all three. The one edit needed is in `phpstan.neon.dist`: its
comment above the alias describes the type as "the raw
`numerosis.billing.plans` entry, or the `metadata` JSON column that mirrors
it", and says "four files declare it". After this phase only the JSON column
is left, and only two files declare it. Reword both facts.

## Phase 2 — delete `schema_version`

- `config/numerosis/schema-version.php`
- `src/Commands/InstallNumerosisCommand.php` — `verifyConfigSchemaVersion()`
  at `:672-698` and its call site at `:83`
- `tests/Feature/Console/Commands/InstallNumerosisCommandTest.php:553-590` —
  both tests
- `docs/host-requirements.md:118` (table row), `:288` (the caveat paragraph)
- `docs/architecture.md:198`, `:205`, `:221`
- `config/stubs/numerosis.php` — the whole file goes in Phase 4 anyway, but
  note that its `schema_version => 1` against the package's `2` is the live
  failure described in Decisions. Do not "fix" it by bumping the stub; the
  fix is that both disappear.

**Gotcha.** `HostRequirementsTest` asserts a pairing in both directions:
every `verify*()` method in `InstallNumerosisCommand` needs an `@verifies`
tag naming it in `InstallNumerosisCommandTest`. Deleting the method without
its tests, or the reverse, fails that test rather than passing quietly.

## Phase 3 — delete `config/numerosis/views.php`

Dead. `NumerosisServiceProvider.php:213` sets `numerosis.views.path`
unconditionally on every boot, so the partial's value is never observable.
The key itself stays — `Features/Tenancy/RegistrationWizardFeature.php:70`
reads it. Only the partial goes.

## Phase 4 — collapse the eleven remaining partials into one file

Eleven, after Phases 2 and 3 remove `schema-version` and `views`.

- Write a single `config/numerosis.php`: all `use` imports at the top, one
  short comment line per key group. The prose paragraphs move to
  `docs/host-requirements.md` §2 (Phase 7), which is already the documented
  reference and is already test-enforced.
- Delete `config/numerosis/` and `config/stubs/numerosis.php`
- `NumerosisServiceProvider::packageRegistered()` — replace the
  `mergeConfigFrom` at `:142` and the "must never be published" comment
  block above it (`:139-141`) with a recursive merge using
  `fillMissingKeys` (moved here from `HostConfig`). Laravel merges published
  config only one level deep, so a host file naming `billing` at all would
  otherwise shadow every sibling key the package later adds under it — and
  `HostConfig` reads deep keys. Keep the `Numerosis::resetModelCache()` call
  that follows at `:144`, and keep it after the merge.
- `NumerosisServiceProvider::packageBooted()` — the publish group at
  `:259-262` publishes the stub; point it at `config/numerosis.php` itself
  and rewrite the comment. Keep the `numerosis-config` tag.

**This move also fixes a live ordering hazard, which is worth knowing about
so it is not mistaken for a regression.** `numerosisConfig()` runs *last* in
`HostConfig::apply()`, after every method that reads `numerosis.*`. So today a
host whose file names `domains` without naming `apex` has `sessionDomain()`
read an unfilled key and skip. Deep-filling at register time means every
`numerosis.*` read inside `apply()` sees a complete array for the first time.
Expect small, correct changes in what `applied()` reports for partial host
configs.

Budget, from measured non-comment line counts: billing 166 (→ ~50 after
Phase 1), tenancy 69, social 43, features 41, models 28, auth 21, domains
20, routes 19, broadcasting 17, schedule 13, cache 12. Minus per-file
boilerplate, plus ~55 `use` lines and terse comments: **~420 lines.** Same
order as stancl's own `config/tenancy.php`.

## Phase 5 — three new keys, and the Fortify gate they replace

- `numerosis.tenancy.central_connection` → `'central'`
- `numerosis.tenancy.seeder` → `TenantDatabaseSeeder::class`
- `numerosis.auth.manage_fortify_features` → `true`

**On `fortify.features`: what changes is the gate, not the derivation.** The
draft said "derived wholly from `numerosis.features`". That is not reachable
and should not be attempted. `numerosis.features` has no entry for
registration, `updateProfileInformation` or `updatePasswords` — those three
are unconditional in `HostConfig::fortifyFeatures()` and stay unconditional.
`PasswordResetFeature` is the only genuine derivation, and
`EmailVerificationFeature` is in the list but documents itself as "not a real
toggle". **Do not invent three new feature classes to close the gap.**

The actual change: `fortifyFeatures()` currently decides whether it may write
by comparing against `fortifyStockFeatures()`, which reflects Fortify's own
shipped config out of `vendor/`. Replace that condition with
`numerosis.auth.manage_fortify_features`, and delete `fortifyStockFeatures()`.
The body of the write is unchanged.

## Phase 6 — rewrite `HostConfig` on the preference/correction split

**Preferences** — project from `numerosis.*`, drop the destination sniffing:

| numerosis key | projects onto |
|---|---|
| `domains.apex` | `session.domain` |
| `models.*` | `tenancy.{tenant,domain,central_user,tenant_user}_model`, `auth.providers.users.model` |
| `tenancy.central_connection` | `tenancy.database.central_connection` |
| `tenancy.seeder` | `tenancy.seeder_parameters.--class` |

**`domains.apex` → `session.domain` needs a decision before you delete its
guard.** `numerosis.domains.apex` defaults to `Domains::apexFromAppUrl()`, so
it is never empty and an unconditional projection always writes. That
overwrites a host that set `session.domain` in `config/session.php` or through
`SESSION_DOMAIN` — the standard places for it — and breaks
`test_it_does_not_override_a_hosts_session_domain` (`HostConfigTest:300`).

Unlike `domains.central` this one is genuinely arguable: the plan's whole
premise is that `numerosis.*` is where a host expresses this. But make the
call deliberately and write it in the commit message, because "your
`SESSION_DOMAIN` is now ignored" is the kind of thing a host finds out from a
logged-out user, not from a test. **Recommended: keep the null-check.** It
costs three lines, it is not the redundant destination-sniffing this plan is
about (there is no `numerosis.*` value being second-guessed — the host set the
vendor key directly and meant it), and it leaves `SESSION_DOMAIN` working.

**`domains.central` was in this table and comes out of it.**
`numerosis.domains.central` is a single string
(`config/numerosis/domains.php:47`); `tenancy.central_domains` is a list. A
host legitimately serving apex, `www` and an admin hostname sets three
entries there, and `centralDomains()` today bails on any non-stock list —
`HostConfigTest`'s `it leaves a hosts list shaped override untouched` is that
behaviour. Projecting unconditionally flattens three hostnames to one and
404s two of them. Keep `centralDomains()` as a method with its stock-list
check. Widening `domains.central` to accept a list would make it a real
preference, but that is a separate change and out of scope here.

**Corrections** — a `private const CORRECTIONS` table applied by one loop.
**Not a plain `key => value` map, and not a plain null check.** Two of the six
never resolve to null, so `if (Config::get($key) === null)` would silently
stop correcting them:

- `tenancy.filesystem.root_override.local` — stancl ships
  `'%storage_path%/app/'` (`vendor/stancl/tenancy/assets/config.php:117`).
  Null-checking it loses the Laravel 11 `storage/app/private` correction.
- `queue.failed.database` — Laravel ships `env('DB_CONNECTION', 'sqlite')`
  (`vendor/laravel/framework/config/queue.php:128`). Null-checking it puts
  failed jobs back in whichever tenant database the worker happened to be in
  when the job died, which is the entire reason the method exists.
  `tests/TestCase.php:396-397` carries a comment about this exact
  coincidental-with-`database.default` case.

So the table is `key => [list of stock values, value]`, and the loop writes
when the current value is `null` **or** appears in that list:

| key | stock values it also replaces |
|---|---|
| `auth.guards.tenant` | — (Laravel ships no `tenant` guard) |
| `auth.providers.tenant` | — |
| `auth.passwords.tenant` | — |
| `activitylog.table_name` | — (spatie ships no default; verified in `vendor/spatie/laravel-activitylog/config/activitylog.php`) |
| `tenancy.filesystem.root_override.local` | `'%storage_path%/app/'` |
| `queue.failed.database` | `Config::get('database.default')` |

`queue.failed.database`'s stock value is not a constant, so the table holds a
sentinel the loop resolves, or that one key stays a method. Either is fine;
pick one and do not spread the decision across both.

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

Plus `centralDomains()`, which stays a method for the reason above.

**Delete outright:**

- `databaseLockOptions()` (43 lines) — regex-rewrites the host's own PDO DSN
  options string to append `innodb_lock_wait_timeout`. Silently edits
  something the host wrote.

  **Write no replacement.** The draft said the behaviour "belongs as a
  `verify*()` warning in `numerosis:install`" as though one had to be added.
  `verifyLockWaitTimeout()` already exists at `InstallNumerosisCommand:255`,
  with a failure-path test at `InstallNumerosisCommandTest:205-216`. It has
  never been able to fire on a real host: `databaseLockOptions()` runs at
  boot and repairs the exact condition the verify checks for, before the
  command ever looks. Deleting the fixer un-shadows the verifier, which is
  the whole change. Adding a second verify method would fail
  `HostRequirementsTest::test_every_verify_method_has_a_failure_path_test`
  unless it also got an `@verifies`-tagged test.

  Note this converts a silent boot-time repair into a hard `numerosis:install`
  failure for a host that sets `lock_wait_timeout` alone — the command has no
  warning channel, only `$this->failures`. That is the intended trade (loud
  and fixable beats silent and surprising), but say so in the commit message.
  The existing test sets config after boot, so it stays green either way.
- `fortifyStockFeatures()` (15 lines) — reads Fortify's own shipped config
  out of `vendor/` by reflection at boot purely to answer "has the host
  chosen?". Phase 5's explicit `manage_fortify_features` key replaces it.
- `numerosisConfig()` — the deep-fill moves to the merge in Phase 4.
  `fillMissingKeys()` moves with it.

Target ~200 lines.

## Phase 7 — docs and the tests that pin them

- `docs/host-requirements.md` §2 — absorb the prose from the deleted
  partials. Corrections get a row each with "not overridable" in the
  override column, and a sentence saying why. Someone debugging "why is
  `session.domain` set to this" has to be able to find the answer even when
  they cannot change it.

  **Every new row also needs a valid "Checked by" cell**, which the draft did
  not mention. `test_every_documented_row_names_a_check_that_exists` requires
  that cell to name **exactly one** method that exists on
  `InstallNumerosisCommand` (`preg_match_all(...) !== 1` is the failure — two
  methods in a cell fails as surely as zero), *or* to be an em-dash **with a
  reason written after it**; a bare `—` fails its own assertion. Corrections
  have no `verify*()` behind them, so they take the reasoned-dash form.
- `tests/Feature/Docs/HostRequirementsTest.php:151` — the failure message
  currently reads "Add a §2 row saying what it is set to **and how to
  override it**", which stops applying to corrections. Reword it.

  The mechanism itself needs no change: the test regexes quoted dotted keys
  out of `HostConfig.php`'s **source text** (`:123`, `:126`), so keys moved
  into a `const CORRECTIONS` array should still be caught. One known blind
  spot to leave alone: `"auth.passwords.{$broker}"` is interpolated, so the
  regex never matched it and still will not.

  **Do: prove it, don't assume it.** Add a bogus key to `CORRECTIONS`,
  confirm the test fails naming that key, remove it. `.ai/rules/testing.md`
  requires a repaired assertion be made to fail before it is trusted, and
  this is the only thing standing between the refactor and a safety net that
  silently stopped catching anything.
- `docs/architecture.md` — the config section, including the "thirteen
  partials" description and the deep-fill/`schema_version` paragraphs
- `docs/extending.md:91-108` — the paragraphs under the Fortify customization
  table say `fortify.features` is defaulted "only while the key still holds
  Fortify's own shipped list". Phase 5 replaces that signal with
  `numerosis.auth.manage_fortify_features`. The same passage credits
  `registerFortify()` with the derivation; it is `HostConfig::fortifyFeatures()`.
  Fix both while you are in there.
- `CLAUDE.md` and `.ai/rules/index.md` — both describe thirteen partials;
  `.ai/rules/index.md`'s header says `config/numerosis.php` assembles
  thirteen and `numerosis.models` lists eight models. **It lists nine**
  (`config/numerosis/models.php:35-43`); fix the count while you are there.
  Separately, and not this plan's problem: `PaymentPlanSeeder` calls
  `Numerosis::model(PlanFeature::class)` for a model the list does not carry.
  Leave it; note it if you want a follow-up.

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
- `tests/Feature/HostConfigTest.php` — see below; this is the one that will
  go wrong.
- `tests/Feature/Support/PackageContributionSeamsTest.php` — calls
  `HostConfig::apply()` directly at `:113`
- `tests/Feature/Console/Commands/InstallNumerosisCommandTest.php`

### `HostConfigTest` — rewrite by category, and report the count

Its `assertNotContains(..., HostConfig::applied())` assertions (`:130`,
`:171`, `:288`, `:370`, `:392`, `:447`) encode "the host set it, so we did
not write". That stays true for **corrections**, which keep their
null-or-stock guard, and for the four methods that survive unchanged
(`tenancy.bootstrappers` at `:130`, `tenancy.migration_parameters` at `:171`,
`database.connections` at `:288`, `auth.passwords.users` at `:392`). It
becomes **false by design** only for **preferences**, which now always project
from `numerosis.*` — `:370` (`auth.providers.users.model`) and `:447`
(`fortify.features`, whose guard moves to `manage_fortify_features`).

So this is a smaller rewrite than the draft implied: two of those six
assertions change meaning, not six. A third test outside that list,
`test_it_does_not_override_a_hosts_session_domain` (`:300`), changes only if
you take the non-recommended branch in Phase 6.

Do not try to keep those green. Keeping them green means reintroducing the
stock-value sniffing one key at a time, which is the thing this plan exists
to delete.

Rewrite around four cases:

1. a preference projects onto its vendor key
2. a host-set correction is left alone
3. an unset correction lands
4. `apply()` twice is idempotent — `applied()` is empty on the second run

Per `CLAUDE.md`, deleting tests needs approval. Rewriting in place is fine;
shrinking coverage is not. **State the before/after test count** in the
commit message or the final report rather than letting it change unremarked.

The before count, measured 2026-09-05: `HostConfigTest` is **38 tests, 496
lines**; `HostRequirementsTest` is 4. Both green together (42 passed, 148
assertions, 1.25s).

### `tests/TestCase.php` — add to it, do not delete from it

`:178-207` pre-sets keys `HostConfig::apply()` would also set, because
Testbench runs `RegisterProviders` before `getEnvironmentSetUp()`. The
comment there records that deleting the block was already tried once (319 of
526 tests failed) and does not hold for anything `HostConfig` computes from
`numerosis.*`.

Phase 5 adds exactly that kind of key (`tenancy.central_connection`,
`tenancy.seeder`), so this block likely needs **two more entries, not
fewer**. If you find yourself wanting to remove it, something in Phase 6
went wrong.

`:399` sets `queue.failed.database` to `'central'` explicitly, not `'mysql'`,
and the comment above it warns against "fixing" it — doing so breaks the
idempotency assertion. Leave it. It is also the clearest statement in the
repo of why that key's correction cannot be a null check.

### Baseline — once, at the end, cold on both sides

The static analysis config is `phpstan.neon.dist`; there is no
`phpstan.neon`. Level 9 with a baseline.

Regenerate **once, after Phase 7** — not per phase. Run
`vendor/bin/phpstan clear-result-cache` before the before-run and again
before the after-run; a warm result cache hides errors and bakes them into
the new baseline (`.ai/rules/static-analysis.md`).

**The "baseline should shrink" expectation is gone.** It rested on twenty
`PlanMetadata` entries disappearing in Phase 1; those were already cleared on
2026-09-04 when the alias was declared. Measured 2026-09-05: the baseline is
884 lines and contains **zero** `PlanMetadata` entries and **zero** entries
naming `Billing/Plans`. Expect roughly no change in size.

The check that still holds: **it must not grow.** A new entry means something
regressed and the baseline is now hiding it — find the entry rather than
accepting it.

### `workbench/config/` — a tripwire, not a task

`workbench/` has no `config/` directory; app config comes from Testbench's
skeleton in `vendor/orchestra/testbench-core/laravel/config/`. Nothing here
should change that.

If you find yourself wanting to create `workbench/config/numerosis.php` to
make a test pass, the design went wrong — that is the failure mode
publishing vendor config would have caused, arriving by the back door.

## Phase 9 — record the rule

Add the preference/correction split to `.ai/rules/` with the
`codebase-learnings` skill. It is the non-obvious part: the axis is what a
host may legitimately choose, not which vendor file the key lands in, and a
future session will otherwise re-derive it from scratch or reintroduce
stock-value sniffing one key at a time.

Record the second half too, since it is the part that is easy to get wrong on
a re-derivation: **a correction still needs a stock-value guard, because some
vendor keys never resolve to null.** Name
`tenancy.filesystem.root_override.local` (stancl ships
`'%storage_path%/app/'`) and `queue.failed.database` (Laravel ships
`env('DB_CONNECTION', 'sqlite')`) as the worked examples.

**Order this after Phase 7, and diff `.ai/rules/index.md` afterwards.**
`record-rule` regenerates that whole table from `paths:` frontmatter and
discards the preamble — the file says so itself, and it has already eaten 82
lines down to 9 once. Phase 7 edits that preamble; this phase can delete the
edit.

## Out of scope

- Publishing any vendor config. Settled above; do not revisit mid-execution.
- Splitting `numerosis.*` into separate config namespaces.
- Typed config objects.
- Anything in `packages/ui`.

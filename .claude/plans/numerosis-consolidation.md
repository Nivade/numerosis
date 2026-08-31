# Numerosis consolidation — v3-only, monorepo, modules in core

> **Supersedes the remaining work in `.claude/plans/memoized-tinkering-meadow.md`**
> (Phases 7–8 of it). That file stays as the record of Phases 0–6 and the three
> completed extractions; do not delete it, do not take its Phase 7/8 text as
> current. Written 2026-08-30 after auditing it at 1518 lines.
>
> **Concurrency note:** a parallel session completed the `numerosis-filament`
> extraction at 16:35 on 2026-08-30 and was still writing to
> `memoized-tinkering-meadow.md` and `.claude/rules/package-split.md`.
> Confirm nothing else is in flight before starting section B.
>
> Read first: `.claude/rules/package-split.md` (mechanism from three real
> extractions), `.claude/rules/package-boundaries.md`.

## Goal

One installable core (tenancy + billing + provisioning + auth mechanics) with
UI layers a host can decline. A consumer runs `composer require nvade/numerosis-filament`
and gets only what they asked for.

## Project constraint: breaking changes are free

**No existing installs, app not live** (confirmed 2026-08-29). Migrations may
be rewritten, renamed or deleted in place; public API may be narrowed or removed
with no deprecation cycle; Composer constraints may be raised freely. Where a
`.claude/rules/` file argues "we can't do X, it breaks existing installs", treat
the *mechanism* it documents as true and the *conclusion* as void.

This is what makes D-A and D-C below correct: a compatibility layer exists to
protect consumers, and there are none.

## Decisions (2026-08-30, with the maintainer)

**D-A — `stancl/tenancy` v3 only. Delete the dual-version layer.**
Measured cost: 8 compat shims, 215 LOC of version-routing support classes, 27
runtime branches across 12 files, 427 LOC of hand-written PHPStan reflection
stubs that must mirror real signatures exactly, ~405 baseline entries across
two files, three PHPStan configs, a 4-job CI matrix, and ×2 verification on
every package move. Delivered value: one bug (`PreservingPathTenantResolver`'s
static property), in path mode, which has no HTTP coverage either way. No host
can install dev-master without root-level `minimum-stability: dev` plus a
`stancl/jobpipeline: 2.0.0-rc7` pin, so the leg protected nobody. Port to v4 as
one hard cut when stancl tags a release, driven by
`.claude/rules/stancl-tenancy-v4.md` — that file is the durable asset and
survives deleting all the code.

**D-B — one repo, `packages/*`, read-only splits pushed on tag.**
The sibling-repo layout already had every coupling property of a monorepo —
shared test harness, real coverage in core's suite, symlinked `path` repos at
fixed relative paths, lockstep `@dev` versions — and none of the benefits: no
atomic commit or revert across a boundary, N PRs per cross-cutting change, N CI
signals. Consumers see no difference. Same pattern as Laravel
(`laravel/framework` → `illuminate/*`), Symfony, and Filament.

**D-C — the module system stays in core.** Extracting it meant ~30 files
threaded through 13 top-level `src/` directories, two Eloquent models whose
migrations live in core, two `Contracts/Billing/*` contracts, the only
satellite→satellite edge in the map, and a double-move of the 12 module
Filament UI files the filament slice has already taken. `class_exists`-guarding
`internachi/modular` — the pattern this repo already uses eight times — buys the
same install-size win for a fraction of the work. **This also makes the filament
slice's "module UI rides here temporarily" note permanent and correct**, rather
than a debt.

Net: 6 packages → 5 units in 1 repo; 4 CI jobs → 2. All five now exist:
core plus `packages/{ui,auth-ui,filament,onboarding}`.

## Toolchain

No Sail here (`.claude/rules/testing.md`).

```bash
docker compose ps                                    # numerosis-mysql-1 healthy
npm install && npx playwright install chromium       # once; see section E — required for EVERY pest run
vendor/bin/pint                                      # FIRST, before pest
php -d memory_limit=1G vendor/bin/pest --compact
vendor/bin/pest --compact --filter=SomeTest
php -d memory_limit=1G vendor/bin/pest --testsuite=Browser   # never pipe to `tail`; see section E
pkill -f "playwright run-server"                     # after any interrupted browser run
printf 'includes:\n  - %s/phpstan.neon.dist\nparameters:\n  tmpDir: /tmp/phpstan-audit\n' "$PWD" > /tmp/phpstan-audit.neon
php -d memory_limit=2G vendor/bin/phpstan analyse -c /tmp/phpstan-audit.neon --no-progress
```

Baseline to hold, as of section B: **0 failed / 7 skipped / 641 passed
(6333 assertions)** — was 623 / 4816 after section A, before the satellite
suites joined the root run. PHPStan does **not** currently hold at 0 outside
its 200-entry baseline; see section B's "PHPStan" note for why that predates
B. **Diff the
assertion count as well as the pass count after any move, and attribute the
delta** — a directory-scanning test goes vacuous, not red, when what it guards
moves. It has moved twice for boring reasons (5540 → 4882 when `ArchTest`'s
scan handed 110 files to the filament package's own `BoundaryTest`; 4882 →
4816 when the same scan lost the 10 files section A deleted).

---

## A — delete the dual-version layer ✅ DONE (2026-08-31)

**Verified: 0 failed / 7 skipped / 623 passed (4816 assertions) in ~112s;
PHPStan 0 outside a regenerated 200-entry baseline; Pint clean.** One CI job
axis, one PHPStan config, one `stancl/tenancy` constraint (`^3.10`).

12 files deleted (8 shims + `TenancyVersion` + `TenancyConfigKeys` + 2
PHPStan stubs) plus 3 config files; every one of the 27 runtime branches
collapsed to its v3 arm.

Four things worth carrying, none of which were in the plan text:

- **`Membership::getCentralResourceClass()` went with `PivotWithCentralResource`.**
  It was declared to satisfy the dev-master interface and is called by
  nothing on v3 — grep confirmed a single occurrence in the whole tree,
  its own declaration.
- **`InitializeTenancyByDomainOrSubdomain`'s constructor lost two
  parameters, not just the gated `parent::__construct()` call.** `Tenancy`
  and `DomainTenantResolver` were only ever there to forward to dev-master's
  parent; v3's parent resolves both from the container inside `handle()`.
  It now takes `Repository` alone.
- **The satellites had to be swept too.** `numerosis-filament`'s
  `NumerosisAdminPlugin` read `TenancyConfigKeys::key('central_domains')`,
  which the core-only grep did not cover — 615 tests failed on the first run
  purely from that one line. Grep `../numerosis-*` as well as `src`/`tests`
  before deleting anything public.
- **Regenerating the baseline needs a diff, not just a green run.** From
  empty it baselines any regression the change introduced. Old vs new paired
  on `(message, path)`: 33 added, 32 removed, **none in `src/`** — all the
  documented Larastan host-subclass false positives and test-idiom noise.
  Recorded in `.claude/rules/static-analysis.md`, along with the trap that
  cost the most time here: a baseline's `path:` entries resolve against *the
  baseline file's own directory*, so a copy of it in `/tmp` matches nothing
  and silently reports the entire baselined set as live errors.

**One deliberate deviation from A.3.** The plan said to keep
`TenancyConfigKeys`'s read-modify-write of the parent array. It was dropped,
because on v3 all four keys are a single segment under `tenancy.`
(`tenancy.central_domains`, not `tenancy.identification.central_domains`) —
there is no intermediate array to truncate, so the RMW protected nothing that
a plain `Config::set()` doesn't. It is also worth being precise that RMW was
never the fix it was described as: `mergeConfigFrom()`'s one-level
`array_merge()` keeps an existing partial parent wholesale either way, so
writing `tenancy.filesystem` as a merged array before stancl registers
truncates it exactly as a dotted write would. **What actually fixes it is
phase, not form** — `HostConfig::apply()` running from a `booting()` callback,
after every provider's `register()`, which is unchanged and still in place
(`.claude/rules/package-host-bootstrap.md`). The moved keys carry a pointer
to `.claude/rules/stancl-tenancy-v4.md` at their write site instead.

### Original brief, kept for reference

Do this **first**: it shrinks every later step, and every remaining move is
currently verified twice.

**A.1 — remove the shims.** `src/Support/Compat/Tenancy/*` (8 files). The 26
consumers revert to direct `Stancl\Tenancy\*` imports, per the **v3 column** of
`.claude/rules/stancl-tenancy-v4.md`'s symbol table. `PivotWithCentralResource`
and `Membership`'s `implements` of it are dev-master-only — delete both.

`Support/Compat/Filament*`, `LogsActivityIfInstalled` and
`HasOneTimePasswordsIfInstalled` **stay**. They guard genuinely optional
packages and are unrelated to tenancy version. They also stay in **core** —
the filament slice already established that a satellite owning them would
invert the dependency they exist to prevent.

**A.2 — delete `src/Support/Tenancy/TenancyVersion.php`** (141 LOC). Collapse
all 27 branch sites in 12 files to the v3 branch: `HostConfig`,
`TenancyServiceProvider`, `InitializeTenancyByDomainOrSubdomain` (drop the
gated `parent::__construct()` — v3's parent has none, which is why it was
gated), `PreservingPathTenantResolver`, `NullMiddleware`,
`LogSyncedResourceChangedInForeignDatabase`, `InstallNumerosisCommand`,
`Testing/CleansUpTenancyDatabases`, plus 4 test files.

**A.3 — collapse `TenancyConfigKeys`, but keep its write path.** The version
routing goes; the **read-modify-write of the whole parent array stays**, either
in that class or folded onto `HostConfig`. It fixes the `Arr::set()`
auto-vivification truncation that once destroyed `tenancy.database`
(`.claude/rules/package-host-bootstrap.md`) — a real bug independent of tenancy
version, and the same hazard the satellites hit again with `numerosis.panels`.
Do **not** revert those writes to dotted `Config::set()`.

**A.4 — delete the dev-master-only `HostConfig` normalizations**:
`tenancyIdentificationMiddleware()` and `cacheTenancyStores()`, their two rows
in `docs/host-requirements.md` §2, and whatever `HostRequirementsTest` asserts
about them. Both are documented no-ops on v3.

**A.5 — one PHPStan config again.** Delete `.phpstan/stancl-tenancy-dev-master.stub.php`,
`.phpstan/stancl-tenancy-v3.stub.php`, `phpstan-dev-master.neon.dist`,
`phpstan-baseline-dev-master.neon` (206 entries); fold `phpstan-common.neon`
back into `phpstan.neon.dist`. Then **regenerate** `phpstan-baseline.neon`
rather than editing it — the three deliberate dev-master entries and every
stub-shaped entry are now dead, and a stale entry surfaces as
`ignore.unmatched`, not silence. Quote the new count.

**A.6 — `composer.json`: `"stancl/tenancy": "^3.10"`.** Drop the `stancl` axis
and both install steps from `.github/workflows/run-tests.yml`; keep one. The
`Static analysis` step stops needing to pick a config.

**A.7 — retitle `.claude/rules/stancl-tenancy-v4.md`** as the port map for when
v4 tags. State at the top that the compat layer it describes was built,
measured and deliberately removed, and why. The symbol map, config-key map and
the three "docs are wrong" corrections stay accurate and stay valuable.

**Verify:** suite green, PHPStan 0 outside the regenerated baseline, Pint clean.
Expect the baseline to shrink; quote the number.

---

## B — collapse to a monorepo ✅ DONE (2026-08-31)

**Verified: 0 failed / 7 skipped / 641 passed (6333 assertions) in ~108s;
Pint clean. PHPStan is red, and was red on the pre-B commit too — see
"PHPStan" below before reading anything into it.**

The assertion delta from section A's baseline (623 / 4816) accounts for
itself exactly: +12 tests / +30 assertions are the three satellite suites now
running in the root suite, +6 / +1487 are the new `PackageBoundariesTest`.

Five things worth carrying:

- **The one test that had to change was `SatelliteViewNamespaceTest`**, which
  matched view hints on `/{$package}/` using the Composer package name. PHP
  resolves `__FILE__` through a symlink, so every hint a satellite registers
  now names its real `packages/<dir>` path, never `vendor/nvade/<name>`. The
  data provider takes the directory now.
- **Boundaries collapsed into one root test rather than three per-package
  ones.** `tests/Feature/PackageBoundariesTest.php` carries what the auth-ui
  and filament `BoundaryTest`s and the ui suite's view scan each carried, and
  widens each: `src` *and* `resources` per package, the cashier-key scan
  across all three. Two mechanical findings: `arch()` cannot express "ui may
  not reference core" at all, because `Nvade\NumerosisUi` is prefixed by
  `Nvade\Numerosis` and a namespace matcher is prefix-based — the old
  per-package tests had already worked around it with a `(?!Ui)` regex. And
  the scan must strip comments (`token_get_all`, dropping `T_COMMENT`/
  `T_DOC_COMMENT`) before matching, or a docblock *explaining* the rule trips
  it: `NumerosisUiServiceProvider`'s class comment names the core classes
  that got `layouts/` evicted from that package, and should keep doing so.
  Verified to fail when a real violation is injected, not just to pass.
- **Satellite test namespaces have to be mapped in the *root* `autoload-dev`.**
  Composer loads only the root package's `autoload-dev`, so a path-installed
  package's own is ignored and `packages/*/tests` would not autoload.
- **The satellites' own `repositories` blocks were deleted**; they pointed at
  `../numerosis` and `../numerosis-ui`, which no longer exist relative to
  their new location. Composer ignores a non-root `repositories` anyway, so
  they were already dead — but a split repo would inherit the wrong paths.
- **The three sibling repos were deleted** once absorption was verified two
  ways: every satellite commit (`17ae8eb`, `ef5e0dc`, `87b495d`, `fab3d98`)
  is reachable from this repo's graph, and a `diff -rq` of each sibling
  against its `packages/*` counterpart showed only the deliberate deletions
  (`phpunit.xml.dist`, `pint.json`, `.gitignore`, the folded `BoundaryTest`s)
  and the deliberate edits. Do both checks before deleting a source repo,
  not just the first — `git subtree add` brings history across but says
  nothing about uncommitted files, and `packages/filament` was a plain copy
  of a repo whose entire contents were untracked.

### PHPStan: red before B, less red after, unattributed

44 errors outside the baseline after B — 34 stale/miscounted baseline entries
(`ignore.unmatched`, `ignore.count`) and 10 live ones. **Measured on the
pre-B commit (`0b1e338`) in a throwaway worktree with the sibling repos
symlinked back into place: 63 errors, same families.** So B did not cause it
and in fact reduced it; the baseline documented as clean on 2026-08-31 does
not reproduce in this environment at all.

Every one of the 10 is the documented Larastan host-subclass family
(`App\Models\Central\*` unioned with `Collection`, `Authenticatable` property
access in tests) — the same false positives `.claude/rules/static-analysis.md`
already records, in a different *shape* than the baselined text. The likely
trigger is what state the Testbench package-discovery cache is in when the
analysis app boots, which any `composer install`/`update` regenerates.

**The baseline was deliberately left alone.** Regenerating would bury the
delta rather than explain it, and would bake in one particular discovery-cache
state that the next composer run can flip back. Chasing it is its own pass —
F is the natural place.

### Original brief, kept for reference

`packages/filament` has **0 commits**, `ui` 1, `auth-ui` 2. Cheapest it will
ever be; cost rises with each package. Confirm the parallel session has
finished first.

**B.1 — move.** `git subtree add --prefix=packages/{ui,auth-ui}` to keep their
three commits; plain `git mv` for filament, which has none. Delete each
package's `composer.lock`, `vendor/`, `phpunit.xml.dist`, `pint.json` — root
covers them. Each package **keeps its own `composer.json`**; that is the thing
that gets split.

**B.2 — root wiring.** Replace the three `path` repository entries with one
`{"type":"path","url":"packages/*","options":{"symlink":true}}`. Core's
`require`/`require-dev` on the satellites keeps its shape. Note core
`require-dev`s `alizharb/filament-activity-log` and
`spatie/laravel-one-time-passwords` because the panels' and auth screens' real
coverage lives in core's suite — that stays true and is unaffected.

**B.3 — one suite.** Root `phpunit.xml.dist` gains a testsuite over
`packages/*/tests`. Core's `tests/TestCase.php` remains the only tenancy/DB
harness.

**B.4 — split on tag.** `.github/workflows/split.yml`, one job per package,
`danharrin/monorepo-split-github-action` (Filament's own — apt for this stack).
Alternative if version sync becomes a chore: `symplify/monorepo-builder`.
Nothing publishes until a tag exists, so this can land inert.

**B.5 — boundaries become tests, not directories.** The filesystem stops
enforcing anything in a monorepo, so each package's `BoundaryTest` becomes root
`arch()` rules over `packages/*`:

- `packages/ui` references no `Nvade\Numerosis`, no `tenancy()`, no `route()`
- only `packages/filament` references `Filament\`
- no package references another satellite
- the cashier-key scan covers `src` **and** `packages/*/src` (it has lost
  coverage twice already — 9 files, then 110)

Keep `SatelliteViewNamespaceTest` and `SatelliteRouteContributionTest`. Their
failure modes — a package silently stopping registration; a route registered
outside the central-domain group answering on every tenant subdomain — are
unchanged by layout.

**Verify:** one `composer install` from a clean clone, full suite green,
PHPStan green, `git log --oneline -1 -- packages/ui` still shows the extraction
commit.

---

## C — modules stay in core, cleanly ✅ DONE (2026-08-31)

**Verified: 0 failed / 7 skipped / 646 passed (6228 assertions) in ~112s;
Pint clean; PHPStan 47 errors outside the baseline, byte-identical to the
pre-change tree measured the same way (the environment-dependent Larastan
family section B already records — `ADDED: []`, `REMOVED: []` on a paired
`(path, message)` diff).**

Assertion delta from B's 6333, attributed per test via `--log-junit` rather
than reasoned about: **−139** `PackageBoundariesTest` (removing the
`numerosis-modules` pattern costs one assertion per scanned file — 19 in
auth-ui, 120 in filament), **+28** the five new tests, **+5** `ArchTest`'s
cashier scan and **+1** `DesignLanguageGuardTest`'s heroicon scan, both of
which assert once per file under `src/` and so charge for the one new file.
Net −105.

Four things worth carrying:

- **`configurePackage()` runs before this package's own `mergeConfigFrom()`,
  so `Features::enabled()` is not answerable there.** Gating the three
  `tenants:*-module` commands on `ModuleSystemFeature::available()` would
  have dropped them for every host, feature on or off. They are gated on
  `class_exists(Modules::class)` alone; the feature switch is enforced inside
  each command instead.
- **The guard is one seam, not seven `class_exists()` calls.**
  `ModuleSystemFeature::available()` = feature enabled ∧ registry installed.
  Every entry point asks it: `GuardsModuleBilling::assertModulesAvailable()`,
  `MigrateModules`, `RollbackModules`, `SynchronizeModules`, the new
  `Concerns\ResolvesInstalledModules` (shared by all three commands, which
  had the same five-line lookup copied three ways), and the filament pages.
- **`ModuleDetail` had no `canAccess()` at all** — it relied on `mount()`'s
  404, which fires *after* `isInstalledOnThisNode()` has already asked the
  registry. So it was already reachable with the modules feature off, and
  would have been a 500 rather than a 403 without the package. Fixed.
- **Absence is only testable in a subprocess.** `class_exists()` answers
  `true` for an already-declared class no matter what the autoloader says, so
  `tests/Feature/Features/ModuleRegistryAbsenceTest` shells out to
  `tests/Support/module-registry-absence-probe.php`, which unregisters
  Composer's loader and re-registers a wrapper refusing `InterNACHI\*`. It
  was verified to fail by temporarily making `SynchronizeModules extend
  ModuleConfig` (`Error: Class "InterNACHI\Modular\Support\ModuleConfig" not
  found`), and carries a positive control so it cannot pass by resolving
  nothing.

**Found, not fixed — out of C's scope.**
`DesignLanguageGuardTest::test_no_filament_resource_uses_a_raw_heroicon_string_for_empty_state_icon`
scans core `src/` only, and every Filament resource moved to
`packages/filament` in Phase 7. It guards nothing today; it passes 317 times
against files that cannot contain the pattern. Same vacuous-scan family as
the view-directory scans in `.claude/rules/package-split.md`.
**Picked up after F — the widened version is in the working tree, verified to
fail but not yet Pint'd or full-suite'd. See "Pick up here" at the end.**

### Original brief, kept for reference

**C.1** — `internachi/modular` moves from core's `require` to `require-dev` +
`suggest`. Guard the hard uses of `InterNACHI\Modular\Support\Facades\Modules`
— `Actions/Modules/{PurchaseModule,MigrateModules,RollbackModules,SynchronizeModules}`
and the three `Console/Commands/*TenantModule` — with `class_exists()`, same
pattern as the eight existing guards. `ModuleSystemFeature` is already a
`Feature`, so the off switch exists.

**C.2** — the 12 module Filament UI files **stay in `packages/filament`**.
Delete the "temporarily, until -modules exists" note there and its
`Nvade\NumerosisModules\` prohibition in that package's `BoundaryTest`; there is
no such package. No move, no cycle.

**C.3** — write the degradation test. A `suggest` entry promises the package
degrades cleanly, and a Blade tag rendering as literal text is a silent pass,
not clean degradation (`.claude/rules/testing.md`). Prove the marketplace pages
are unreachable rather than fatal when `internachi/modular` is absent.

---

## D — `packages/onboarding`, the last extraction ✅ DONE (2026-08-31)

**Verified: 0 failed / 7 skipped / 658 passed (6325 assertions) in ~103s;
Pint clean; PHPStan cold-cache 58 errors, **none in any file this section
added** (see the PHPStan note below — the number is not comparable to the 47
quoted for A–C, and that is the finding).**

Assertion delta from C's 6228 itemised exactly, +97 with nothing unexplained:
+8 the browser tests (E), +9 the onboarding package suite, +33 the new
`PackageBoundariesTest` `onboarding` row, +81 that test's cashier scan over
the same package, +3 `SatelliteViewNamespaceTest`, −30 `ArchTest`'s cashier
scan and −6 `DesignLanguageGuardTest`'s heroicon scan (both charge per `src/`
file; 7 left, 1 arrived), −1 `FeaturesTest` (core's feature array is one
shorter).

Moved: 5 Livewire classes, `RegistrationWizardFeature`, `RegistrationState`,
the wizard's 5 views and `components/registration/*`, and the
`spatie/laravel-livewire-wizard` require. `Nvade\Numerosis\Livewire\Tenant\*`
and `Support\State\*` no longer exist.

Five things worth carrying:

- **A satellite config write is *not* always safe in the register phase, and
  this is the counter-example to what `.claude/rules/package-split.md` says.**
  Writing `numerosis.tenancy.registration.steps` from `packageRegistered()`
  auto-vivified `numerosis.tenancy` before core's `mergeConfigFrom()`, whose
  one-level `array_merge()` then kept that partial array wholesale — so
  `implementations`, `provisioning` and `identification` were **discarded**.
  Symptom: `Target [Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant] is
  not instantiable` from a Filament billing page, ~40 failures, none of them
  near the cause. `numerosis.panels` survives the identical treatment only
  because `HostConfig` deep-fills it. Fixed by deferring that one write to a
  `booting()` callback. **The rule is not "writes go in register"; it is
  "writes go in register only where the parent namespace is deep-filled".**
- **The feature-name constant had to move to core first.** Six core sites
  (`welcome`, `features`, `components/footer`, `pages/tenant/⚡mine` ×3, and
  `CompleteRedirectCheckout`) read `RegistrationWizardFeature::NAME`, and a
  *constant* fetch autoloads where a `use` import or `::class` does not.
  `Support\Tenancy\SelfServeRegistration::{FEATURE,SESSION_KEY}` is the core
  anchor; the satellite's `NAME` is defined as that constant. Exactly the
  `ConfiguredProviders::FEATURE` pattern from the auth-ui move.
  `SESSION_KEY` went with it because `registration.wizard_state` was a
  literal duplicated in three files that now sit either side of a package
  boundary.
- **The route keeps its feature gate.** Contributing `/get-started` through
  `Numerosis::addCentralRoutes()` unconditionally made
  `RegistrationWizardDisabledTest` fail — installing the package is not the
  same decision as switching the wizard on.
- **Core's config no longer carries the step list.** Naming the four step
  classes in `config/numerosis.php` would put a package core does not depend
  on into core's own config; the satellite fills the key and a host-published
  value wins.
- **The Livewire aliases were the thing most at risk and survived
  untouched.** `company-info` / `technical-setup` / `plan` /
  `tenant-registration` are explicit in `SHIPPED_STEP_ALIASES` and
  `addComponent()`, so the namespace move did not disturb them. Only
  `Steps\Payment` resolves by FQCN (it must — `payment` collides with
  Cashier's published view), and nothing hardcodes its alias since the tests
  were fixed to resolve through `livewire.finder`.

### PHPStan: the warm result cache was masking errors all session

**PHPStan's `tmpDir` result cache makes the error count depend on which files
you happened to edit.** Warm, this tree reports 47–48; cold (`rm -rf` the
`tmpDir`) it reports **58**. The extra 10 are pre-existing errors in files
nobody touched — six `Parameter #1 $view of function view expects
view-string` (the documented Larastan false positive: core's own provider is
never registered in the analysis app, so no `numerosis::` view resolves) and
three `Cannot cast … to string` in the `tenants:*-module` commands, on a line
`git diff` shows is unchanged.

So the "PHPStan identical to before, `ADDED: []`" comparisons quoted for
sections C and E were **weaker evidence than they read**: they compared two
warm runs, and a warm run only re-analyses what changed. The conclusions
still hold (nothing this session added reports an error, verified cold), but
**the honest comparison is cold-vs-cold**, and `static-analysis.md`'s
existing warning that "green doesn't survive a `composer install`" has this
second mechanism alongside it.

### Original brief, kept for reference

Contents: `Livewire/Tenant/Registration/*` (5 files), `RegistrationWizardFeature`,
`Support/State/RegistrationState`, `resources/views/livewire/tenant` +
`components/registration`, and the `spatie/laravel-livewire-wizard` require.

**D.1 — derive the file list mechanically; do not read it off a table.** The map
has been wrong in all three extractions (ui's `layouts`/`partials`; auth-ui's
`TurnstileFeature`, both `one_time_passwords` migrations, `layouts/auth*`;
filament's compat shims and `registerFilamentTheme()`). Run all four checks and
let them produce the list:

```bash
grep -rnE 'Nvade\\Numerosis|tenancy\(|route\(' <candidate-dir>   # symbol reach-back
grep -rn 'x-numerosis::<component>' resources/ packages/          # view reach-back
grep -rn '<MovedClass>::' src/ resources/ tests/ config/          # const/static — autoloads, `use` does not
grep -rn '<MovedNamespace>\\\\' src/ packages/                    # escaped form inside strings
```

The last two are the ones that bite. A class-constant fetch autoloads where a
bare `use` does not (cost: three core views gated on `SocialLoginFeature::NAME`).
Filament's discovery calls take namespaces as double-backslashed **strings**, so
a namespace rewrite misses them and the panel registers with zero resources —
symptom is an unrelated `RouteNotFoundException`.

**D.2 — go through the seams that already exist**: `Numerosis::addCentralRoutes()`,
`Features::register()`, and `numerosis.panels.admin.tenant_registration_component`
(which names the wizard's Livewire **alias**, not a class — neither layer may
name the other's class).

**D.3 — a satellite must register into a world where core's config is absent,
and do nothing.** Sentinel on a key **only core writes** (`numerosis.features`),
never on one the satellite itself writes — `Arr::set()` auto-vivifies, so "the
namespace exists" is not evidence core registered. The rule is *phase*, not
check: write config in the register phase, read core's config only from
`booting()`. Full write-up in `.claude/rules/package-split.md`.

---

## E — browser tests ✅ DONE (2026-08-31)

**Verified: 0 failed / 7 skipped / 650 passed (6236 assertions) in ~111s
(`vendor/bin/pest` runs every testsuite, so that includes the 4 new browser
tests); `--testsuite=Browser` alone is 4 passed / 8 assertions in ~5s; Pint
clean; PHPStan 47 outside the baseline, `ADDED: []` / `REMOVED: []` against the
pre-C tree.** Assertion delta 6228 → 6236 is exactly the 4 browser tests.

`pestphp/pest-plugin-browser ^4.3` + `playwright` (npm) + Chromium.

**The first open item is closed: `PreservingPathTenantResolver` is now
test-verified, not source-derived.** `tests/Browser/PathModeTest` boots the app
in path mode and drives three real HTTP requests. With this package's resolver
the authenticated tenant panel renders (`Dashboard`, plus the tenant's own name
— a value only reachable through `Filament::getTenant()`); with stancl's
`PathTenantResolver` rebound in its place the same request is a 500,
`Filament\Panel::getTenantBillingUrl(): Argument #1 ($tenant) must be of type
Illuminate\Database\Eloquent\Model, null given`. That negative control is the
test; the positive one alone would prove nothing.

Five things worth carrying:

- **The plugin serves Laravel *in-process*** — an amphp socket in front of the
  same booted kernel the test holds — so `RefreshDatabase`'s transaction, the
  `CloneTenantSchema` tenant databases and `actingAs()` all carry into browser
  requests unchanged. This is why the browser suite needed no harness of its
  own. It also means **`PHP_SAPI` is still `cli`**, so
  `app()->runningInConsole()` is `true` inside a browser request and
  `shouldRegisterPanel()`'s console exemption still applies — the
  "central route wins over the `{tenant}` wildcard" question in
  `.claude/rules/filament-tenancy.md` is **still out of reach**, and E's
  premise was wrong about that. Path mode is unaffected (it registers no
  wildcard), which is why it is what got covered.
- **Installing the plugin makes Playwright a prerequisite for the *whole*
  suite.** `Pest\Browser\Plugin::terminate()` starts the Playwright server on
  every Pest run regardless of which tests ran, so without `npm install` +
  `npx playwright install chromium` even `--filter=ModuleFeatureSwitchesTest`
  aborts with no test output and a non-zero exit. A `beforeEach()` skip guard
  was written and removed: the plugin aborts first, so it was dead code that
  read as protection. CI installs both; `tests/Browser/README.md` documents it.
- **A real bug fell out, which is the point of the exercise.**
  `Tenant::factory()` set `'data' => ['name' => $company]`, and VirtualColumn
  folds every *non-custom attribute* into `data` — so `data` was itself
  treated as one and the written column came out
  `{"user_id":…,"tenancy_db_name":…}` with no `name` at all. **Every tenant
  the suite has ever created had a null name.** Nothing failed, because the
  only thing that requires one is Filament's own tenant layout
  (`FilamentManager::getTenantName(): string`), which no test rendered until
  this one. Fixed to a top-level `'name' => $company`; zero other assertions
  moved.
- **`Playwright::setHost()` is the only lever for a multi-domain app.** The
  server always binds `127.0.0.1` and `LaravelHttpServer::rewrite()` discards
  the host of an absolute URL, so `visit('http://acme.example/x')` does **not**
  reach `acme.example`. `setHost()` rewrites the inbound `Host` header per
  request; it is global static state, set explicitly per test.
- **The plugin orphans its `playwright run-server` node process**, and an
  orphan holds the inherited stdout pipe open — so `pest … | tail` looks like
  a hang long after PHP exited. Redirect to a file; `pkill -f "playwright
  run-server"` after. Two apparent multi-minute hangs in this session were
  this and nothing else.

**Not done: the rest of the smoke path** (wizard in each identification mode →
module marketplace purchase → admin panel shows the tenant). The path-mode
round trip was the item blocking D; the subdomain and custom-domain legs need
`Playwright::setHost()` juggling per request and a Stripe checkout stub, and
neither is a prerequisite for D. "Proof the split composes" is seeded by
`tests/Browser/SmokeTest` (central login page served over real HTTP) rather
than fully covered.

### Original brief, kept for reference

Two open items are the same gap, and neither closes any other way.

- **Path mode's HTTP round trip.** `shouldRegisterPanel()`'s
  `runningInConsole()` exemption means a Pest-dispatched request always sees the
  tenant panel registered and always resolves the wildcard, regardless of config
  (`.claude/rules/filament-tenancy.md`). `PreservingPathTenantResolver`'s
  necessity is source-derived, not test-verified.
- **Proof the split composes.** Per-package suites cannot show it.

Pest 4 is installed; add `pestphp/pest-plugin-browser`. One smoke path against a
real server: central login → registration wizard (each identification mode) →
tenant panel loads → module marketplace purchase → admin panel shows the new
tenant.

**Do this before D**, not after — right now a broken split composes silently.

---

## F — docs and rules ✅ DONE (2026-08-31)

**Verified: 0 failed / 7 skipped / 660 passed (6327 assertions) in ~119s;
Pint clean; PHPStan **cold-cache `[OK] No errors`** — 0 outside the 200-entry
baseline, the first clean cold run since B. Quote it with the date: section
D's own note records that this number oscillates with the Testbench
package-discovery cache, so a later run reporting 47–58 is not necessarily a
regression.**

Assertion delta **−4**, fully attributed and the only one: `HostRequirementsTest`
called `assertCount(4, …)` on each table's *header* row before skipping it, and
the header is now skipped one line earlier — two headers × two test methods
that both call `documentedRows()`.

Six things worth carrying:

- **The pre-F tree measured 660 / 6331, not the 658 / 6325 section D recorded.**
  The difference is `tests/Feature/Models/Central/SubscriptionOwnerTest` (2
  tests, 6 assertions), written after D's write-up and still untracked.
  **Attribute a delta against a tree you measured, not against a number in a
  plan** — the plan number is only as fresh as the paragraph it sits in.
- **A doc-parsing test assumed every markdown table in the file was its
  table.** `HostRequirementsTest::documentedRows()` took every `| ` line as a
  §1/§2 row and asserted 4 cells, so adding §0's 3-column per-package map failed
  it with `Row 'Package' does not have a 'Checked by' cell` — an error message
  about the wrong file. A table now opts *in* by its own header ending in
  `Checked by`, and the separator-line check had to widen from `'| '` to `'|'`
  or `|---|` would reset the state one line after the header. Same family as
  the vacuous directory scans: the parser was coupled to "the whole document"
  rather than to what it guards.
- **`Numerosis::routes()`'s docblock still said "there is no hook to append to
  the defaults", twelve lines above `addCentralRoutes()`.** That sentence is
  the one `package-boundaries.md` was originally built on. A retired constraint
  can outlive itself in a docblock and get re-read as current — when a seam
  lands, grep for the prose that said it could not.
- **`package-boundaries.md` is now the seam map**, not the argument against
  the split. Everything it asked for exists; its still-live findings (the
  `make($feature)` boot failure vs the silent `is_a()` name-map loss, the 11
  lazy core→`Filament\` edges, the resolved `PurchasesModules` cycle, module UI
  in two directories, 85 migrations staying in core) were kept and re-verified
  against the tree rather than copied.
- **`filament-tenancy.md`'s own suggested fix was wrong and is corrected.** It
  said the "central route wins over the `{tenant}` wildcard" gap needed a Pest
  browser test; section E established the plugin serves Laravel in-process, so
  `PHP_SAPI` stays `cli` and `runningInConsole()` is still true. A **rule file's
  "Suggested better approach" can go stale exactly like its facts** — this one
  would have sent the next session to build something that cannot work.
- **INDEX.md's D11 "this copy is canonical, saas-m and thin-app carry
  byte-identical copies" is retired.** saas-m is archived and thin-app is on a
  different toolchain, so five of these files would actively mislead there.
  Copy individual rules deliberately; never sync the directory.

### Original brief, kept for reference

**F.1** — `docs/host-requirements.md` and `DEPENDENCIES.md` rewritten
per-package. Drop the two dev-master rows (A.4). No dev-master opt-in block is
needed any more.

**F.2** — `.claude/rules/` re-sync. `INDEX.md` self-reports out of sync since
2026-08-29, and rules load into **every** session, so a stale rule actively
misleads in a way a stale plan does not.

- files describing moved code (`module-marketplace.md`, `filament-tenancy.md`,
  `tenant-registration-wizard.md`, most of `auth-login.md`) get a header naming
  the package that holds it — one repo now, so they need not move
- `package-boundaries.md` is largely obsolete: the seams it argues for exist.
  Rewrite as "what the seams are and how to contribute through them", or delete
- correct the `activity_log` migration count (9: 4 central, 5 tenant, not
  symmetrical) in `package-boundaries.md` and `optional-dependencies.md`
- `stancl-tenancy-v4.md` per A.7
- INDEX.md's "canonical copy, saas-m and thin-app carry byte-identical copies"
  claim needs re-deciding — those two hosts are not on this layout

---

## Order

```
A  delete dual-version layer   ── ✅ DONE 2026-08-31
B  collapse to monorepo        ── ✅ DONE 2026-08-31
C  modules stay in core        ── ✅ DONE 2026-08-31
E  browser tests               ── ✅ DONE 2026-08-31 (path-mode leg; see E)
D  packages/onboarding         ── ✅ DONE 2026-08-31
F  docs + rules                ── ✅ DONE 2026-08-31
```

**The plan is complete.** What is deliberately *not* done is under "Pick up
here" below.

A before B is a preference (fewer moving parts per step), not a dependency.
**E before D is a real dependency**: without it, a broken D is silent.

---

## Pick up here (session ended 2026-08-31)

### Where the tree is

A–F are committed on `package-scope-reduction`, five commits on top of
`b58d19d`:

```
1fb64d9 docs: rewrite the host and dependency docs per package, resync the rules   (F)
97b1e0a feat: extract nvade/numerosis-onboarding, the last package of the split    (D)
e6a5164 test: pin subscriptions.subscribable_id to the owner's primary key
80d3ec9 test: add browser tests, and fix the tenant factory bug they found         (E)
64c47a8 feat: keep the module system in core behind one class_exists seam          (C)
```

C, D, E and F had been done interleaved in the working tree, so three files
(`composer.json`, `phpunit.xml.dist`, `tests/Feature/PackageBoundariesTest.php`)
were split by hand into per-section states. **Only `1fb64d9` is verified**
(0 failed / 7 skipped / 660 passed, 6327 assertions; Pint clean; PHPStan cold
`[OK]`); the four below it are thematic groupings, not independently green
checkpoints. Don't cherry-pick one of them somewhere and assume it stands
alone.

`.agents/` and `.codex/` are untracked and belong to other tooling — not this
work, deliberately not committed, not in `.gitignore` either.

### `DesignLanguageGuardTest` widening — done and verified

`tests/Feature/View/DesignLanguageGuardTest::test_no_filament_resource_uses_a_raw_heroicon_string_for_empty_state_icon`
now scans `[src, packages/*/src]` instead of `src` alone, and reports a
repo-relative path rather than `getRelativePathname()` (ambiguous across two
roots). This closed the vacuous-scan defect section C found: every
`emptyStateIcon(` call site is in `packages/filament`, **zero** under `src/`,
so the guard was asserting ~311 times against files that cannot match.

Verified it **fails** on an injected raw `'heroicon-o-key'` in
`packages/filament/src/Admin/Resources/Roles/RoleResource.php` (1 failed, 339
assertions, correct path in the message), then reverted the injection. Pint
clean. Full suite: **0 failed / 7 skipped / 660 passed, 6458 assertions**
(from 6327 — the expected +131, one per file under `packages/*/src`). Needed
a `docker compose up -d` first; MySQL wasn't running.

### Still open, in priority order

1. **The rest of E's smoke path** — subdomain and custom-domain wizard legs, a
   module marketplace purchase, the admin panel showing the new tenant.
   Neither non-path leg is cheap: both need `Playwright::setHost()` juggling
   per request (the server always binds `127.0.0.1` and
   `LaravelHttpServer::rewrite()` discards an absolute URL's host), and the
   purchase needs a Stripe checkout stub. Section E for what the plugin can
   and cannot reach — in particular that it does **not** escape
   `runningInConsole()`, so subdomain mode's "central route wins over the
   `{tenant}` wildcard" question stays out of reach of any test here.
2. **PHPStan baseline environment sensitivity** — the cold count has been
   observed at 0, 47, 48 and 58 on unchanged trees. Prime suspect is the
   Testbench package-discovery cache, regenerated by every composer run.
   Explained in section B and `.claude/rules/static-analysis.md`; the baseline
   was **deliberately left un-regenerated**, because regenerating buries the
   delta and pins one particular cache state. Don't "fix" it by regenerating.
3. **`.claude/rules/package-boundaries.md`'s own suggestion** — the seams have
   writers (`addCentralRoutes`, `Features::register`, …) but no readers except
   `Features::registered()`, so "which package added this route" is answerable
   only by grep. Worth adding alongside a sixth package, not before one.

## Done, not revisited

Contribution seams (`addCentralRoutes`/`addTenantRoutes`, `Features::register`,
`addTenantMigrationPath`/`addTenantSeeder`/`addCentralSeeder`/`addPermissionContext`),
config-driven wizard steps, all three identification modes, the panel seams
(`numerosis.panels.*`), the `jobs`-table and migration-basename repairs, and the
ui / auth-ui / filament extractions. Mechanism for all of it is in
`.claude/rules/`.

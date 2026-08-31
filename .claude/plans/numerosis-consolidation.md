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

Net: 6 packages → 5 units in 1 repo; 4 CI jobs → 2.

## Toolchain

No Sail here (`.claude/rules/testing.md`).

```bash
docker compose ps                                    # numerosis-mysql-1 healthy
vendor/bin/pint                                      # FIRST, before pest
php -d memory_limit=1G vendor/bin/pest --compact
vendor/bin/pest --compact --filter=SomeTest
printf 'includes:\n  - %s/phpstan.neon.dist\nparameters:\n  tmpDir: /tmp/phpstan-audit\n' "$PWD" > /tmp/phpstan-audit.neon
php -d memory_limit=2G vendor/bin/phpstan analyse -c /tmp/phpstan-audit.neon --no-progress
```

Baseline to hold, as of section A: **0 failed / 7 skipped / 623 passed
(4816 assertions)**, PHPStan 0 outside a 200-entry baseline. **Diff the
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

## B — collapse to a monorepo

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

## C — modules stay in core, cleanly

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

## D — `packages/onboarding`, the last extraction

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

## E — browser tests: the one missing capability

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

## F — docs and rules

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
B  collapse to monorepo        ── cheapest now (filament has 0 commits)
C  modules stay in core        ── independent of A/B, may interleave
E  browser tests               ── before D
D  packages/onboarding         ── last extraction
F  docs + rules                ── after D
```

A before B is a preference (fewer moving parts per step), not a dependency.
**E before D is a real dependency**: without it, a broken D is silent.

## Done, not revisited

Contribution seams (`addCentralRoutes`/`addTenantRoutes`, `Features::register`,
`addTenantMigrationPath`/`addTenantSeeder`/`addCentralSeeder`/`addPermissionContext`),
config-driven wizard steps, all three identification modes, the panel seams
(`numerosis.panels.*`), the `jobs`-table and migration-basename repairs, and the
ui / auth-ui / filament extractions. Mechanism for all of it is in
`.claude/rules/`.

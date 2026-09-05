# Confusion cleanup — 2026-09-01

**Status: ✅ Complete.** Steps 1–7 landed and were committed 2026-09-01
(`numerosis@f3e0297` + `numerosis-thin-app@bddeff1`). The loose ends below were
closed the same day, uncommitted at the time of writing:

- **`config/numerosis.php` is split** — `config/numerosis/<key>.php`, fifteen
  partials the root file `array_merge`s, assembled output verified byte-identical
  to the old single file. The risk this plan named was real and is handled: the
  root file is now **never published** (`require __DIR__` would resolve against
  the host), so `hasConfigFile('numerosis')` is gone, `packageRegistered()`
  merges by hand, and `vendor:publish --tag=numerosis-config` writes the short
  override stub in `config/stubs/numerosis.php`. Mechanism in
  `.claude/rules/package-boundaries.md`.
- **The host's copy is 55 lines**, down from 925: `schema_version` plus
  `routes.home_view`, everything else deep-filled.
- **`docs/{architecture,features,extending,host-requirements}.md` and `README.md`
  are current** — six packages, `AccountPagesFeature` as a satellite, no
  `MarketingPagesFeature`, the real `src/` map, the config split.
- **The 32 contracts stay, decided rather than deferred.** 22 are the
  `numerosis.{billing,tenancy}.implementations` swap points; the other 10 are
  role interfaces used as types. Reasoning in `docs/extending.md`.
- **Baseline is 202 entries, not 203**: `configDirectories` in
  `phpstan.neon.dist` now names `config` and `config/numerosis`, so the 23
  baselined `env()`-outside-config errors are gone rather than re-baselined
  under new paths.

**Step 7's second half landed 2026-09-01 (uncommitted).** `Support\Numerosis`
is split by audience: `Support\Contributions` (tenant columns, central/tenant
route callbacks, tenant migration paths, seeders, permission contexts) and
`Support\Assets` (publish map + asset tags) join `Support\ModelResolver`.
649 → 606 lines; every moved method stays on `Numerosis` as a delegate, so no
call site, no host config and no doc seam changed. Verified: 669 passed /
7 skipped — the same counts as before the split — Pint clean, PHPStan
`[OK] No errors` outside the 202-entry baseline.

Two things the split decided rather than deferred:

- **The two `reset*ForTesting()` names stay two methods**, mapping to
  `Contributions::flushRouteContributions()` /
  `::flushMigrationAndSeederContributions()`. Collapsing them to one `flush()`
  would silently widen what each caller clears, and
  `PackageContributionSeamsTest` calls them separately.
- **`$tenantColumns` still has no reset**, in either class. Its only writer is
  `Models\Central\Tenant`'s own declaration, so there is nothing per-test to
  undo — the pre-split behaviour, kept deliberately rather than by omission.

The one naming trap the split introduces:
`Contributions::tenantMigrationPaths()` returns contributions **only**, while
`Numerosis::tenantMigrationPaths()` returns those plus the package's own.
`HostConfig` wants the second. Both docblocks say so.

Still open: nothing from this plan except the `.agents/skills/` copy below.

Driven by a code-level audit of why the package "felt extremely confusing".
The audit's findings and the seven-step plan are reproduced below; what
actually landed is marked per step.

## The root finding

Core was a reusable framework **and** one specific SaaS product at the same
time — it shipped 54 Blade views including a marketing site, and 7 Livewire
screens — so "does this belong in core?" had no answer. Everything else
(three meanings of "Feature", three `RoleResource` classes, a `Shared/`
directory called `App/`) was downstream of that.

## What landed

### 1. Product split out of the framework ✅ verified

- **Marketing pages → the host app.** `welcome`, `about`, `terms`, `privacy`,
  `features` views moved to `numerosis-thin-app/resources/views/`; the host
  registers the four routes from `AppServiceProvider::register()` via
  `Numerosis::addCentralRoutes()`. `MarketingPagesFeature` deleted.
- Core keeps `home` — it must, since OAuth redirects, checkout error paths and
  the tenant panel all fall back to it — rendering a placeholder
  `numerosis::home`. **New seam: `numerosis.routes.home_view`.** A host cannot
  replace `home` by registering a second route of that name (core declares
  its own first, so it wins the path match); it points this key at its own
  view instead. The host sets it to `'welcome'`.
- Core's header/footer now link to marketing routes through `Route::has()`
  instead of a feature flag.
- **Account UI → new package `nvade/numerosis-account`** (`packages/account`,
  `Nvade\NumerosisAccount\`). Holds the 4 settings Livewire components, the
  workspace list, invoice download and billing portal routes, and
  `AccountPagesFeature`.
  - Core owns the feature *name* as `Support\Ui\AccountPages::FEATURE` —
    same trick as `SelfServeRegistration::FEATURE`, because six core and
    satellite call sites gate a post-login redirect on it and a constant fetch
    autoloads.
  - Views join the shared `numerosis::` namespace (moved, not copied).
    Single-file Livewire pages needed their own prefix, `account-pages::`,
    because `livewire.component_namespaces` maps a prefix to exactly one
    directory.
- Deleted `resources/views/layouts/app/sidebar.blade.php` — dead (nothing
  referenced it) and it linked to `tenants.index`, a route that exists nowhere.
- **Trap found and fixed:** `tests/TestCase.php` replaced the whole
  `livewire.component_namespaces` array in `defineEnvironment()`, which runs
  after every provider's `register()` — silently dropping the satellite's key.
  It now sets one key at a time. Same hazard family as the `Arr::set()` one in
  `.claude/rules/package-host-bootstrap.md`.

### 2. "Feature" reclaimed ✅ verified

`Models\Central\Feature` → **`PlanFeature`** (a plan's selling point, e.g.
"Priority support"), with `FeaturePolicy` → `PlanFeaturePolicy`,
`FeatureFactory` → `PlanFeatureFactory`, and the Filament resource tree
`Admin/Resources/Central/Features/` → `PlanFeatures/` (resource, 3 pages, form,
table, relation manager, and its test all renamed).

The word now means one thing in PHP: a capability toggle. The **table is still
`features`** — deliberate, documented on the model: renaming it means rewriting
five migrations and the `payment_plan_features` FK for a name only a schema
dump shows.

### 3. Role/Permission duplication ✅ verified, partially by design

- **Deleted `Models\Central\Role` and `Models\Central\Permission`** — 7-line
  empty subclasses with no connection override, existing only so two Filament
  resources could import a differently-named class, while spatie resolved
  `Models\Role` for the same table.
- **`packages/filament/src/App/` → `Shared/`.** It sat beside `Admin/` and
  `TenantAdmin/` and read as a third panel; it is the shared base for the
  tenant panel's Team cluster, and `App\` means something else in every host.
- **Not merged, on purpose:** the central panel's `RoleResource` (87 lines)
  and `Shared`'s (275 lines) stay separate. They answer different questions —
  staff auditing roles vs. a tenant owner composing permission sets — and
  merging means giving one audience the other's UI. Now documented in the
  `Shared` class docblock so the duplication reads as intentional.

### 4. One home for default implementations ✅ verified

`Support\Defaults\*` (5 classes) moved into `Services\` beside the others that
already lived there: `Services\{Invitations,Modules,Auth,Notifications,Tenancy}`.
`Support\Defaults` is gone. Also renamed `FindLoginCandidate` →
`ResolveLoginCandidate`, the only contract/implementation pair whose names did
not share a stem.

**Decided 2026-09-01, and the count was wrong:** all 32 contracts stay. 22 —
not 9 — are named in `numerosis.{billing,tenancy}.implementations`, which two
providers loop and `bind()`; the remaining 10 are role interfaces used as
types (`is_a()` checks on a host's model, the feature registry's 14
implementers, Filament module-plugin discovery). "One implementation" is the
expected state for a framework whose point is that the host supplies the
second. Reasoning in `docs/extending.md`.

### 5. One way to read the current user ✅ verified

- **Deleted `Actions\Queries\IsUserAuthenticated`** — wrapped `Auth::check()`
  with no added type information. Call sites use `->check()` on the guard.
- **Kept `GetAuthenticatedUser`** and converted the 9 remaining raw
  `Auth::user()` / `auth()->user()` sites to it. It earns its keep: it returns
  `?Nvade\Numerosis\Models\User` where the facade returns `Authenticatable`,
  which matters with two user models on two guards and keeps level-9 analysis
  honest without a `@var` at every call site.
- **New arch test** pins it: `tests/Feature/ArchTest.php` fails if anything
  under `src/` or `packages/*/src` reads the current user through the facade.
  One exemption, `Support\Numerosis::exceptions()`, whose Sentry context
  callback runs during reporting when the container may be mid-teardown.
- **Not done:** collapsing the four tenancy-context idioms (`tenant()`,
  `tenancy()->initialized`, `Context::`, `isCentralDomain`). On reading them
  they answer four different questions; forcing one would be wrong.

### 6. Enums grouped by domain ✅ verified

`Enums\TenantProvisionStatus` → `Enums\Tenancy\`, `Enums\{BillingCycle,ModuleBillingMode}`
→ `Enums\Billing\`. Every enum now sits under a domain namespace;
`Enums\Tenant\DisplayStatus` stays put (it is a thing *inside* a tenant, mirroring
`Models\Tenant\`), while `Enums\Tenancy\*` is tenancy mechanics.

### 7. God-class and config split — extraction ✅ verified, config split not started

**Verified 2026-09-01** (669 passed, 7 skipped):

- New `src/Support/ModelResolver.php` holds model resolution, the
  model↔factory name mapping and the memoization cache.
  `Numerosis::{model,factoryNameFor,modelNameFor,resetModelCache}()` remain as
  thin delegates — they are the idiom every call site and every host's
  `config/numerosis.php` already uses, so the implementation moved and ~200
  call sites did not. `Numerosis.php` is 724 → 649 lines.

Done: the suite was run and is green, so the extraction is confirmed clean.

**Config split ✅ done 2026-09-01.** The hazard named here was the right one and
is what shaped the fix: the root file `require`s its section files, so it can
never be published. `hasConfigFile('numerosis')` — which would have registered
exactly that file for publishing — is gone; `packageRegistered()` calls
`mergeConfigFrom()` itself (same phase package-tools used) and `packageBooted()`
publishes `config/stubs/numerosis.php` under the unchanged `numerosis-config`
tag. `HostConfig::numerosisConfig()` and `verifyConfigSchemaVersion()` both
`require` the package path, so both were unaffected; a host that already
published the full file still works, since a complete file needs no backfill.
Assembled output was diffed against the pre-split file and is identical.

**✅ Done 2026-09-01, uncommitted.** The rest of `Numerosis`'s surface is
split: `Support\Contributions` took the contribution seams and both test-only
resets, `Support\Assets` took the publish map and the asset tags. What is left
on `Numerosis` is one audience — application bootstrap (`configure`, `routes`,
`middleware`, `broadcasting`, `exceptions`, the three `registerXUsing()`
overrides) — plus a delegate for every moved method. Details and the two
decisions it forced are at the top of this file.

## Also fixed along the way

- `boost.json` had `"sail": true` and listed uninstalled packages, inherited
  from the `saas-m` copy commit. That is what generated 575 of `CLAUDE.md`'s
  602 lines describing a *different application* — Sail-prefixed commands in a
  repo with no Sail, plus Vue/Reverb/Telescope/Debugbar. Both corrected.
- `.claude/agents/` (10, tracked) and `.codex/agents/` (10, untracked, **not
  recoverable**) deleted, settling the contradiction with the no-sub-agents
  rule. Recorded in `CLAUDE.md` and `.claude/rules/subagents.md`.
- New `docs/{architecture,features,extending}.md`; `README.md` rewritten;
  `.claude/plans/README.md` added (plans are historical, not authoritative).
- `.claude/rules/package-host-bootstrap.md` corrected: panel providers live in
  `packages/filament/src/Providers/`, and core's method is
  `registerHostPanelProviders()` — the file named a path and a method that no
  longer exist.
- The host's `resources/views/welcome.blade.php` (stock Laravel scaffold,
  never routed) was overwritten by the package's real marketing homepage. The
  original is in the host's git history if wanted.

## Loose ends — all closed 2026-09-01

- ~~**Nothing is committed.**~~ Committed 2026-09-01: `numerosis@f3e0297`
  (205 paths), `numerosis-thin-app@bddeff1` (11). Neither is pushed.
- ~~**`vendor/bin/pint` and `composer analyse` have not been run.**~~ Both run
  and green. Analysis turned out not to cover `packages/` at all — `paths` in
  `phpstan.neon.dist` still listed only core's directories, so every satellite
  had been unanalysed since the split. Adding it found a real defect
  (`DeleteAccount`'s unnamespaced view reference) plus the deprecated Filament
  table/form APIs, all fixed in the same commit.
- ~~`docs/architecture.md` and `docs/features.md` predate steps 1–7.~~ Rewritten,
  along with `docs/extending.md`, `docs/host-requirements.md` §0 and `README.md`.
- ~~The host still carries a full 922-line copy of `config/numerosis.php`.~~ 55
  lines now, verified against a real boot rather than Testbench.
- ~~`.agents/skills/` in the package is an untracked third copy of the skills
  already in `.claude/skills/`.~~ **Deleted 2026-09-01.** Checked first,
  because the directory listings differ and that reads like unique content:
  five `source-command-{catchup,debug,pint,review,ship}` directories existed
  only there, and `codebase-learnings/SKILL.md` differed. Neither was worth
  keeping. The five are generated wrappers whose bodies are byte-identical to
  the tracked `.claude/commands/*.md` they name, and the `codebase-learnings`
  difference is a mechanical Codex rewrite — `.claude/rules/` → `.Codex/rules/`,
  `CLAUDE.md` → `AGENTS.md` — pointing at a `.Codex/` directory that does not
  exist in this repo. So the merge was empty and the delete lost nothing.
  Untracked, so there is no git recovery if that turns out wrong.

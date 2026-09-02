# Splitting Numerosis Into Packages

Facts learned actually doing it, not from the plan
(`.claude/plans/memoized-tinkering-meadow.md`, Phases 6–8). The agreed
six-package map and the D1–D4 decisions live there; this file is what the
first real extraction taught, and every bullet holds for the four still to
come.

> **Layout note, 2026-08-31.** The three extracted packages are no longer
> sibling repos: `nvade/numerosis-{ui,auth-ui,filament}` live in this repo
> under `packages/*`, path-installed from one `{"type":"path","url":"packages/*"}`
> entry and published as read-only splits on tag
> (`.github/workflows/split.yml`). **Every mechanism below is unchanged** —
> the shared view namespace, the register-vs-`booting()` phase rule, the
> constant-vs-`use` autoload asymmetry, the escaped-namespace-in-a-string
> trap — because none of them depended on the packages being separate repos.
> What changed is where the files sit and what enforces the boundaries; see
> the monorepo bullets at the end of this file.

- **Two packages can serve one view namespace, and that is what makes a split
  cost zero view edits.** `Illuminate\View\FileViewFinder::addNamespace()`
  *appends* to a namespace's path list (`array_merge($this->hints[$ns], $hints)`)
  rather than replacing it, and `ServiceProvider::loadViewsFrom()` goes
  straight through it — so `nvade/numerosis-ui` registers
  `->hasViews('numerosis')`, the same namespace core uses, and all ~139
  `<x-numerosis::ui.*>` / `numerosis::partials.*` references keep resolving
  untouched. Blade view names are strings nothing type-checks, so not moving
  them is worth more than a per-package namespace.

  Two consequences to hold onto: paths are searched **in registration order**,
  so a same-named file in two packages resolves to whichever registered first,
  silently — files must be *moved*, never copied. And a host's own
  `resources/views/vendor/numerosis` is still prepended ahead of both, so
  publishing keeps working unchanged.

- **A directory-scanning test does not fail when the directory it guards moves
  to another package — it just runs fewer times.** `DesignLanguageGuardTest`
  and `RegisteredComponentTagsTest` both hardcoded
  `dirname(__DIR__, 3).'/resources/views'`. After the ui extraction the suite
  still reported **615 passed**, with the assertion count dropping 5561 → 5325.
  That number is the only tell; there is no red. Both now enumerate every path
  under the `numerosis` namespace via `FileViewFinder::getHints()`, which keeps
  them correct as further packages split out with no edit at all. Same
  vacuous-pass family as `.ai/rules/testing.md`'s `assertDontSee` and empty
  `ArchTest` — **when a split lands, diff the assertion count, not just the
  pass count.**

  `ViewFinderInterface` declares no `getHints()`; `FileViewFinder` is what is
  actually bound, so narrow with `instanceof` or PHPStan reports
  `method.notFound`.

  **A third instance, found and fixed 2026-08-31** (outside the change that
  surfaced it — section C of `.claude/plans/numerosis-consolidation.md`):
  `DesignLanguageGuardTest::test_no_filament_resource_uses_a_raw_heroicon_string_for_empty_state_icon`
  used to scan core `src/` for `emptyStateIcon('heroicon-…')`, and every Filament
  resource moved to `packages/filament` in Phase 7. It asserted 317 times
  against files that cannot contain the pattern. The tell is the one this
  file already names — its assertion count tracks the size of `src/`, so it
  went **up** by one when an unrelated `src/Concerns/` trait was added, which
  is the opposite of what a guard over Filament resources should do. Now scans
  `src` **and** `packages/*/src`, the same widening `PackageBoundariesTest`
  already applied to the cashier-key scan; verified to fail on an injected
  `'heroicon-o-key'` in a `packages/filament` resource before landing.

- **"Which files belong in the leaf package" is answered by grep, not by the
  plan.** D4 assigned `layouts/` and `partials/` to `numerosis-ui`. They were
  moved, and moved straight back: they name
  `Nvade\Numerosis\Support\{Numerosis,Features,Routes\RouteNames}`,
  `Models\Central\CentralUser`, `Actions\Queries\GetAuthenticatedUser`, and
  call `tenancy()` — a leaf package shipping them would depend on core, which
  is the exact property it exists to not have. `partials/script-config.blade.php`
  is the clearest case: it reads `tenancy()->initialized`, so it is product
  wiring, not a shared primitive.

  Before moving any directory, run the check that decides it:

  ```bash
  grep -rnE 'Nvade\\Numerosis|tenancy\(|route\(' <dir>
  ```

  `numerosis-ui` pins this as a test of its own
  (`test_no_view_here_references_the_core_package_or_tenancy`) rather than
  leaving it to reviewers, and the next leaf-ish package should copy that test
  before it copies anything else.

- **A moved view can still reach back into core by *view name*, which no grep
  for classes catches.** `components/ui/card.blade.php` renders
  `<x-numerosis::placeholder-pattern>`, which lived in core. It would have kept
  working (the namespace is shared and core is always installed) while
  quietly making the leaf depend on core's view set. That file moved too.
  Check moved views for `x-numerosis::` references to things that stayed
  behind, not only for PHP symbols.

- **The Testbench harness is the host, so it overrides package registration —
  including registration the split just moved.** `tests/TestCase.php` set
  `livewire.component_namespaces` as a **whole array**, pinning both `layouts`
  and `pages` at core's paths. `getEnvironmentSetUp()` runs *after* every
  provider registers under Testbench (`.ai/rules/testing.md`), so that
  write wins over any package's own. Setting the dotted key
  (`livewire.component_namespaces.pages`) instead preserves siblings and lets
  the owning package's registration stand — which also means the suite
  exercises it for real instead of papering over it.

- **Local composition is a `path` repository with `symlink: true`, and the
  symlink is what makes it worth doing.** Core's `composer.json` gained
  `"nvade/numerosis-ui": "@dev"` plus a `repositories` entry pointing at
  `../numerosis-ui`; `vendor/nvade/numerosis-ui` is a symlink, so edits in
  either repo are live in the other with no `composer update`. Provider
  discovery works normally through `extra.laravel.providers` — nothing has to
  be hand-registered, and `tests/TestCase.php`'s existing
  `ignorePackageDiscoveriesFrom(): []` override is what lets that happen in
  the suite.

- **A dependency moving into a satellite does not have to be re-declared.**
  `livewire/flux` came out of core's `require` and went into
  `numerosis-ui`'s; core still resolves it transitively, and
  `.ai/rules/testing.md`'s rule ("if package code renders another
  package's components, that package belongs in `require`, not `suggest`") is
  satisfied because the requirement moved *with* the views that render it.

- **A class constant fetch autoloads the class; a bare `use` import does not.**
  `.ai/rules/optional-dependencies.md` frames the eager/lazy asymmetry
  around `implements`/`use <Trait>`; `Foo::SOME_CONST` belongs on the eager
  side too, and it is much easier to miss because it looks like a string.
  Three core views gated on `Features::enabled(SocialLoginFeature::NAME)`
  while `SocialLoginFeature` moved to `numerosis-auth-ui` — a host without
  that package would have fataled on the invitation-accept screen, the auth
  button grid, and the tenant panel's connected-accounts manager, none of
  which are auth-UI screens. **When a feature class moves to a satellite,
  grep for `<ThatClass>::` (not just `use <ThatNamespace>`) across everything
  that stays.** Fix used here: the string lives on a core class
  (`Support\Social\ConfiguredProviders::FEATURE`) and the satellite's own
  `NAME` is *defined as* that constant, so they cannot drift.

- **Core `require-dev`s the satellite, and circular `path` repositories
  resolve.** `nvade/numerosis-auth-ui` requires `nvade/numerosis` and core
  require-devs it back, both through `path` repositories with `symlink: true`.
  Composer handles the cycle (the root package satisfies the satellite's own
  requirement on it). That is what lets the moved code's real coverage stay in
  core's suite — where the tenancy/DB harness already exists — instead of
  duplicating `tests/TestCase.php` into every satellite. The satellite's own
  suite then only has to prove *its half* of the wiring (it registers its
  feature, serves the view namespace, fills its config seam), which boots
  fine under plain Testbench with no database.
  **`require-dev`, never `require`** — a `require` would be a real cycle in
  the dependency graph a host resolves.

- **The failure modes a split introduces are all silent, so each seam needs a
  test that names it.** Two now live in core:
  `tests/Feature/View/SatelliteViewNamespaceTest` (each installed satellite
  appears in the `numerosis::` hint list, and the path it registers really
  contains a file it owns) and `tests/Feature/Support/SatelliteRouteContributionTest`
  (contributed routes are bound to a central domain *and* the `web` group —
  a satellite using a plain `Route::get()` instead of
  `Numerosis::addCentralRoutes()` would answer on every tenant subdomain, and
  nothing would fail). Both were verified to fail when broken. Copy this pair
  forward: add a row to the first one's data provider per new package.

- **The assertion count moved again, and `ArchTest` is the usual culprit.**
  616 passed / 5561 assertions → 622 / 5540 across the auth-ui move: the six
  new tests add ~10, and the ~35 lost are `ArchTest`'s cashier-key scan over
  `src`, which stopped seeing 9 moved files. Not a regression, but the moved
  files were left unguarded — the satellite now carries its own `BoundaryTest`
  with that scan plus a "must not reference `Filament\` or another satellite"
  guard. **Every package's own boundary test is the first file to write, and
  it should absorb whatever core-wide scan stops covering the moved code.**

- **A hardcoded `dirname(__DIR__, N).'/src/...'` in a test fails *loudly* when
  the file moves, which is the good case — resolve it by reflection so it
  keeps working.** `PanelThemeTest` read both panel providers off disk by
  path; after the filament extraction that path did not exist and the test
  errored on `file_get_contents(): Failed to open stream`. Compare the
  view-directory scans, which went vacuous instead. `(new
  ReflectionClass(Foo::class))->getFileName()` is correct wherever the class
  lives, and PHPStan needs the `?string` return narrowed.

- **A `Filament\` reference in core is not automatically a boundary
  violation — check whether it is reached without a panel.**
  `.ai/rules/package-boundaries.md` lists 14 core files naming
  `Filament\`. Only two actually moved. `Models/{User,Central/CentralUser}`
  keep `Filament\Panel` in method signatures (lazy, and the
  `Support\Compat\Filament*` shims that make them optional **stay in core**,
  against the Phase-6 map — they are what lets core's own models load without
  Filament, so a satellite cannot own them). `Models/Central/Tenant` and
  `Resolvers/PreservingPathTenantResolver` were comments only.
  `Http/Middleware/CheckInvitationStatus` was the one real find: it calls
  `Filament\Notifications\Notification::make()` on an ordinary
  expired-invitation link, i.e. a **core** route reached with no panel
  anywhere — now `class_exists()`-guarded with a session flash fallback.
  `ApplyDefaultBranding` moved (its only consumer is the tenant plugin).
  `Support\Numerosis::assetTags()` stayed: it is core's public API and the
  registration behind it is already guarded.

- **A string containing an escaped namespace is invisible to a
  namespace-rewrite pass.** Filament's discovery calls take the namespace as
  a *string* — `->discoverResources(in: ..., for: 'Nvade\\Numerosis\\Filament\\Admin\\Resources')`
  — so a rewrite matching `Nvade\Numerosis\Filament\` misses them entirely.
  Nothing fails at load; the panel registers with zero resources and the
  symptom is `RouteNotFoundException: Route [filament.admin….index] not
  defined` from an unrelated test. **After any namespace move, grep for the
  double-backslash form as well as the single one**, and for bare
  `namespace X;` lines (no trailing separator, so a prefix-with-separator
  match skips them too).

- **Not everything the plan's package map assigns actually belongs there —
  and the second extraction corrected the map in three more places.**
  `TurnstileFeature` (core's invitation screen and `partials/head` use it),
  the `one_time_passwords` migrations (`Models\User` composes the OTP trait,
  so the tables must exist wherever core does — same reasoning as
  `activity_log`), and `layouts/auth*` / `components/auth*` / `auth-header`
  (rendered by core's invitation and suspended-tenant screens) all stayed in
  core despite being listed for `numerosis-auth-ui`. Two `use`-level greps
  decide it faster than reading the map:

  ```bash
  grep -rn "ThatClass::" src/ resources/ tests/ config/   # constants + statics
  grep -rn "x-numerosis::that-component" resources/       # view-level reach-back
  ```

- **A satellite's `Config::set()` into core's namespace auto-vivifies it, so
  "that namespace exists" is not evidence core registered.** `Arr::set()`
  creates every missing segment on the way down —
  `.ai/rules/package-host-bootstrap.md` records the destructive version of
  this (a truncated `tenancy.database`); the quiet version is that
  `numerosis-auth-ui` writing `numerosis.panels.tenant.login` *creates*
  `numerosis.panels` as an array of its own invention when core has not
  merged its config. `numerosis-filament` then guarded panel registration on
  `numerosis.panels` being non-null and the guard was simply never true-false
  — it passed on a namespace no core had supplied. **The sentinel has to be a
  key only core writes** (`numerosis.features`).

  Guarding the *write* on that same sentinel was tried and reverted: provider
  register order between two discovered packages is not ours to choose, so
  auth-ui legitimately registers before core in the package's own Testbench
  harness and the guard suppressed a write that had to happen (2 red tests in
  `SatelliteRouteContributionTest`). The asymmetry that makes this work is
  **phase, not check**: a satellite's config *write* belongs in the register
  phase (unordered, must vivify), and anything *reading* core's config to
  decide whether to wire something belongs in a `booting()` callback, which
  runs after every provider's `register()` — including core's `mergeConfigFrom`.
  `HostConfig::numerosisConfig()`'s deep-fill is what makes the vivified
  namespace harmless rather than truncating, the same way
  `.ai/rules/package-host-bootstrap.md` describes for `tenancy.database`.

  **Counter-example, found 2026-08-31 doing `packages/onboarding` (section D
  of `.claude/plans/numerosis-consolidation.md`): "writes go in the register
  phase" is only safe where the parent namespace is deep-filled.**
  `numerosis-onboarding` wrote `numerosis.tenancy.registration.steps` from
  `packageRegistered()`, exactly as auth-ui writes `numerosis.panels.tenant.login`
  — and it destroyed core's config. `Arr::set()` vivified `numerosis.tenancy`
  before core's `mergeConfigFrom()`, whose one-level `array_merge()` then kept
  that one-key array wholesale, discarding `implementations`, `provisioning`
  and `identification`. The symptom is nowhere near the cause:
  `Target [Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant] is not
  instantiable` thrown from a Filament billing page, ~40 failures.
  `numerosis.panels` survives the identical treatment **only** because
  `HostConfig::numerosisConfig()` deep-fills it; `numerosis.tenancy` is not
  deep-filled. Fixed by deferring that single write to a `booting()` callback,
  which still lands before core's `packageBooted()` feature-boot loop reads
  the key. **Before writing a satellite default into a core namespace, check
  whether `HostConfig` deep-fills that namespace — if it does not, the write
  belongs in `booting()`.**

  This is reachable in ordinary use, not just in an odd harness: Larastan
  boots an application that discovers every *vendor* package but not the root
  one (`.ai/rules/static-analysis.md`), so every satellite registers with
  core absent. That is exactly the shape of a host that installs a satellite
  and, for any reason, does not have core's provider registered — and the
  failure it produced was `Configuration value for key
  [numerosis.auth.guards.central] must be a string, NULL given`, thrown from
  inside Filament's own boot, nowhere near the cause. **A satellite must be
  able to register into a world where core's config is not there, and do
  nothing.** `Features::all()` now reads `Config::array('numerosis.features',
  [])` for the same reason.

- **A route *name* the rest of core generates cannot move to a satellite.**
  `logout` and `verification.verify` stayed in core's `routes/web.php` even
  though `routes/auth.php` moved wholesale, because their handlers are core
  auth mechanics (`Actions\Auth\LogoutUser`,
  `Http\Controllers\Auth\VerifyEmailController`) and core's own layouts and
  notifications call `route()` on both names. The split line for a route is
  "who generates the URL", not "which file it currently sits in".

## After the collapse into `packages/*` (2026-08-31)

- **A path-installed package's own `autoload-dev` is never loaded — only the
  root package's is.** So `packages/*/tests` do not autoload from their own
  `composer.json`; every satellite's test namespace has to be mapped in the
  **root** `autoload-dev` for the merged suite to see them. Nothing errors if
  you forget: PHPUnit reports "no tests found" for that testsuite, which reads
  like a glob problem.

- **PHP resolves `__FILE__` through a symlink, so a path-installed package
  registers its *real* path, not its `vendor/` one.** Every view hint, asset
  path and `dirname(__DIR__)` from `packages/ui` names
  `<repo>/packages/ui/...`, never `vendor/nvade/numerosis-ui/...`. Anything
  matching on those paths has to match the `packages/<dir>` form — this is
  what broke `SatelliteViewNamespaceTest`, which keyed on the Composer
  package name (`/numerosis-ui/`) and found nothing.

- **In one repo the filesystem enforces no boundary at all, so each rule the
  separate repos got for free has to become a test.**
  `tests/Feature/PackageBoundariesTest.php` is that file. Two things about
  writing it that are not obvious:

  - **`arch()` cannot express "ui may not reference core".** Pest's
    namespace matchers are prefix-based and `Nvade\NumerosisUi` *is* prefixed
    by `Nvade\Numerosis`, so the rule matches the package against itself.
    A filesystem scan with `/Nvade\\Numerosis(?!Ui)/` is the honest tool, and
    half the rules (`tenancy(`, `route(`) are about strings anyway.
  - **Strip comments before matching, or the guard trips on its own
    documentation.** `NumerosisUiServiceProvider`'s class docblock names the
    core classes that got `layouts/` evicted from that package — correct, and
    a textual scan cannot tell prose from a dependency. `token_get_all()`
    minus `T_COMMENT`/`T_DOC_COMMENT` for `.php`; raw contents for
    `.blade.php`, which is not tokenizable PHP.

- **A non-root `repositories` block is ignored by Composer, so it rots
  silently.** The satellites each carried `{"type":"path","url":"../numerosis"}`
  entries that had never been read by anything since core started requiring
  them. They were only wrong once the packages moved — and a split repo would
  have inherited paths pointing outside itself. Delete a package's
  `repositories` when it stops being a root package.

## Suggested better approach

The vertical slice — scaffold one package, move its files, get it green on
its own, then verify core — cost one round trip to
discover the `layouts`/`partials` mistake and fix it cheaply. Scaffolding all
five skeletons first, as the plan originally sequenced it, would have deferred
that discovery until four packages had been built on the same wrong
assumption. Keep doing them one at a time, and treat each package's own
"nothing here references what I depend on nothing for" test as the first file
written, not the last.

# Splitting Numerosis Into Packages

Facts learned actually doing it, not from the plan
(`.claude/plans/memoized-tinkering-meadow.md`, Phases 6–8). The agreed
six-package map and the D1–D4 decisions live there; this file is what the
first real extraction taught, and every bullet holds for the four still to
come.

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
  vacuous-pass family as `.claude/rules/testing.md`'s `assertDontSee` and empty
  `ArchTest` — **when a split lands, diff the assertion count, not just the
  pass count.**

  `ViewFinderInterface` declares no `getHints()`; `FileViewFinder` is what is
  actually bound, so narrow with `instanceof` or PHPStan reports
  `method.notFound`.

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
  provider registers under Testbench (`.claude/rules/testing.md`), so that
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
  `.claude/rules/testing.md`'s rule ("if package code renders another
  package's components, that package belongs in `require`, not `suggest`") is
  satisfied because the requirement moved *with* the views that render it.

## Suggested better approach

The vertical slice — scaffold one package, move its files, get it green on
its own, then verify both matrix legs of core — cost one round trip to
discover the `layouts`/`partials` mistake and fix it cheaply. Scaffolding all
five skeletons first, as the plan originally sequenced it, would have deferred
that discovery until four packages had been built on the same wrong
assumption. Keep doing them one at a time, and treat each package's own
"nothing here references what I depend on nothing for" test as the first file
written, not the last.

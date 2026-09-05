---
paths:
  - 'src/Models/**'
  - 'src/Features/**'
  - 'src/Support/Compat/**'
  - 'composer.json'
---
> **Header note, 2026-09-05 (later).** `ryangjchandler/laravel-cloudflare-turnstile`
> and `socialiteproviders/discord` moved `suggest`/`require-dev` → `require`,
> for keeps: both packages are always present now.
> `TurnstileFeature::isEnabled()` lost its `class_exists(TurnstileRule::class)`
> guard, and `SocialLoginFeature::bootstrap()` lost its
> `class_exists(\SocialiteProviders\Discord\Provider::class)` guard and the
> string-literal class names it existed to protect — both now plain `use`
> imports. `socialiteproviders/zoho` is the only optional package left in
> this family; it needs no guard of its own because nothing in core
> references a Zoho-specific class.
>
> **Header note, 2026-09-05.** `spatie/laravel-one-time-passwords` and
> `spatie/laravel-activitylog` moved `suggest` → `require`, for keeps: the
> package is always present now, and the feature toggle
> (`numerosis.features`'s `OneTimePasswordFeature`) or the model's own trait
> use (`Tenant\User`'s `LogsActivity`) is the only thing that varies.
> `Support\Compat\{HasOneTimePasswordsIfInstalled,LogsActivityIfInstalled}`
> are deleted; `Nvade\Numerosis\Models\User` and `Tenant\User` `use` the real
> traits directly, no conditional-definition shim in between.
> `ryangjchandler/laravel-cloudflare-turnstile` is the only package left that
> this file's mechanism still protects — everything below naming the other
> two as "the two remaining optional packages" is describing history.
>
> **Header note, 2026-09-03 (Phase 6).** `ryangjchandler/laravel-cloudflare-turnstile`
> moved `require` → `suggest` — `TurnstileFeature::isEnabled()` gained a
> `class_exists(TurnstileRule::class)` check, the one seam. It needed no
> `Support\Compat\*` shim: nothing in core `implements`/`use <Trait>`s a
> Turnstile symbol, only a method-body `new TurnstileRule` gated behind the
> flag — the lazy case this file's first bullet describes, not the eager one
> `Support\Compat\*` exists for. Also dropped the same phase, with no
> guard at all rather than a `suggest`: `torann/geoip`. `ResolveCheckoutRegion`
> stopped calling it, full stop — there is nothing left to degrade.
>
> **Header note, 2026-09-03 (Phase 2).** `internachi/modular` is **gone too** —
> not `suggest`, not `require-dev`, not referenced anywhere. The whole module
> system, `ModuleSystemFeature::available()`, the three
> `tenants:*-module` commands, `tests/Support/module-registry-absence-probe.php`
> and `tests/Feature/Features/ModuleRegistryAbsenceTest` were deleted in Phase 2
> of `.claude/plans/archive/humming-nibbling-flame.md`. The absence-probe recipe below
> is kept because it is the **only** way to prove a dependency is genuinely
> optional, and the two remaining optional packages
> (`spatie/laravel-one-time-passwords`, `spatie/laravel-activitylog`) have no
> such probe — write one from this recipe rather than re-deriving it.
>
> **Header note, 2026-09-03 (Phase 1).** `filament/filament` is **gone** — the package,
> both panels and `Support\Compat\Filament{User,HasTenants}Contract` were all
> deleted in Phase 1 of `.claude/plans/archive/humming-nibbling-flame.md`. Everything
> below about Filament is kept because the *mechanism* it documents (eager
> `implements`/`use trait` vs lazy type hints, the conditional-definition
> shim, one `class_exists()` seam per package) is still exactly how
> `spatie/laravel-one-time-passwords` and `spatie/laravel-activitylog` stay
> optional. Read the Filament examples as history, not as code you will find.

# Optional Dependencies

- **`implements`/`use trait` resolve their target eagerly, at class-declaration
  time — a method's own parameter/return type does not, and that asymmetry is
  the whole mechanism behind making a dependency genuinely optional.** PHP
  only needs a class-typed parameter or return type to exist at *call* time,
  not at parse/autoload time — so a base model can reference `Filament\Panel`
  in a method signature and stay loadable without Filament installed, as long
  as nothing actually calls that method. `extends`/`implements`/trait `use`
  are different: they affect the class's real structure, so the target has to
  be loaded the moment the declaring class itself is autoloaded. This is why
  `filament/filament`, `spatie/laravel-one-time-passwords` and
  `spatie/laravel-activitylog` were stuck as `require` (DEPENDENCIES.md
  called it a "Known gap") despite `config('numerosis.features')` claiming
  "everything else opt-in" — `src/Models/User.php` did `implements
  FilamentUser` and `use HasOneTimePasswords`, `Tenant\User`/`Invitation` did
  `use LogsActivity`, all four eager, all four on abstract base models every
  consumer's own model extends regardless of which features are toggled on.
  `class_exists()` guards — the pattern used everywhere else in this
  package for genuinely optional packages — cannot protect an `implements`
  clause; PHP doesn't consult it, the engine just tries to load the class.

- **Fixed by making the target of the eager clause itself conditional, not
  by removing the clause.** `Nvade\Numerosis\Support\Compat\*` — one file
  per interface/trait — does:

  ```php
  if (interface_exists(\Filament\Models\Contracts\FilamentUser::class)) {
      interface FilamentUserContract extends \Filament\Models\Contracts\FilamentUser {}
  } else {
      interface FilamentUserContract {}
  }
  ```

  (same shape for traits, with `trait_exists()`/`use RealTrait;` inside the
  `if` branch). The base model always does `implements FilamentUserContract`
  — a symbol that always exists — so it always autoloads; which behaviour
  that symbol actually carries is decided once, at file-load time, by
  whether the real package is present. Composer's psr-4 autoloader doesn't
  care that the file conditionally defines its symbol — it only maps
  name→file, never parses contents — so this works with zero autoloader
  configuration. Same fix in `ActivityResource.php` for a full class
  (`extends ActivityLogResource`), where the `else` branch is a plain empty
  class rather than an empty interface/trait: Filament's own resource
  discovery only registers a discovered class when it `is_subclass_of`
  `Filament\Resources\Resource`, so an unrelated stand-in class is simply
  skipped, not registered wrong.

- **Moving a `require` back to `suggest` isn't safe until every eager
  reference elsewhere in the package is found, not just the base models.**
  Two more turned up specifically *because* this fix was attempted, not
  because DEPENDENCIES.md's original "Known gap" note mentioned them:
  `NumerosisServiceProvider::registerFilamentPanels()` unconditionally
  registered `NumerosisAdminPanelProvider`/`NumerosisTenantPanelProvider` —
  both `extends Filament\PanelProvider` — with no `class_exists()` guard at
  all, so autoloading either fatals regardless of any feature flag; same for
  `registerFilamentTheme()`, which directly instantiates `Filament\Support\Assets\{Theme,Js,Css}`
  via `FilamentAsset::register()`. Both are now skipped
  (`class_exists(\Filament\PanelProvider::class)` /
  `class_exists(\Filament\Support\Facades\FilamentAsset::class)`) when
  Filament isn't installed — a host naming its own panel provider via
  `numerosis.panels.*.provider` is trusted to have Filament and is never
  guarded, only the package's own default classes are. **Before moving any
  `require` to `suggest`, grep the whole package for that namespace's
  `extends`/`implements`/`use <Trait>` — not just inside model files — and
  separately for any *unconditional* call site that directly instantiates
  one of its classes (`new Theme(...)`, `Filament::registerPanel(...)`,
  etc.), since those fatal exactly the same way and no `composer.json` edit
  or model-level compat shim protects them.**

- **A migration reading `config('some-package.some-key')` with no fallback
  is not automatically broken just because the package moved to `suggest` —
  check whether `HostConfig` already defaults that key first.** The nine
  (4 central, 5 tenant — recounted 2026-08-29; this bullet said eight)
  `activity_log` migrations all read
  `config('activitylog.table_name')`/`config('activitylog.database_connection')`
  with no package-supplied default (nothing merges `spatie/laravel-activitylog`'s
  own config file if it isn't installed) — this looked like it needed the
  same migration-hardening DEPENDENCIES.md's original "Known gap" note
  worried about (`Incorrect table name ''`). It didn't:
  `HostConfig::activityLogTable()` already unconditionally defaults
  `activitylog.table_name` to `'activity_log'` regardless of whether the
  real package exists, dating from before this fix. Verified by reading
  `HostConfig.php`, not assumed from the migration files alone — the
  migrations themselves contain zero `Spatie\*` class references (pure
  `Schema::create($tableName, ...)` calls), so they were never actually an
  autoload hazard, only a config-default one, and that one was already
  closed.

- **"Does this degrade cleanly without the package?" is not answerable from
  inside the suite, because `class_exists()` reports `true` for an
  already-declared class regardless of what the autoloader would do.** The
  only way to simulate absence is a **fresh process with Composer's loader
  wrapped**, before any of the package's classes are declared:

  ```php
  $loader = require dirname(__DIR__, 2).'/vendor/autoload.php';
  spl_autoload_unregister([$loader, 'loadClass']);
  spl_autoload_register(static function (string $class) use ($loader): void {
      if (str_starts_with($class, 'InterNACHI\\')) { return; }
      $loader->loadClass($class);
  });
  ```

  `tests/Support/module-registry-absence-probe.php` **was** that script and
  `tests/Feature/Features/ModuleRegistryAbsenceTest` shelled out to it
  (2026-08-31, making `internachi/modular` `suggest` again — section C of
  `.claude/plans/archive/numerosis-consolidation.md`); both were deleted with the
  module system on 2026-09-03, so read them out of git history rather than
  looking for them on disk. No probe covers the two optional packages that
  remain. Four things about it that are not obvious and would be got wrong on
  a second one:

  - **It boots no Laravel application at all.** `Features::forceForTesting()`
    answers the feature switch with no config repository, so the only thing
    under test is the class-existence half. That is what keeps the probe to
    ~50 lines instead of a Testbench harness that would itself have to be
    told not to discover the absent package's service provider.
  - **Check `autoload_files` before trusting the wrapper.** The filter is
    installed *after* `vendor/autoload.php` returns, so a package with a
    `files` autoload entry has already been included by then and cannot be
    hidden. `internachi/modular` is psr-4 only; verify per package rather
    than assuming.
  - **A class-not-found during class declaration is a catchable `Error`**, so
    the probe can report per class (`class_exists($c) || trait_exists($c)`
    inside `try/catch (Throwable)`) instead of dying on the first one. That
    is what makes the failure name the offending class.
  - **It needs a positive control and a verified failure.** The same script
    run *without* the flag must report the package present, or the absence
    assertions would pass just as well against a probe that resolved
    nothing; and the guard was confirmed to fail by temporarily writing
    `class SynchronizeModules extends ModuleConfig` (`Error: Class
    "InterNACHI\Modular\Support\ModuleConfig" not found`). Same discipline
    `.ai/rules/testing.md` demands of any regression test.

- **One `class_exists()` seam per optional package, not one per call site.**
  `internachi/modular` had seven consumers across core and
  `packages/filament`; they all ask `ModuleSystemFeature::available()`
  (feature enabled **and** `class_exists(Modules::class)`) rather than
  repeating the check. The two questions produce the same answer at every
  site, and one unguarded call site is a fatal rather than a disabled
  feature — which is exactly what the probe above is there to catch when a
  new one gets added. The three `tenants:*-module` commands share
  `Concerns\ResolvesInstalledModules` for the same reason: the lookup was
  copied three ways, so the guard would have had to be added three times.

  This also surfaced a live gap the guard work was not looking for:
  `NumerosisFilament\TenantAdmin\Pages\Modules\ModuleDetail` had **no
  `canAccess()` override at all**, relying on `mount()`'s `abort_if(...404)`
  — which runs *after* `isInstalledOnThisNode()` has already asked the
  registry. It was therefore reachable with the modules feature switched
  off, and would have been a 500 rather than a 403 without the package. **A
  page gated only inside `mount()` is not gated**; check for a `canAccess()`
  when auditing a discovered Filament page, not just for the presence of
  *some* check.

- **`configurePackage()` runs before this package's own `mergeConfigFrom()`,
  so `config('numerosis.*')` — and therefore `Features::enabled()` — is
  unreadable there.** Command registration is the case that matters:
  `spatie/laravel-package-tools` collects commands in `configurePackage()`,
  which `PackageServiceProvider::register()` calls *before* it registers the
  config merge. Gating the three `tenants:*-module` commands on
  `ModuleSystemFeature::available()` there would have dropped them for every
  host, feature on or off, with nothing failing — `artisan list` would just
  be missing three entries. They are gated on `class_exists()` alone (an
  install-time fact, config-independent) and the feature switch is enforced
  inside each command's `handle()`. Same family as the
  register-vs-`booting()` phase rule in
  `.ai/rules/package-host-bootstrap.md`: **decide whether a check belongs
  in the register phase by what it reads, not by where it reads well.**

## Suggested better approach

The `Support\Compat` pattern only exists because PHP has no first-class
"optional trait/interface" feature — every future optional dependency that
needs an eager `implements`/`use`/`extends` will need its own hand-written
conditional-definition file, one per symbol, forever. If a fourth or fifth
one shows up, consider a small code-generation step (a composer script that
emits `Support/Compat/*.php` from a declarative list of
`[symbol, realClass, kind]` tuples) rather than continuing to hand-write
near-identical files — the two here (`HasOneTimePasswordsIfInstalled`,
`LogsActivityIfInstalled`; the two Filament shims were deleted with
`packages/filament` in Phase 1) are still simple enough that this wasn't
worth building yet.

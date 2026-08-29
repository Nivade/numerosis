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

## Suggested better approach

The `Support\Compat` pattern only exists because PHP has no first-class
"optional trait/interface" feature — every future optional dependency that
needs an eager `implements`/`use`/`extends` will need its own hand-written
conditional-definition file, one per symbol, forever. If a fourth or fifth
one shows up, consider a small code-generation step (a composer script that
emits `Support/Compat/*.php` from a declarative list of
`[symbol, realClass, kind]` tuples) rather than continuing to hand-write
near-identical files — the four here are still simple enough that this
wasn't worth building yet.

---
topic: package-host-bootstrap
updated: 2026-08-07
---

# Package/Host Bootstrap Wiring

Three independent bugs stacked on top of each other and took thin-app fully
down on 2026-08-07 (facade-root fatal crash-looping every worker → 404 on
every URL → `Target class [web] does not exist` 500 → `/admin` 404 with no
admin routes registered). Each one masked the next until fixed in order.
Recorded together because they're easy to mistake for one bug while
debugging — fixing #1 just exposes #2, fixing #2 just exposes #3. The
fourth item below isn't part of that chain — it's a mistake made *while
debugging* the chain, kept here because it's the same "host bootstrap
staleness" failure mode and worth the same caution.

- **Nothing in `src/` may call a facade at config-load time — `Domains::appUrl()` did, via a rector rewrite.** `config/numerosis.php` calls
  `Domains::apexFromAppUrl()` → `hostFromAppUrl()` → `appUrl()` directly in
  the config file body, which runs inside `LoadConfiguration`, one of the
  first bootstrappers — before `RegisterFacades` has run, so
  `Facade::$app` is still null. `Domains`'s own class docblock already
  documents this ("these methods are called from `config/numerosis.php`
  while the config repository is still being built... nothing here
  throws"), and originally read raw superglobals
  (`$_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? getenv('APP_URL')`) for
  exactly that reason. A rector run (2026-08-06) rewrote that line to
  `Env::get('APP_URL', Request::server('APP_URL') ?? getenv('APP_URL'))` —
  `Request::server()` is the `Illuminate\Support\Facades\Request` facade,
  which broke the invariant the class comment was written to protect.
  Every artisan invocation fataled with `RuntimeException: A facade root
  has not been set`, thrown from inside config loading, before any
  exception handler exists to catch it — this is what crash-looped `queue`,
  `queue-provisioning`, and `reverb` under supervisor. Reverted to raw
  superglobal access. **Any future rector/rewrite pass touching `Domains.php`
  needs to be checked against this docblock by hand — a facade-safety rule
  this specific can't be enforced by rector's own rule set, since the rule
  that broke it (superglobal → facade) is usually a correct, encouraged
  rewrite everywhere else in the codebase.** See
  `.claude/rules/exception-handling.md`'s new bullet for how this same
  incident's *second* bug (masked reporting) compounded the first.

- **`App\Providers\Filament\AdminPanelProvider` / `TenantAdminPanelProvider` are host-owned, always required, and were never actually removed from thin-app — they were staged as an uncommitted `git rm`, unrelated to any of these three bugs.** Package extraction (numerosis@1d82f8f, thin-app@572a501) shrank these two files from 220/113 lines to 47/44 — everything package-owned (discovery paths, middleware stack, guards, widgets) moved into `Nvade\Numerosis\Filament\NumerosisAdminPlugin`/`NumerosisTenantPlugin`, leaving only what a host genuinely decides: the default panel and its colours. **`AdminPanelFeature`/`TenantPanelFeature` (`config('numerosis.features')`) do not replace these providers** — `AdminPanelFeature::bootstrap()` is a deliberate no-op; the feature flag is only *read* by `AdminPanelProvider::register()` (via `NumerosisAdminPlugin::shouldRegisterPanel()`) to decide whether to call `Filament::registerPanel()`. The provider class itself still has to be instantiated by `bootstrap/providers.php` for that check to ever run. Misreading the `AdminPanelFeature` docblock as "the package registers this automatically" and deleting the `bootstrap/providers.php` entries (rather than investigating why the files were staged-deleted) turned a working `/admin` 302 into a 404 with no admin routes registered at all — an error made *while debugging* the three bugs below, not one of the original three. Diagnosed via `git status` on thin-app showing `D  app/Providers/Filament/AdminPanelProvider.php` (staged, not committed) and `git log --diff-filter=D` finding no commit that ever deleted them — i.e. still fully recoverable with `git restore --staged --worktree <path>`, no recreation needed. **Before deleting anything referenced by a host's `bootstrap/`, check `git status`/`git log` on it first** — "this class doesn't exist" can mean "staged for deletion, uncommitted" rather than "genuinely gone," and those need opposite fixes.

- **A host's `bootstrap/app.php` needs both `->withRouting(using: Numerosis::routes(...))` and `->withMiddleware(...)` — neither is truly optional, despite `NumerosisServiceProvider`'s docblocks calling the *static* helpers "now optional".** thin-app's `bootstrap/app.php` had only
  `->withExceptions(...)`; both calls were simply missing. Two distinct
  failures stacked from this one omission:
  - No `->withRouting()` at all means `Numerosis::routes()` never runs —
    not the host's own routes, not even the package's `routes/web.php` +
    `routes/tenant.php`. Every URL 404s; `route:list` shows only
    Livewire/Flux asset routes, nothing from either `web.php`.
  - No `->withMiddleware()` call at all means
    `ApplicationBuilder::withMiddleware()`'s `afterResolving(HttpKernel::class,
    ...)` hook — the *only* place Laravel's baseline `web`/`api`
    middleware groups get pushed onto the `Router` — never runs.
    `NumerosisServiceProvider::registerMiddleware()` (which does run
    automatically, from `packageBooted()`) only defines the package's own
    aliases and `tenant`/`universal` groups; it assumes `web`/`api`
    already exist. Without them, every `Route::middleware('web')` (used by
    the package's own central-domain route group) dispatches into
    `BindingResolutionException: Target class [web] does not exist` —
    Laravel treats an unrecognised middleware group name as a class name
    to resolve. Reads like a totally unrelated new bug; it is the same
    root cause as the 404 above (`->withMiddleware()` missing), just
    surfaced one layer later once routing itself started working.

  Fixed by adding both calls:
  ```php
  ->withRouting(using: Numerosis::routes(...))
  ->withMiddleware(fn (Middleware $middleware) => Numerosis::middleware($middleware))
  ```
  **The ambiguity that caused this**: `NumerosisServiceProvider::registerMiddleware()`'s
  and `registerBroadcasting()`'s docblocks describe the corresponding
  *static* `Numerosis::middleware()` / `Numerosis::broadcasting()` /
  `Numerosis::broadcastChannelsPath()` helpers as "now optional" — true,
  since `packageBooted()` redoes that registration automatically. But the
  underlying **framework** calls, `->withMiddleware()` and
  `->withBroadcasting()` themselves, are a different thing — `->withRouting()`
  and `->withMiddleware()` still have no provider-level equivalent
  (`->withRouting()`'s registration must happen at `ApplicationBuilder`
  build time, before any provider boots, full stop) or their only purpose
  is to trigger a framework-owned side effect the package can't replicate
  from inside `packageBooted()` (`->withMiddleware()`'s job of seeding
  Laravel's own baseline groups). Reading "the static helper is optional"
  as "the framework hook is optional" is the exact misreading that
  produced this bug.

  ## Suggested better approach

  `InstallNumerosisCommand`'s `verifyCentralDomains()` checks that
  `config('tenancy.central_domains')` is non-empty, but nothing in the
  install command checks that `bootstrap/app.php` actually calls
  `->withRouting()`/`->withMiddleware()` at all — a host that omits either
  gets no warning from `numerosis:install`, only a runtime 404/500
  discovered by hand, as happened here. A check could read
  `bootstrap/app.php`'s source (or, more robustly, assert
  `Route::has('terms')`/`Route::hasMiddlewareGroup('web')` once the app has
  booted) and fail loudly with the exact missing call to add — turning this
  from "debug a 500 in production" into "installer told me before I
  deployed."

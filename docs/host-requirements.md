# What the host app must own

`nvade/numerosis` normalizes almost everything it needs at boot time
(`Nvade\Numerosis\Boot\HostConfig::apply()`, run from a `booting()`
callback that `NumerosisServiceProvider::packageRegistered()` registers —
deferred to that phase on purpose, so config another package's own
`mergeConfigFrom()` still has to merge into is normalized after it lands
rather than before; see `.ai/rules/package-host-bootstrap.md`) — a host
that sets database credentials, Stripe keys and `APP_URL`, runs `migrate`
and asset publishing, and writes a one-line `bootstrap/app.php` gets a
working multi-tenant SaaS. Everything past that is an override, not a
requirement.

This document is two lists: what you actually have to do, and what the
package does for you (with how to override it, if the default is wrong for
your deployment). `php artisan numerosis:install` is the executable copy of
both — it prints what `HostConfig` configured for you, then runs every
`verify*()` check below and fails loudly, naming the exact key, if
something is still wrong. Run it with `--verify-only` to check without
publishing or seeding anything.

## 0. What you install

`nvade/numerosis` is two Composer packages developed in one repository and
published as read-only splits — collapsed from six by
`.claude/plans/archive/humming-nibbling-flame.md`. Both are effectively required.

| Package | What it is |
|---|---|
| `nvade/numerosis` | Tenancy, Fortify-backed auth, billing, provisioning, the onboarding wizard, every migration and seeder, and the auth/account/onboarding screens that used to be separate packages. |
| `nvade/numerosis-ui` | The shared Blade layer: `<x-numerosis::ui.*>`, the design tokens, `livewire/flux`. Not declinable in practice — core `require`s it, because core's own views render its components and a missing Blade tag renders as literal text rather than failing. |

Auth screens, routes and session handling are `laravel/fortify`'s; core
supplies the tenancy-aware actions Fortify's contracts call and the views at
`numerosis::auth.*`. See [`extending.md`](extending.md) for the
customization seams.

Worth knowing: **installing a feature's code is not the same decision as
switching it on.** `config('numerosis.features')` is the only switch —
turning `RegistrationWizardFeature` off hides `/get-started` even though the
wizard's code is always present; turning `SocialLoginFeature` off hides
OAuth even though `laravel/socialite` is always a dependency.

`.ai/rules/package-boundaries.md` is the full seam map — routes,
features, migration paths, seeders, permission contexts, panels — for anyone
writing a sixth package rather than a host.

## 1. What you must provide

Nothing here can be defaulted — each is a secret, infrastructure, or a
framework hook that has to run before any package code can act.

| Requirement | Required value / shape | Why it can't be automated | Checked by |
|---|---|---|---|
| `APP_URL` | correct, reachable scheme+host | every domain value (`numerosis.domains.*`, `tenancy.central_domains`, `session.domain`) derives from it | — indirectly, via `verifyDomainConfig()` and `verifyCentralDomains()` below; nothing to assert about the raw env value itself |
| `STRIPE_KEY` / `STRIPE_SECRET` | real Stripe publishable/secret keys | secrets; cannot be defaulted | `verifyStripeKeys()` |
| `STRIPE_WEBHOOK_SECRET` | the signing secret Stripe's dashboard gives your webhook endpoint | secret, used to verify inbound Stripe webhooks | — not independently verifiable; a wrong value fails loudly the first time Stripe calls back, not silently, so nothing here would catch it earlier than that |
| DB credentials + `php artisan migrate` | working MySQL credentials; migrations run against the `central` connection | MySQL — tenancy needs `CREATE DATABASE`, and the `central` connection has to exist before anything else in this list means anything | `verifyDatabaseConnections()` |
| — same, `failed_jobs` table specifically | present on whichever connection `queue.failed.database` names | migrations create it; nothing else does | `verifyFailedJobsConnection()` |
| `bootstrap/app.php` wiring | `->withRouting(using: Numerosis::routes(...))` plus `Numerosis::middleware()`/`Numerosis::exceptions()` inside your own `withMiddleware()`/`withExceptions()` closures, or `Numerosis::configure(...)` for a greenfield app | `ApplicationBuilder::withRouting()`/`withMiddleware()` run at builder time, before any service provider — nothing inside `packageRegistered()`/`packageBooted()` can substitute for the framework hook itself | — `NumerosisServiceProvider::booted()` self-heals a missing call (registers routes/middleware itself if it detects neither ran) rather than failing the install command; see `.ai/rules/package-host-bootstrap.md` |
| `php artisan vendor:publish --tag=numerosis-public-assets` | run at least once, unless you build `resources/js/numerosis.js` through your own Vite | copies this package's prebuilt JS/CSS to `public/vendor/numerosis/`; `Assets::tags()` links both URLs whether or not they are there, so a missing publish is a 404, not an exception | `verifyPublicAssets()` |
| DNS + a provisioning worker | depends on `numerosis.tenancy.identification.mode` — `subdomain`: `*.{tenant_pattern}` resolves; `custom_domain`: each tenant points their own domain here; `path`: no DNS change at all. Plus, in every mode, a queue worker on the `provisioning` queue | infrastructure — tenant provisioning is queued there, not on the default worker | — infrastructure, outside anything a boot-time check can observe; `numerosis:install`'s printed manual steps name the right one for your mode |

**Plus one conditional**:

- `NUMEROSIS_APEX_DOMAIN` when served at the apex of a multi-part public
  suffix (`example.co.uk`) — the documented limit of
  `Domains::apexFromAppUrl()`'s label-count heuristic (it strips one label
  from `APP_URL`'s host, which is wrong when the registrable domain itself
  has two labels). See the `domains.apex` row below.

Checkout's region-specific payment-method order has no lookup wired in —
`torann/geoip` was dropped (Phase 6 of the scope-reduction plan) —
`ResolveCheckoutRegion` always returns null, and checkout always falls back
to `numerosis.billing.payment_methods.default_order`.

Central data (roles, permissions and the example plans) needs
seeding too, but as of the seeding-by-convention work below that's now a
side effect of a command you already run (`numerosis:install`, or a fresh
host's own `db:seed`), not a separate obligation.

## 2. What the package configures for you, and how to override it

Everything below has a package default. A row exists here because a host
*can* still break it — either by setting the key directly (bypassing the
mechanism that would have normalized it) or by customising something
upstream (a published stub, a Cashier connection name) the normalization
can't see into. `verify*()` methods described as "narrowed" ask *"did the
host break what we set"*, not *"did the host wire this up"* — the common
case (a host that touched none of this) never reaches a failure message at
all. Every normalization is independently covered by
`tests/Feature/HostConfigTest.php` (one "sets the default" case, plus a
"does not override the host" case wherever a host value of that key is a
meaningful thing to hold), and every config key `HostConfig` so much as
names has to appear somewhere in this document —
`tests/Feature/Docs/HostRequirementsTest.php` fails otherwise, which is
what stops the next normalization from shipping undocumented.

| Concern | Set to, by default | Override | Checked by |
|---|---|---|---|
| `tenancy.tenant_model` / `tenancy.domain_model` / `tenancy.central_user_model` / `tenancy.tenant_user_model` | `Numerosis::model(...)` for each — the package's own concrete class, or a host subclass at the conventional `App\Models\<suffix>` path, picked up automatically (see the `models.*` row below) | publish a stub at that path, or set `numerosis.models.<FQCN>` directly for a non-conventional location | `verifyTenancyModels()` (narrowed — HostConfig sets this; only fires for a key set directly, bypassing `Numerosis::model()`) |
| `tenancy.seeder_parameters['--class']` | `numerosis.tenancy.seeder`, always — a preference, not a correction: it projects onto this key unconditionally | set `numerosis.tenancy.seeder`, not `tenancy.seeder_parameters` directly | `verifyTenancyModels()` |
| `tenancy.central_domains` | `[numerosis.domains.central]`, derived from `APP_URL` — checked against stancl's own stock default (`['127.0.0.1', 'localhost']`, not `[]`) as well as empty, so an untouched host is corrected too | set the array yourself, or override `numerosis.domains.central` | `verifyCentralDomains()` (narrowed) |
| `tenancy.bootstrappers` | stancl's stock four, plus `SpatiePermissionsBootstrapper`, `AuthGuardBootstrapper` and `PasswordBrokerBootstrapper` (appended, never replaced) | override `tenancy.bootstrappers` to a list that excludes them, on purpose — at which point tenancy-guard/permission behaviour is your own responsibility | `verifyTenancyBootstrappers()` (fires only for a host that removed one after the append: the tenant guard then resolves central users, permission lookups read the central tables, and tenant password resets use the central broker — three silent wrong answers, no error) |
| `tenancy.migration_parameters` | `--path` includes `Numerosis::tenantMigrationPath()` (the vendor directory itself, not a published copy), `--realpath` forced true | append your own extra `--path` entries for host-specific tenant migrations | `verifyTenantMigrationPath()` (narrowed to validating any *extra* paths a host has added — the vendor path itself is unconditionally present after every boot) |
| `tenancy.filesystem.disks` | never contains `livewire` | — not user-facing, nothing to override | — actively stripped on every boot if present, so nothing to fail |
| `tenancy.filesystem.root_override.local` | `'%storage_path%/app/private/'` | set it yourself | `verifyTenantFilesystemRoot()` (checks the `%storage_path%` placeholder survives and that the override ends in the same directory as `filesystems.disks.local.root`; a wrong value otherwise surfaces as the mimetype-rejection failure `tenant-filesystem.md` documents, at upload time) |
| `tenancy.database.central_connection` | `numerosis.tenancy.central_connection`, always — a preference, not a correction | set `numerosis.tenancy.central_connection`, not this key directly | — folded into `verifyDatabaseConnections()`'s general connection checks |
| `database.connections.central` | cloned from `database.connections.{database.default}` when absent | define your own `central` connection | `verifyDatabaseConnections()` |
| `database.connections.*.options` (MySQL only) | mirrors `innodb_lock_wait_timeout` next to any `lock_wait_timeout` found | set both yourself in one `SET SESSION` string, or neither | `verifyLockWaitTimeout()` (narrowed — only fires for a `lock_wait_timeout` string that doesn't match the pattern `HostConfig` recognises) |
| `session.domain` | `'.'.numerosis.domains.apex` | set it yourself | `verifySessionDomain()` (narrowed) |
| `queue.failed.database` | `'central'` | set it yourself | `verifyFailedJobsConnection()` (narrowed for the connection name; the "table actually exists" half stays fully real — that needs a migration to have run) |
| `auth.guards.tenant` / `auth.providers.tenant` | session guard over an eloquent provider on `Numerosis::model(Tenant\User::class)` | set `auth.guards.tenant` / `auth.providers.tenant` yourself | `verifyAuthGuards()` (narrowed) |
| `auth.providers.users.model` | `Numerosis::model(CentralUser::class)`, always — a preference, not a correction: set `numerosis.models.<CentralUser FQCN>`, not this key directly | point `numerosis.models.<CentralUser FQCN>` at your own model, as long as it implements `Nvade\Numerosis\Contracts\Auth\CentralUserModel` | folded into `verifyAuthGuards()` |
| `auth.passwords.<broker>` | a broker over the `users` provider, `password_reset_tokens` table, whenever `auth.defaults.passwords` names a broker with no entry | define the broker yourself | `verifyAuthPasswordBroker()` (narrowed) |
| `auth.passwords.tenant` | a broker over the `tenant` provider, `password_reset_tokens` table (in the tenant database), whenever unset. `PasswordBrokerBootstrapper` points `fortify.passwords` at it for the duration of tenancy — without which a tenant subdomain's forgot/reset request resolves the *central* `users` provider | define `auth.passwords.tenant` yourself, or rename it through `numerosis.auth.password_brokers.tenant` | `verifyTenantAuthProvider()` (the tenant provider's model and this broker's entry; the behaviour itself is covered by `tests/Feature/Auth/PasswordResetBrokerTest.php`, which asserts the notifiable is the tenant user and not its synced central mirror) |
| `numerosis.*` deep-fill (the mechanism, not any one key) | every key under `config/numerosis.php` is filled in at every depth from the package's own defaults, so a host override file only has to name what it's actually changing | publish `config/numerosis.php` (or write a smaller override file — any key you omit is filled in, at any depth, not just the top level) | — a mechanism, not a single checkable value; the individual keys it protects each have their own row and check below |
| `numerosis.routes.home_view` | `'numerosis::home'` — a placeholder. Core registers the `home` route unconditionally (OAuth redirects, checkout error paths and the tenant panel all fall back to it), but the page itself is the product's | point this at your own view. Do **not** register a second route named `home` or a second route on `/`: core declares its own first, so yours would never match | — no `verify*()`; a missing view fails as `View [x] not found` on the first request to `/`, which is loud enough |
| `numerosis.domains.apex` / `.central` / `.tenant_pattern` | derived from `APP_URL` (see the conditional obligation above for the one case this derivation is wrong) | `NUMEROSIS_APEX_DOMAIN` / `NUMEROSIS_CENTRAL_DOMAIN` / `NUMEROSIS_TENANT_DOMAIN` | `verifyDomainConfig()` |
| `numerosis.social.routes.{redirect,callback}.name` | `'social.redirect'` / `'social.callback'` — the package's own route names. Provider metadata (label, icon, which are configured) is `Enums\Auth\SocialProvider`, not config — nothing here to override for that | override if you rename either route | `verifySocialRoutes()` (narrowed) |
| `numerosis.models.<FQCN>` | unset — `Numerosis::model()` finds a subclass at the conventional `App\Models\<suffix>` path automatically when one exists and extends the package model | publish the stub (`--tag numerosis-models`) and let convention find it, or set this key directly for a non-conventional class location | `verifyModelOverrides()` |
| `livewire.temporary_file_upload.disk` | `'livewire'` — a dedicated disk, same physical root as `local`, deliberately excluded from `tenancy.filesystem.disks` | override, but not to `'local'` or anything else tenant-suffixed | `verifyLivewireUploadDisk()` |
| `livewire.component_namespaces.{numerosis-layouts,numerosis-pages}` | the package's own `resources/views/{layouts,pages}` | override if you publish those views locally — **from your own provider's `register()`, not `boot()`** (see the note below). The generic `layouts`/`pages` keys are yours; core never writes them, because a Livewire namespace maps one prefix to exactly one directory | `verifyLivewireComponentNamespaces()` |
| `cache.serializable_classes` (read, never written) | left exactly as your `config/cache.php` has it; what follows it is `DomainTenantResolver::$shouldCache`, which is only turned on when this value can round-trip a cached tenant model (`null`/`true`, or an allowlist naming `tenancy.tenant_model`) | add your tenant model to the allowlist to keep the resolver cache, or force the decision with `numerosis.tenancy.cache_resolved_tenants` (`true`/`false`) | `verifyTenantResolverCache()` (warns when the cache ended up off, since that costs a central lookup per tenant request; never fails the install) |
| your own `database/migrations/*.php` filenames | must not collide with the package's `database/migrations/central/*.php`. The package ships extended copies of a fresh Laravel app's stock users and jobs migrations, but under `2019_09_01_00000{0,2}_*` rather than the stock `0001_01_01_*`, so an untouched app does not collide | delete yours, or merge what it adds into the package's copy | `verifyCentralMigrationCollisions()` (warns; the migrator dedupes by filename and keeps *yours*, so the package's copy silently never runs) |
| `Database\Seeders\DatabaseSeeder` (container binding, not config) | the package's own seeder, whenever the host hasn't defined that class | write `database/seeders/DatabaseSeeder.php` yourself (the class existing wins outright — nothing here can override it); call `$this->call(\Nvade\Numerosis\Database\Seeders\DatabaseSeeder::class)` from it to combine the two | — the binding itself isn't checked (it can't fail in a way an install-time check would catch); the *data* it seeds is: |
| central `permissions` / `payment_plans` rows | seeded by `numerosis:install` (default; `--no-seed` to skip) or a fresh host's own `db:seed`, via the binding above | run either command | `verifyCentralDataSeeded()` |
| `resources/{css,js}` (published `numerosis-assets`) | not required — `Numerosis::assetTags()` renders the prebuilt `dist/numerosis.js`/`dist/numerosis.css` whenever `resources/js/numerosis.js` hasn't been published | publish + customise (`numerosis.js` imports `stripe-checkout.js`/`stripe-confirm.js` by relative path, both load-bearing for payment — keep the directory together). Once `resources/js/numerosis.js` is also an entry in your `vite.config.js`, your build is used in place of the prebuilt bundle. Override the CSS through `tokens.css`'s custom properties rather than by publishing it | `verifyPublishedAssetsMatchSource()` (warns on drift between a published copy and the vendor original; doesn't fail the install) |
| `activitylog.table_name` | `'activity_log'`, whenever unset | set it yourself | `verifyActivityLogTable()` (the table has to exist on the central connection; a wrong value otherwise surfaces as `Incorrect table name ''` from `migrate`) |
| `fortify.features` | registration, password reset (following `PasswordResetFeature`), profile updates, password updates and email verification — written whenever `numerosis.auth.manage_fortify_features` is `true` (the default). Two-factor authentication and passkeys are dropped from that list: no `numerosis::auth.two-factor-challenge` view ships and the columns those controllers write do not exist, so leaving them on registers screens that fail only once somebody reaches them | set `numerosis.auth.manage_fortify_features` to `false` and edit `fortify.features` yourself — from that point the list is yours outright, including turning 2FA back on (you supply the views and the migration). Toggling password reset alone is better done through `numerosis.features`' `PasswordResetFeature`, so the two configs cannot disagree | `verifyFortifyFeatures()` (refuses two-factor and passkeys, whose screens have no views or columns here; the backfill itself is covered by `tests/Feature/HostConfigTest.php`) |

### Notes worth keeping in mind

- **`php artisan route:cache` does not work, and fails loudly.** Fortify's
  route file is loaded once per central domain *and* once inside the tenant
  group (`Numerosis::routes()`), because `guest:<guard>` is baked
  into route middleware at registration time and the two groups need
  different guards. Route *names* are therefore duplicated — `login`,
  `register`, `password.*`, `verification.*` — and Laravel refuses to
  serialize a duplicate name:
  `Unable to prepare route [login] for serialization. Another route has
  already been assigned name [login].` Serving the routes uncached is
  correct: the router matches the domain-scoped copy first on a central
  host, and `route('login')` resolves the domain-less tenant copy, which
  generates a host-relative URL that is right on both. Only the *cache* step
  is unavailable. Leave `route:cache` out of your deploy pipeline;
  `config:cache`, `view:cache` and `event:cache` are unaffected. Removing
  this limitation means one registration behind a context-aware guard (see
  `.ai/rules/auth-login.md`), which is a change to this package, not to your
  app.
- **`numerosis.tenancy.identification.mode` is a deploy-time choice, not a
  runtime toggle.** `subdomain` (default) keeps the original behaviour
  exactly: `{tenant}.{apex}`, a `domains` row per tenant, wildcard DNS.
  `custom_domain` gives each tenant its own fully-qualified host — the
  registration wizard grows a second field, because the tenant's *identifier*
  (which is also its database name) can never be the domain itself. `path`
  serves tenants at `{central_domain}/{tenant}/…` with no DNS and no
  `domains` rows at all. Switching after tenants exist does not migrate the
  ones already provisioned. `path` mode's full request round trip **is**
  covered, by `tests/Browser/PathModeTest` (a real HTTP request into an
  authenticated tenant panel, with stancl's own resolver rebound as the
  negative control). `subdomain` mode's is not, and cannot be from this
  suite — the browser plugin serves Laravel in-process, so
  `runningInConsole()` stays true and the tenant panel's `{tenant}` wildcard
  is always registered; verify that one by hand against a real web server.
  See `.ai/rules/identification-modes.md`.
- **You install no optional packages.** `ryangjchandler/laravel-cloudflare-turnstile`,
  `spatie/laravel-one-time-passwords` and `spatie/laravel-activitylog` are all
  `require`, so `App\Models\User` (or your own subclass) always composes
  `HasOneTimePasswords`/`LogsActivity` directly, with no compat shim involved.
  Turnstile and passwordless (OTP) login are gated by `numerosis.features`'s
  `TurnstileFeature`/`OneTimePasswordFeature`; with Turnstile off,
  `<x-numerosis::turnstile-field />` still resolves and renders nothing, so
  forms need no conditional. Activity logging on `Tenant\User` runs
  unconditionally.
- **Don't name a guard literally `central` alongside `web`.** Two session
  guards over one model meant a user could authenticate under one and not
  the other. `web` *is* the central guard; `numerosis.auth.guards.central`
  is the only indirection needed, and it already defaults to `'web'`.
- **The shared-session trap `session.domain` creates.** Because one session
  spans every subdomain, `SessionGuard` stores nothing but a primary key —
  and tenant user ids are per-database integers. Visiting tenant A (where
  you're id 2) then tenant B (where id 2 is someone else) would authenticate
  the session as that someone else on B, with no error, if nothing else
  intervened. `Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant` is the fix
  (forgets the tenant guard's session key when the session's recorded tenant
  changes) and must stay registered **after** `StartSession` in any stack
  that can reach a tenant route — it already is, inside
  `Numerosis::middleware()`'s `tenant` group; this note is here so a host
  extending that group doesn't reorder it.
- **`config/app.php` needs nothing.** This package reads nothing out of it
  beyond `app.name`/`app.url`/`app.env`, which Laravel defines itself. It
  used to invent five keys there (`app.domain`, `app.host`,
  `app.central.*`) — a package key in a framework file can have no default
  at all, since `mergeConfigFrom()` only ever supplies defaults for a
  package's *own* config file. They live under `numerosis.domains.*` now.
- **`numerosis.models.*` resolves in three steps.** An explicit
  `numerosis.models.<FQCN>` entry wins; failing that, the same class name
  under your app namespace (`App\Models\Central\Tenant` for
  `Nvade\Numerosis\Models\Central\Tenant`) is used when it exists *and*
  extends the package model, so a conventionally-named subclass needs no
  config at all and an unrelated class of that name is ignored; failing both,
  the package's own class. Every model this covers is concrete, so overriding
  is optional, and results are memoized for the lifetime of the process. Read
  this before touching either this table's row or `HostConfig::tenancyModels()`.
- **`#[UseFactory]` is the escape hatch for your own `App\Models\*` classes.**
  `Numerosis::factoryNameFor()` replaces Laravel's *global* factory-name
  resolver (it has to: every factory ships from the package even when the
  model is your subclass), and it answers for any class under a `\Models\`
  namespace — including models of your own that this package knows nothing
  about. `App\Models\User::factory()` therefore resolves to
  `Nvade\Numerosis\Database\Factories\UserFactory`, while `modelNameFor()`
  still instantiates *your* `User` — so the symptom is never a wrong class,
  it's wrong fields (a state method that doesn't exist, a column your
  migrations added that nothing sets). Annotate the model and Laravel's own
  `HasFactory::newFactory()` short-circuits both resolvers:
  `#[UseFactory(\Database\Factories\UserFactory::class)]`.
- **Reclaim `livewire.component_namespaces` from `register()`, not
  `boot()`.** `LivewireServiceProvider::boot()` reads that config once and
  bakes the result into the view finder's hints
  (`app('view')->addNamespace(...)`), so a value set in any provider's
  `boot()` is accepted by the config repository and changes nothing about how
  `numerosis-pages::`/`numerosis-layouts::` actually resolve — you see the right value in
  `config()` and the package's views still win. Laravel registers app
  providers after package providers, so setting it in your own
  `AppServiceProvider::register()` lands after this package's default and
  before Livewire reads it. Confirmed by reflecting the view finder's `hints`,
  not by reading the config back.
- **Test suites need `Nvade\Numerosis\Testing\CleansUpTenancyDatabases`.**
  `RefreshDatabase` transacts the default connection only, so every row
  written through `central` (every `Tenant`, `Domain`, `CentralUser`) survives
  into the next test and collides on a unique key, and every physical tenant
  database a test provisioned outlives the rollback because `CREATE DATABASE`
  is not transactional. Compose the trait into your base test case, call
  `setUpCleansUpTenancyDatabases()` from `setUp()` and `keepDatabaseSchema()`
  after `parent::tearDown()`:

  ```php
  protected function setUp(): void
  {
      $this->setUpCleansUpTenancyDatabases();

      parent::setUp();
  }

  protected function tearDown(): void
  {
      parent::tearDown();

      $this->keepDatabaseSchema();
  }
  ```

  Under Orchestra Testbench the `setUp…()` call is optional, since Testbench
  calls `setUp{TraitName}` for every composed trait itself, and calling it
  anyway is a no-op — so one base class copies between a Testbench harness
  and a plain-Laravel host unchanged. Ordering is handled inside the trait on
  purpose:
  `beforeApplicationDestroyed()` *appends* on
  `Illuminate\Foundation\Testing\TestCase` and `array_unshift`es on
  Testbench's, so the same registration lands on opposite sides of
  `RefreshDatabase`'s rollback depending on the base class — hand-rolled
  copies of this cleanup get that backwards about half the time, and a
  cleanup that runs on the wrong side either blocks on the test's own row
  locks or drops a database the rollback then reconnects to.
- **A published `resources/css/app.css` has to keep every `@source` line it
  came with, the Flux one especially.** Flux's real component templates
  live under `vendor/livewire/flux/stubs/resources/views/flux/**` (loaded
  through `Blade::anonymousComponentPath`, so no ordinary glob reaches
  them) and its own prebuilt `flux.css` does not ship every utility they
  use. Without
  `@source '../../vendor/livewire/flux/stubs/resources/views/**/*.blade.php';`
  a class like `dark:bg-white/10` compiles to nothing at all, and an input
  falls back to opaque white while its text stays light — near-white on
  white, worst in dark mode, with no error anywhere. A copy published
  before 2026-08-12 predates that line; `verifyPublishedAssetsMatchSource()`
  reports the drift, but only as a warning, since editing these files is
  the point of publishing them.
- **`config/cashier.php`, `config/permission.php` and `config/queue.php`**
  are published as-is by their own packages rather than being package
  invariants — nothing to normalize or verify beyond what's already listed
  above.
- **The deep-fill has one blind spot: a key your file still names, in an
  outdated shape.** It only ever backfills a key that's entirely *missing*
  from your file — a top-level key renamed or restructured since you last
  looked at this file is invisible to it, since your old key is still
  present, just wrong. There is no version guard against this; compare your
  file against the package's own `config/numerosis.php` after an upgrade.

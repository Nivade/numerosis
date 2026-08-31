# What the host app must own

`nvade/numerosis` normalizes almost everything it needs at boot time
(`Nvade\Numerosis\Support\HostConfig::apply()`, run from a `booting()`
callback that `NumerosisServiceProvider::packageRegistered()` registers —
deferred to that phase on purpose, so config another package's own
`mergeConfigFrom()` still has to merge into is normalized after it lands
rather than before; see `.claude/rules/package-host-bootstrap.md`) — a host
that sets database credentials, Stripe keys and `APP_URL`, runs `migrate`
and `filament:assets`, and writes a one-line `bootstrap/app.php` gets a
working multi-tenant SaaS. Everything past that is an override, not a
requirement.

This document is two lists: what you actually have to do, and what the
package does for you (with how to override it, if the default is wrong for
your deployment). `php artisan numerosis:install` is the executable copy of
both — it prints what `HostConfig` configured for you, then runs every
`verify*()` check below and fails loudly, naming the exact key, if
something is still wrong. Run it with `--verify-only` to check without
publishing or seeding anything.

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
| One-line `bootstrap/app.php` | `Numerosis::configure(...)`, or `->withRouting()`/`->withMiddleware()` calling `Numerosis::routes()`/`Numerosis::middleware()` | `ApplicationBuilder::withRouting()`/`withMiddleware()` run at builder time, before any service provider — nothing inside `packageRegistered()`/`packageBooted()` can substitute for the framework hook itself | — `NumerosisServiceProvider::booted()` self-heals a missing call (registers routes/middleware itself if it detects neither ran) rather than failing the install command; see `.claude/rules/package-host-bootstrap.md` |
| `php artisan filament:assets` | run at least once | already required for Filament's own core CSS; also copies this package's prebuilt theme + app JS/CSS to `public/{css,js}/nvade/numerosis/` | `verifyFilamentThemeAsset()` |
| DNS + a provisioning worker | depends on `numerosis.tenancy.identification.mode` — `subdomain`: `*.{tenant_pattern}` resolves; `custom_domain`: each tenant points their own domain here; `path`: no DNS change at all. Plus, in every mode, a queue worker on the `provisioning` queue | infrastructure — tenant provisioning is queued there, not on the default worker | — infrastructure, outside anything a boot-time check can observe; `numerosis:install`'s printed manual steps name the right one for your mode |

**Plus two conditionals**:

- `NUMEROSIS_APEX_DOMAIN` when served at the apex of a multi-part public
  suffix (`example.co.uk`) — the documented limit of
  `Domains::apexFromAppUrl()`'s label-count heuristic (it strips one label
  from `APP_URL`'s host, which is wrong when the registrable domain itself
  has two labels). See the `domains.apex` row below.
- `MAXMIND_LICENSE_KEY` plus one `php artisan geoip:update`, if you want
  checkout's region-specific payment-method order. `HostConfig` points
  `geoip.service` at torann/geoip's local `maxmind_database` driver (the
  only one needing no outbound request per lookup), but the `.mmdb` file
  it reads is licensed, so nothing can ship or fetch it for you. The
  package schedules `geoip:update` weekly once that service is configured;
  the *first* run is yours. Skipping this is a supported state, not a
  broken one: `ResolveCheckoutRegion` catches the driver's throw, reports
  it and returns null, so checkout falls back to
  `numerosis.billing.payment_methods.default_order` — at the cost of one
  reported exception per checkout page load, which is worth knowing before
  it shows up in Sentry.

Central data (roles, permissions, example plans, module catalogue) needs
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
what stops the next normalization from shipping undocumented the way
`geoip.service` did.

| Concern | Set to, by default | Override | Checked by |
|---|---|---|---|
| `tenancy.tenant_model` / `tenancy.domain_model` / `tenancy.central_user_model` / `tenancy.tenant_user_model` | `Numerosis::model(...)` for each — the package's own concrete class, or a host subclass at the conventional `App\Models\<suffix>` path, picked up automatically (see the `models.*` row below) | publish a stub at that path, or set `numerosis.models.<FQCN>` directly for a non-conventional location | `verifyTenancyModels()` (narrowed — HostConfig sets this; only fires for a key set directly, bypassing `Numerosis::model()`) |
| `tenancy.seeder_parameters['--class']` | the package's own `TenantDatabaseSeeder` | set `tenancy.seeder_parameters` yourself | `verifyTenancyModels()` |
| `tenancy.central_domains` | `[numerosis.domains.central]`, derived from `APP_URL` — checked against stancl's own stock default (`['127.0.0.1', 'localhost']`, not `[]`) as well as empty, so an untouched host is corrected too | set the array yourself, or override `numerosis.domains.central` | `verifyCentralDomains()` (narrowed) |
| `tenancy.bootstrappers` | stancl's stock four, plus `SpatiePermissionsBootstrapper` and `AuthGuardBootstrapper` (appended, never replaced) | override `tenancy.bootstrappers` to a list that excludes them, on purpose — at which point tenancy-guard/permission behaviour is your own responsibility | — appended unconditionally on every boot, so nothing to fail; a host who deliberately overrides the list past this point is opting out of the guarantee, not tripping a bug |
| `tenancy.identification.middleware` / `tenancy.identification.domain_identification_middleware` (dev-master only) | this package's `InitializeTenancyByDomainOrSubdomain` subclass appended, alongside stancl's stock list | override either array yourself if you register a different identification middleware | — appended unconditionally on every boot; a no-op on v3, which has neither key. Without this, dev-master's `getRouteMode()` can't recognise the subclass as identification middleware and falls back to `RouteMode::CENTRAL`, so `PreventAccessFromUnwantedDomains` 404s every tenant-domain request — see `.claude/rules/stancl-tenancy-v4.md` |
| `tenancy.cache.stores` (dev-master only) | any entry whose `cache.stores.<name>.driver` is `array` is filtered out | set `tenancy.cache.stores` yourself if you need finer control | — filtered unconditionally on every boot; a no-op on v3 (no such key) and a no-op whenever the configured cache store isn't `array`. Without this, `CacheTenancyBootstrapper::getCacheStores()` hard-throws `Cache store [array] is not supported by this bootstrapper.` for any host on `CACHE_STORE=array` |
| `tenancy.migration_parameters` | `--path` includes `Numerosis::tenantMigrationPath()` (the vendor directory itself, not a published copy), `--realpath` forced true | append your own extra `--path` entries for host-specific tenant migrations | `verifyTenantMigrationPath()` (narrowed to validating any *extra* paths a host has added — the vendor path itself is unconditionally present after every boot) |
| `tenancy.filesystem.disks` | never contains `livewire` | — not user-facing, nothing to override | — actively stripped on every boot if present, so nothing to fail |
| `tenancy.filesystem.root_override.local` | `'%storage_path%/app/private/'` | set it yourself | — no `verify*()`; a wrong value here surfaces as the mimetype-rejection failure `tenant-filesystem.md` documents, at upload time, not at install time |
| `tenancy.database.central_connection` | `'central'` | set it yourself | — folded into `verifyDatabaseConnections()`'s general connection checks |
| `database.connections.central` | cloned from `database.connections.{database.default}` when absent | define your own `central` connection | `verifyDatabaseConnections()` |
| `database.connections.*.options` (MySQL only) | mirrors `innodb_lock_wait_timeout` next to any `lock_wait_timeout` found | set both yourself in one `SET SESSION` string, or neither | `verifyLockWaitTimeout()` (narrowed — only fires for a `lock_wait_timeout` string that doesn't match the pattern `HostConfig` recognises) |
| `session.domain` | `'.'.numerosis.domains.apex` | set it yourself | `verifySessionDomain()` (narrowed) |
| `queue.failed.database` | `'central'` | set it yourself | `verifyFailedJobsConnection()` (narrowed for the connection name; the "table actually exists" half stays fully real — that needs a migration to have run) |
| `auth.guards.tenant` / `auth.providers.tenant` | session guard over an eloquent provider on `Numerosis::model(Tenant\User::class)` | set `auth.guards.tenant` / `auth.providers.tenant` yourself | `verifyAuthGuards()` (narrowed) |
| `auth.providers.users.model` | `Numerosis::model(CentralUser::class)`, whenever the current value doesn't implement `App\Contracts\Auth\CentralUserModel` | point it at your own model, as long as it implements that contract | folded into `verifyAuthGuards()` |
| `auth.passwords.<broker>` | a broker over the `users` provider, `password_reset_tokens` table, whenever `auth.defaults.passwords` names a broker with no entry | define the broker yourself | `verifyAuthPasswordBroker()` (narrowed) |
| `numerosis.*` deep-fill (the mechanism, not any one key) | every key under `config/numerosis.php` is filled in at every depth from the package's own defaults, so a host override file only has to name what it's actually changing | publish `config/numerosis.php` (or write a smaller override file — any key you omit is filled in, at any depth, not just the top level) | — a mechanism, not a single checkable value; the individual keys it protects each have their own row and check below |
| `numerosis.schema_version`, in a *published* `config/numerosis.php` | must match the package's own current value | bump it once you've confirmed your file still matches the package's current shape | `verifyConfigSchemaVersion()` |
| `numerosis.domains.apex` / `.central` / `.tenant_pattern` | derived from `APP_URL` (see the conditional obligation above for the one case this derivation is wrong) | `NUMEROSIS_APEX_DOMAIN` / `NUMEROSIS_CENTRAL_DOMAIN` / `NUMEROSIS_TENANT_DOMAIN` | `verifyDomainConfig()` |
| `numerosis.social.providers` | the package's 5-provider metadata block (used only for hosts running `SocialLoginFeature`; `[]` disables the buttons) | override in your own config file | `verifySocialProviders()` (narrowed) |
| `numerosis.social.routes.{redirect,login}.name` | `'oauth'` / `'oauth.callback'` — the package's own route names | override if you rename either route | `verifySocialRoutes()` (narrowed) |
| `numerosis.models.<FQCN>` | unset — `Numerosis::model()` finds a subclass at the conventional `App\Models\<suffix>` path automatically when one exists and extends the package model | publish the stub (`--tag numerosis-models`) and let convention find it, or set this key directly for a non-conventional class location | `verifyModelOverrides()` |
| `livewire.temporary_file_upload.disk` | `'livewire'` — a dedicated disk, same physical root as `local`, deliberately excluded from `tenancy.filesystem.disks` | override, but not to `'local'` or anything else tenant-suffixed | `verifyLivewireUploadDisk()` |
| `livewire.component_namespaces.{layouts,pages}` | the package's own `resources/views/{layouts,pages}` | override if you publish those views locally — **from your own provider's `register()`, not `boot()`** (see the note below) | `verifyLivewireComponentNamespaces()` |
| `cache.serializable_classes` (read, never written) | left exactly as your `config/cache.php` has it; what follows it is `DomainTenantResolver::$shouldCache`, which is only turned on when this value can round-trip a cached tenant model (`null`/`true`, or an allowlist naming `tenancy.tenant_model`) | add your tenant model to the allowlist to keep the resolver cache, or force the decision with `numerosis.tenancy.cache_resolved_tenants` (`true`/`false`) | `verifyTenantResolverCache()` (warns when the cache ended up off, since that costs a central lookup per tenant request; never fails the install) |
| your own `database/migrations/*.php` filenames | must not collide with the package's `database/migrations/central/*.php` — a fresh Laravel app's three stock migrations (`0001_01_01_00000{0,1,2}_*`) do, because the package ships extended copies of exactly those | delete yours, or merge what it adds into the package's copy | `verifyCentralMigrationCollisions()` (warns; the migrator dedupes by filename and keeps *yours*, so the package's copy silently never runs) |
| `Database\Seeders\DatabaseSeeder` (container binding, not config) | the package's own seeder, whenever the host hasn't defined that class | write `database/seeders/DatabaseSeeder.php` yourself (the class existing wins outright — nothing here can override it); call `$this->call(\Nvade\Numerosis\Database\Seeders\DatabaseSeeder::class)` from it to combine the two | — the binding itself isn't checked (it can't fail in a way an install-time check would catch); the *data* it seeds is: |
| central `permissions` / `payment_plans` rows | seeded by `numerosis:install` (default; `--no-seed` to skip) or a fresh host's own `db:seed`, via the binding above | run either command | `verifyCentralDataSeeded()` |
| `resources/{css,js}` (published `numerosis-assets`) | not required — `Numerosis::assetTags()` renders the prebuilt `dist/numerosis.js`/`dist/numerosis.css` whenever `resources/js/numerosis.js` hasn't been published | publish + customise (`numerosis.js` imports `stripe-checkout.js`/`stripe-confirm.js` by relative path, both load-bearing for payment — keep the directory together) | `verifyPublishedAssetsMatchSource()` (warns on drift between a published copy and the vendor original; doesn't fail the install) |
| `activitylog.table_name` | `'activity_log'`, whenever unset | set it yourself | — no `verify*()`; a wrong value here surfaces as `Incorrect table name ''` from `migrate`, not at install-check time |
| `geoip.service` | `'maxmind_database'`, whenever unset — torann/geoip ships `null` there and its own `GeoIP::getService()` throws on that, so every checkout page load would fatal | choose another service (`maxmind_api`, `ipapi`, …), or keep this one and repoint `geoip.services.maxmind_database.database_path` | — no `verify*()`: the sole caller (`ResolveCheckoutRegion`) catches, reports and returns null, so even a missing `.mmdb` costs the region-specific payment-method order rather than the checkout. See §1's MaxMind conditional |
| `numerosis.panels.{admin,tenant}.provider`, `numerosis.panels.default` | both providers unset — `nvade/numerosis-filament` registers `NumerosisAdminPanelProvider` / `NumerosisTenantPanelProvider` itself when installed, so a host's `bootstrap/providers.php` lists neither, and without that package no panel registers at all (`filament/filament` is then not needed either); `default` is `'admin'`, the panel a bare `/` resolves into | name your own provider class in either key to replace that panel wholesale — core registers what you name and numerosis-filament stands down for that panel — or set `default` to `'tenant'`/`null` (`null` only if your own provider supplies a default panel — Filament has nowhere to route an unscoped request otherwise) | — a class Filament cannot register already fails by name, from Filament, at boot; whether a panel registers at all stays each plugin's `shouldRegisterPanel()` call, which is request-shaped and not observable at install time |
| `numerosis.panels.tenant.login`, `numerosis.panels.admin.tenant_registration_component` | `login` is **`null`** — `nvade/numerosis-auth-ui` fills it with its own `PasswordlessLogin` at register time when installed, and Filament falls back to its own login page when it is not; `tenant_registration_component` is the `tenant-registration` wizard alias | name your own Livewire login component, or your own wizard's registered alias; set either to `null` to switch that piece off | — read defensively rather than verified: a `null`, an empty string, or a login class that isn't installed all mean "skip that wiring", never a fatal. That is the point of these two keys — `->login(Foo::class)` takes a compile-time string, so an uninstalled login component would otherwise register fine and only fatal at the first `/login` on a tenant subdomain |

### Notes worth keeping in mind

- **`numerosis.tenancy.identification.mode` is a deploy-time choice, not a
  runtime toggle.** `subdomain` (default) keeps the original behaviour
  exactly: `{tenant}.{apex}`, a `domains` row per tenant, wildcard DNS.
  `custom_domain` gives each tenant its own fully-qualified host — the
  registration wizard grows a second field, because the tenant's *identifier*
  (which is also its database name) can never be the domain itself. `path`
  serves tenants at `{central_domain}/{tenant}/…` with no DNS and no
  `domains` rows at all. Switching after tenants exist does not migrate the
  ones already provisioned. `path` mode's full request round trip is
  source-verified rather than covered by an automated test — verify it by
  hand against a real web server before relying on it. See
  `.claude/rules/identification-modes.md`.
- **`filament/filament`, `spatie/laravel-one-time-passwords` and
  `spatie/laravel-activitylog` are all `suggest`, not `require`** — as is
  `nvade/numerosis-filament`, which owns both panels and pulls
  `filament/filament` in with it (`alizharb/filament-activity-log` is that
  package's `suggest`, not this one's). Skip them and you get a working
  multi-tenant SaaS with no admin/tenant panel, no passwordless (OTP)
  login, and no activity logging — `App\Models\User` (or your own subclass)
  still autoloads and works, it just doesn't `implements FilamentUser` or
  compose `HasOneTimePasswords`/`LogsActivity`. This works because
  `Nvade\Numerosis\Support\Compat\*` (`FilamentUserContract`,
  `FilamentHasTenantsContract`, `HasOneTimePasswordsIfInstalled`,
  `LogsActivityIfInstalled`) conditionally define themselves against the
  real package's interface/trait only when it's installed
  (`interface_exists()`/`trait_exists()`, checked once at file scope) —
  install one of these four later and the corresponding behaviour turns on
  with no code change on your side, since the base models already
  reference the compat symbol, not the real one directly.
- **Don't name a guard literally `central` alongside `web`.** Two session
  guards over one model meant a user could authenticate under one and not
  the other. `web` *is* the central guard; `numerosis.auth.guards.central`
  is the only indirection needed, and it already defaults to `'web'`.
- **The shared-session trap `session.domain` creates.** Because one session
  spans every subdomain, `SessionGuard` stores nothing but a primary key —
  and tenant user ids are per-database integers. Visiting tenant A (where
  you're id 2) then tenant B (where id 2 is someone else) would authenticate
  the session as that someone else on B, with no error, if nothing else
  intervened. `App\Http\Middleware\EnsureSessionMatchesTenant` is the fix
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
- **`numerosis.models.*`'s three-step resolution** (explicit config, then
  convention, then the package's own class) is documented in full on
  `Numerosis::model()`'s own docblock — read that before touching either
  this table's row or `HostConfig::tenancyModels()`.
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
  `pages::`/`layouts::` actually resolve — you see the right value in
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
  after `parent::tearDown()`. Ordering is handled inside the trait on purpose:
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
- **`config/cashier.php`, `config/permission.php`, `config/broadcasting.php`,
  `config/queue.php`, `config/numerosis.php`'s `modules` block** are
  published as-is by their own packages, or are pure host *data* (the
  module catalogue, Filament plugin map) rather than package invariants —
  nothing to normalize or verify beyond what's already listed above.
- **The deep-fill has one blind spot: a key your file still names, in an
  outdated shape.** It only ever backfills a key that's entirely *missing*
  from your file — a top-level key renamed or restructured since you last
  looked at this file is invisible to it, since your old key is still
  present, just wrong. `schema_version` is the guard against that case, not
  against the common case of an incomplete file (which the deep-fill
  already handles on its own, with no version bump needed).

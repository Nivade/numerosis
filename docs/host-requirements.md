# What the host app must own

`nvade/numerosis` normalizes almost everything it needs at boot time
(`Nvade\Numerosis\Support\HostConfig::apply()`, called from
`NumerosisServiceProvider::packageRegistered()` on every request) — a host
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
| Wildcard DNS + a provisioning worker | `*.{tenant_pattern}` resolves; a queue worker runs on the `provisioning` queue | infrastructure — tenant provisioning is queued there, not on the default worker | — infrastructure, outside anything a boot-time check can observe |

**Plus one conditional**: `NUMEROSIS_APEX_DOMAIN` when served at the apex of
a multi-part public suffix (`example.co.uk`) — the documented limit of
`Domains::apexFromAppUrl()`'s label-count heuristic (it strips one label
from `APP_URL`'s host, which is wrong when the registrable domain itself
has two labels). See the `domains.apex` row below.

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
`tests/Feature/HostConfigTest.php` (one "sets the default" case and one
"does not override the host" case, per key).

| Concern | Set to, by default | Override | Checked by |
|---|---|---|---|
| `tenancy.tenant_model` / `domain_model` / `central_user_model` / `tenant_user_model` | `Numerosis::model(...)` for each — the package's own concrete class, or a host subclass at the conventional `App\Models\<suffix>` path, picked up automatically (see the `models.*` row below) | publish a stub at that path, or set `numerosis.models.<FQCN>` directly for a non-conventional location | `verifyTenancyModels()` (narrowed — HostConfig sets this; only fires for a key set directly, bypassing `Numerosis::model()`) |
| `tenancy.seeder_parameters['--class']` | the package's own `TenantDatabaseSeeder` | set `tenancy.seeder_parameters` yourself | `verifyTenancyModels()` |
| `tenancy.central_domains` | `[numerosis.domains.central]`, derived from `APP_URL` — checked against stancl's own stock default (`['127.0.0.1', 'localhost']`, not `[]`) as well as empty, so an untouched host is corrected too | set the array yourself, or override `numerosis.domains.central` | `verifyCentralDomains()` (narrowed) |
| `tenancy.bootstrappers` | stancl's stock four, plus `SpatiePermissionsBootstrapper` and `AuthGuardBootstrapper` (appended, never replaced) | override `tenancy.bootstrappers` to a list that excludes them, on purpose — at which point tenancy-guard/permission behaviour is your own responsibility | — appended unconditionally on every boot, so nothing to fail; a host who deliberately overrides the list past this point is opting out of the guarantee, not tripping a bug |
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
| `livewire.component_namespaces.{layouts,pages}` | the package's own `resources/views/{layouts,pages}` | override if you publish those views locally | `verifyLivewireComponentNamespaces()` |
| `Database\Seeders\DatabaseSeeder` (container binding, not config) | the package's own seeder, whenever the host hasn't defined that class | write `database/seeders/DatabaseSeeder.php` yourself (the class existing wins outright — nothing here can override it); call `$this->call(\Nvade\Numerosis\Database\Seeders\DatabaseSeeder::class)` from it to combine the two | — the binding itself isn't checked (it can't fail in a way an install-time check would catch); the *data* it seeds is: |
| central `permissions` / `payment_plans` rows | seeded by `numerosis:install` (default; `--no-seed` to skip) or a fresh host's own `db:seed`, via the binding above | run either command | `verifyCentralDataSeeded()` |
| `resources/{css,js}` (published `numerosis-assets`) | not required — `Numerosis::assetTags()` renders the prebuilt `dist/numerosis.js`/`dist/numerosis.css` whenever `resources/js/numerosis.js` hasn't been published | publish + customise (`numerosis.js` imports `stripe-checkout.js`/`stripe-confirm.js` by relative path, both load-bearing for payment — keep the directory together) | `verifyPublishedAssetsMatchSource()` (warns on drift between a published copy and the vendor original; doesn't fail the install) |
| `activitylog.table_name` | `'activity_log'`, whenever unset | set it yourself | — no `verify*()`; a wrong value here surfaces as `Incorrect table name ''` from `migrate`, not at install-check time |

### Notes worth keeping in mind

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

# What the host app must own

`nvade/numerosis` requires several config files it does not ship and cannot
merge safely. Each is a **whole file the host owns**, not a section the
package can `mergeConfigFrom` into — publishing a partial stub and merging one
level deep silently drops keys the package needs (see the `config/tenancy.php`
row below for the concrete case that makes this true). This document lists,
file by file, exactly which keys the host must set and why — copied from
`saas-m`'s `.claude/rules/*`, since a consumer who re-derives the reason from
scratch is the consumer who "fixes" it wrong.

## `config/tenancy.php`

stancl/tenancy's own filename. The package does not merge into it — it only
documents the keys it needs present:

| Key | Required value / shape | Why | Checked by |
|---|---|---|---|
| `tenant_model` | host's concrete `Tenant` model (extends the package's abstract base) | package ships an abstract base only — see `.claude/plans/package-extraction.md` Phase 4.4 | `verifyTenancyModels()` |
| `domain_model` | host's concrete `Domain` model | same | `verifyTenancyModels()` |
| `central_user_model` | host's concrete `CentralUser` model | `App\Contracts\Auth\CentralUserModel`; read by `UserModelResolver` | `verifyTenancyModels()` |
| `tenant_user_model` | host's concrete tenant `User` model | `App\Contracts\Auth\TenantUserModel`; same resolver | `verifyTenancyModels()` |
| `central_domains` | list of every hostname serving the central app | consumed by `Numerosis::routes()` — one `Route::middleware('web')->domain($domain)` group per entry | `verifyCentralDomains()` |
| `bootstrappers` | must include, in addition to stancl's own: `App\Services\Tenancy\Bootstrappers\SpatiePermissionsBootstrapper`, `App\Services\Tenancy\Bootstrappers\AuthGuardBootstrapper` | `AuthGuardBootstrapper` is the *entire* enforcement mechanism for "central domain default guard = central guard, inside tenant = tenant guard" — omit it and every ambient `auth()->user()` resolves `CentralUser` on tenant domains. See `auth-guards.md` | `verifyTenancyBootstrappers()` |
| `migration_parameters` | `'--path'` must include `Nvade\Numerosis\Support\Numerosis::tenantMigrationPath()` (an absolute vendor path, `--realpath` true) | the default is the vendor directory itself, not a published copy — publishing `numerosis-tenant-migrations` remains available as an opt-in customisation escape hatch, but two identical copies can only ever drift | `verifyTenantMigrationPath()` |
| `seeder_parameters` | host's tenant seeder class, typically one that calls the package's own tenant seeders | package seeders live under `Nvade\Numerosis\Database\Seeders` | `verifyTenancyModels()` |
| `filesystem.disks` | must **not** contain `livewire` | see `config/filesystems.php` row below — this is the one entry that must be *absent*, not present | `verifyLivewireDiskExclusion()` |

**Why the package can't own this file:** `mergeConfigFrom()` merges one
level deep. A host that publishes only a stub and lets the package supply the
rest ends up with the package's `bootstrappers` array (missing
`AuthGuardBootstrapper` if the host forgot to append it) or the package's
`tenant_model` (pointing at the abstract base, which the panel then can't
resolve `{tenant}` route-model-binding against — see `filament-tenancy.md`'s
404 case). The whole file must be reasoned about as one unit.

## `config/database.php`

| Key | Required value / shape | Why | Checked by |
|---|---|---|---|
| `connections.central` | host's central DB connection | read by `stancl` and by `App\Actions\Billing\FindTenantByStripeCustomer` and friends | `verifyDatabaseConnections()` |
| `connections.tenant` | template connection stancl clones per-tenant | must not itself be named anything the host also uses directly | `verifyDatabaseConnections()` |
| PDO init command | `SET SESSION lock_wait_timeout = …, innodb_lock_wait_timeout = …` — the pair is **optional** (leaving both unset keeps MySQL's defaults, which is what a production host usually wants), but setting one without the other is not | **both** variables, not just one — `lock_wait_timeout` alone only bounds DDL/metadata locks; ordinary row/FK locks (`INSERT`/`UPDATE`/`DELETE`) wait out `innodb_lock_wait_timeout`, whose MySQL default is 50s. Setting only the first turns "collision, 10s, loud" into "collision, 50s, still loud but 5x more expensive" — and leaves you believing lock waits are bounded when they are not. See `testing.md` | `verifyLockWaitTimeout()` |

The `tenant` connection inherits this init command automatically (stancl's
`DatabaseConfig::connection()` merges the central connection's config), so it
does not need to be set twice.

## `config/auth.php`

| Key | Required value / shape | Why | Checked by |
|---|---|---|---|
| `defaults.guards.context.central` | e.g. `'web'` | never the literal `'web'` at call sites — `App\Support\Routes\RouteNames`-style indirection, read through this key, is how `AuthGuardBootstrapper` and every panel `authGuard()` call agree on a name | `verifyAuthGuards()` |
| `defaults.guards.context.tenant` | e.g. `'tenant'` | same | `verifyAuthGuards()` |
| both providers | pointing at the host's concrete central/tenant user models | Spatie's guard-name resolution (`Guard::getConfigAuthGuards()`) matches `auth.guards.*.provider` against the model class — this is what makes `CentralUser::guardName()` / `Tenant\User::guardName()` resolve correctly without hardcoding either name on the model | `verifyAuthGuards()` |
| `defaults.passwords` + `passwords.<broker>` | a broker naming a real provider (default `users` → provider `users`, table `password_reset_tokens`) | `Password::sendResetLink()` resolves its user model through the **broker** config, not through `auth.providers` directly. With no broker entry Laravel falls back to its own generic `Illuminate\Foundation\Auth\User`, which has no `Notifiable` trait — the failure is `Call to undefined method ...User::notify()`, which reads as a broken user model rather than as missing broker config. | `verifyAuthPasswordBroker()` |
| `social.providers` | an array, `[]` if `SocialLoginFeature` is off | `Nvade\Numerosis\Support\Social\ConfiguredProviders::all()` (used by the social-login button grid and the tenant panel's social-accounts manager) reads this with `Config::array(...)`, which throws `InvalidArgumentException` rather than returning `[]` when the key is missing entirely — a config key merely absent, not empty, surfaces as a 500 from an otherwise-unrelated view. | `verifySocialProviders()` |
| `social.routes.{redirect,login}.name` | the names of the host's OAuth redirect and callback routes (`oauth` / `oauth.callback`) — only needed when `social.providers` is non-empty | The social-login button component passes the value straight into `route(...)`, so an unset key is `route(null)`: the host sees `Route [] not defined` raised from `components/auth/buttons/social.blade.php`, which names neither the config key nor the route it was trying to build. Credentials in `config/services.php` are a separate axis — `ConfiguredProviders` renders a button only for a provider present in **both**, so a provider with metadata and no `client_id` correctly renders nothing. | `verifySocialRoutes()` |

**Do not** name a guard literally `central` alongside `web` — saas-m tried
this, found two session guards over one model meant a user could authenticate
under one and not the other, and deleted the duplicate. `web` *is* the
central guard; the config key above is the only indirection needed.

## `config/session.php`

| Key | Required value / shape | Why | Checked by |
|---|---|---|---|
| `domain` | a hostname starting with a **leading dot** (e.g. `.example.com`) | without the dot, the session cookie is scoped to exactly one hostname and does not carry across tenant subdomains — but see the next row, because carrying across subdomains is exactly what makes the next bug possible | `verifySessionDomain()` |

**The shared-session trap this creates:** because one session spans every
`*.example.com` subdomain, `SessionGuard` stores nothing but a primary key —
and tenant user ids are per-database integers. Visiting tenant A (where you're
id 2) then tenant B (where id 2 is someone else) authenticates the session as
that someone else on B, with no error. `App\Http\Middleware\EnsureSessionMatchesTenant`
is the package's fix (forgets the tenant guard's session key when the
session's recorded tenant changes) and **must be registered after
`StartSession`** in every stack that can reach a tenant route — it's already
positioned correctly inside `Numerosis::middleware()`'s `tenant` group, this
note is here so a host extending that group doesn't reorder it.

## `config/filesystems.php` + `config/livewire.php`

| Key | Required value / shape | Why | Checked by |
|---|---|---|---|
| `filesystems.disks.livewire` | **package-supplied** (`NumerosisServiceProvider::packageBooted()` sets it when absent) — separate disk, same physical root as `local` (`storage_path('app/private')`), **not** listed in `tenancy.filesystem.disks`; override if you need a different root | Livewire's `livewire/upload-file` route is registered by Livewire's own service provider with only the `web` middleware group — it never passes through `tenancy.identification`, so it always runs central. If `local`'s root is tenant-suffixed (correct, for genuinely tenant-isolated files) and Livewire uploads also go through `local`, the upload writes to the un-suffixed central path while the tenant-panel page validating it looks in the tenant-suffixed path. The failure reads as `"The logo field must be a file of type: image/*."` — a mimetype rejection — not a 404 or a missing-file error, because `TemporaryUploadedFile::getMimeType()` silently can't find the file at the wrong path. See `tenant-filesystem.md`. | `verifyLivewireDiskExclusion()` |
| `livewire.temporary_file_upload.disk` | **package-supplied**, `'livewire'` when unset — override if needed, but not to `'local'` | points Livewire's own config at the fixed-root disk above | `verifyLivewireUploadDisk()` |
| `livewire.component_namespaces` | **package-supplied** when the key is absent or still Livewire's stock `resource_path('views/{layouts,pages}')` default — `NumerosisServiceProvider::packageBooted()` points both at the package's own `resources/views/{layouts,pages}`; override if you publish those views locally instead | Livewire's own default config points `'layouts'`/`'pages'` at `resource_path('views/{layouts,pages}')` — correct for a plain single-repo app, wrong here: those files ship from the package, not the host. Without this override, `Route::livewire('/tenants/mine', 'numerosis::pages.tenant.mine')` and `<livewire:layouts::header />` (used by `resources/views/layouts/app/header.blade.php`) fail with `Unable to find component: [...]`, which reads like a missing route/view rather than a namespace pointed at the wrong directory. | `verifyLivewireComponentNamespaces()` |
| `livewire.component_layout` | `'layouts::app'` | Livewire's own default; unaffected by the row above as long as `'layouts'` resolves per that row — listed here only so the two are read together. | — Livewire's own default; nothing to assert unless a host changes it |

## `resources/css`, `resources/js`

| Key | Required value / shape | Why | Checked by |
|---|---|---|---|
| `resources/{css,js}` | published (`numerosis-assets` tag) into `resource_path()` directly, kept in sync with the package originals | `resources/views/partials/styles.blade.php` `@vite`s the host's own `resources/js` root, and `central.js` imports `stripe-checkout.js`/`stripe-confirm.js` by relative path — both only resolve if the whole directory lands together at `resources/js`, not nested under a package-specific path. A host is allowed to customise the published copy; three of the JS files are load-bearing for payment, so silent drift is worth surfacing even though it isn't a hard failure. | `verifyPublishedAssetsMatchSource()` |

## `config/cashier.php`, `config/permission.php`, `config/broadcasting.php`

Published as-is by their own packages (`laravel/cashier`,
`spatie/laravel-permission`, the framework's broadcasting stub). The package
does not modify these; it only assumes their default shape — except for the
two Cashier credentials, without which every billing path fails at the first
API call:

| Key | Required value / shape | Why | Checked by |
|---|---|---|---|
| `cashier.key` | Stripe publishable key (`STRIPE_KEY`) | read by the checkout Livewire components when they mount Stripe Elements; an empty value renders a card field that never initialises, with the failure visible only in the browser console. | `verifyStripeKeys()` |
| `cashier.secret` | Stripe secret key (`STRIPE_SECRET`) | every server-side Stripe call. Unset, the first one throws `Invalid API Key provided` from deep inside the SDK, naming neither this package nor the config key. | `verifyStripeKeys()` |

## `config/queue.php`

| Key | Required value / shape | Why | Checked by |
|---|---|---|---|
| `queue.failed.database` | a connection whose database carries `failed_jobs` — the central one, since that is where this package's `recreate_failed_jobs_table` migration runs | Gated by `QUEUE_FAILED_DRIVER`, **not** by `queue.default`: a host on Redis queues still writes exhausted jobs here, and `queue:work`'s own `JobFailed` listener is what does the insert — so a wrong connection does not lose a log line, it throws inside the worker. Laravel's skeleton default is `sqlite`, and the failure reads `Database file at path […]/database.sqlite does not exist`, naming neither this key nor `failed_jobs`. Do **not** point it at a connection that moves under tenancy. | `verifyFailedJobsConnection()` |

## `config/app.php`

**Nothing.** Ordinary framework file, entirely host-owned, and this package no
longer reads a single key out of it beyond `app.name`/`app.url`/`app.env`,
which Laravel defines itself.

It used to require five: `app.domain`, `app.host` and
`app.central.{domain,default,subdomain}`. Those were package keys living in a
framework file, which had two costs worth remembering if anyone is tempted to
add a sixth. First, `mergeConfigFrom()` can only supply defaults for a
package's *own* config file, so keys placed in `config/app.php` can have no
default at all — every consumer had to hand-edit Laravel's config, and
`numerosis:install` could only *check* for them and describe the resulting
error. Second, they drifted: `app.domain` derived from `env('DOMAIN')` while
`app.host` derived from `env('DOMAIN_NAME').'.'.env('DOMAIN_EXTENSION')`, and
`Domain::getUrl()` read the second while `CreateTenantDomain` and
`DefaultTenantDomainPolicy` read the first — so a tenant's stored domain and
its generated URL could name different hosts with nothing comparing them.

They are `config/numerosis.php`'s `domains` block now; see the section below.

## `config/numerosis.php` — `domains`

Package-owned, with defaults derived from `APP_URL`
(`Nvade\Numerosis\Support\Domains`), so **a host that sets none of these still
boots**. Override through the env keys when the derivation is wrong for your
deployment.

| Key | Required value / shape | Why | Checked by |
|---|---|---|---|
| `domains.apex` (`NUMEROSIS_APEX_DOMAIN`) | the registrable domain tenant subdomains hang off, e.g. `example.com` | Read by `DefaultTenantDomainPolicy`, `CreateTenantDomain`, `Domain::getUrl()` and two Filament tenant screens. The default strips the leading label from `APP_URL`'s host when it has three or more labels — correct for `app.example.com`, and correct by luck of label count for `app.example.co.uk`, but **wrong for a two-label-suffix domain served at its apex** (`example.co.uk` would reduce to `co.uk`). Set this explicitly on such a domain; doing it properly needs the Public Suffix List, which is not worth a dependency for a default. | `verifyDomainConfig()` |
| `domains.central` (`NUMEROSIS_CENTRAL_DOMAIN`) | the hostname the central app answers on, e.g. `app.example.com` | `routes/auth.php`'s OAuth redirect route calls `Route::get(...)->domain(config('numerosis.domains.central'))->name('oauth')`. If this is ever null, the failure does not surface where it is read: `Illuminate\Routing\Route::domain(null)` is a *getter* branch returning the route's current domain string instead of `$this`, so it fails one line later as `Call to a member function name() on string` — reads like a routing bug in the package. Defaults to `APP_URL`'s host verbatim. | `verifyDomainConfig()` |
| `domains.tenant_pattern` (`NUMEROSIS_TENANT_DOMAIN`) | must contain the literal `{tenant}`, e.g. `{tenant}.example.com` | Passed to Filament's `->tenantDomain()`, which substitutes the placeholder. A pattern without it routes every tenant to the same host. Defaults to `'{tenant}.'.domains.apex` — **not** `APP_URL`'s host, which may carry the central subdomain and would put every tenant one level too deep. | `verifyDomainConfig()` |

**A host that published `config/numerosis.php` before these keys existed loses
them silently.** `mergeConfigFrom()` merges one level deep, so an older
`domains` array wins wholesale and the new keys are simply absent — the same
shape D13 records for the deleted `numerosis-billing`/`numerosis-tenancy`
files. `verifyDomainConfig()` exists for exactly that case; re-publish with
`--tag=numerosis-config --force` and re-apply your edits.

## `database/seeders/DatabaseSeeder.php` — the host's own file

Not config, but the same shape of problem: a host file the package's data
depends on and cannot reach.

Laravel's skeleton ships `Database\Seeders\DatabaseSeeder`, and `db:seed`
runs **that** one. The package's own seeders live under
`Nvade\Numerosis\Database\Seeders` and are never reached unless the host
calls them — either by `$this->call(\Nvade\Numerosis\Database\Seeders\DatabaseSeeder::class)`
from its own seeder, or by running `php artisan numerosis:install --seed`,
which calls them directly. Both are re-runnable; every package seeder keys on
a natural key (`permissions.name`, `payment_plans.slug`, `modules.slug`).

| Requirement | Required value / shape | Why | Checked by |
|---|---|---|---|
| central `permissions` rows | non-empty | Spatie's `hasPermissionTo()` **throws** `PermissionDoesNotExist` rather than returning false, so an unseeded table turns every policy check into a 500 reading `There is no permission named 'viewAny permissions' for guard 'web'` — which looks like a guard misconfiguration and sends you into `auth-guards.md` instead of into the seeder. | `verifyCentralDataSeeded()` |
| central `payment_plans` rows | non-empty | The registration wizard's plan step renders whatever `PaymentPlanRepository::findBySlug()`'s source returns; empty means an empty step, which reads as a styling bug rather than missing data. | `verifyCentralDataSeeded()` |

## `config/numerosis.php` — `models`

Only required if the host publishes the model stubs (`--tag numerosis-models`)
or writes its own subclasses. A host running on the package's own models needs
none of this: every key defaults to `null`, and `Numerosis::model()` then
returns the package class. `numerosis:install` writes these keys when it
publishes stubs, and verifies them either way.

| Key | Required value / shape | Why | Checked by |
|---|---|---|---|
| `models.<package FQCN>` | the host subclass for that model, set through its `NUMEROSIS_MODEL_*` env key | Publishing a stub does **nothing on its own.** All ~108 package call sites resolve through `Numerosis::model()`, which returns the package's own class unless this key names something else (D12). A host that creates rows through the stub while this stays unset gets package class-strings written into morph columns (`LinkSubscriptionToTenant`'s `subscribable_type` is the clearest), so a later polymorphic lookup finds nothing, Cashier's `updateOrCreate` falls through to an `insert`, and that insert collides on a unique key — **surfacing as `SQLSTATE 1205`/`1062` on an unrelated statement**, i.e. reading exactly like the lock-wait contention `testing.md` documents. | `verifyModelOverrides()` |
| the value's class | must exist and must extend the package model it overrides | `Numerosis::model()` returns the value verbatim; a typo'd or unrelated class reaches Eloquent, not this package's own error handling. | `verifyModelOverrides()` |
| the value's `.env` form | **single-quoted**: `NUMEROSIS_MODEL_TENANT='App\Models\Central\Tenant'` | phpdotenv reads `\M` inside a *double-quoted* value as an unrecognised escape sequence and throws `InvalidFileException` for the **entire .env file** — one double-quoted class-string here stops the app booting at all, with an error naming neither this key nor this package. Bare (unquoted) also works; double-quoted never does. | — written by `appendModelOverrides()`; a hand-edited `.env` fails at boot, before any command runs |

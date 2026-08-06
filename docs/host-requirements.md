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
| `migration_parameters` | must point at an **absolute path** (`--realpath`) to the package's tenant migrations, since after installation those files live under `vendor/nvade/numerosis/database/migrations/tenant` | a relative path resolves against the host's `database/migrations`, which doesn't have them | `verifyTenantMigrationPath()` |
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
| `filesystems.disks.livewire` | separate disk, same physical root as `local` (`storage_path('app/private')`), **not** listed in `tenancy.filesystem.disks` | Livewire's `livewire/upload-file` route is registered by Livewire's own service provider with only the `web` middleware group — it never passes through `tenancy.identification`, so it always runs central. If `local`'s root is tenant-suffixed (correct, for genuinely tenant-isolated files) and Livewire uploads also go through `local`, the upload writes to the un-suffixed central path while the tenant-panel page validating it looks in the tenant-suffixed path. The failure reads as `"The logo field must be a file of type: image/*."` — a mimetype rejection — not a 404 or a missing-file error, because `TemporaryUploadedFile::getMimeType()` silently can't find the file at the wrong path. See `tenant-filesystem.md`. | `verifyLivewireDiskExclusion()` |
| `livewire.temporary_file_upload.disk` | `'livewire'`, not `'local'` | points Livewire's own config at the fixed-root disk above | `verifyLivewireUploadDisk()` |
| `livewire.component_namespaces` | `['layouts' => <path to the package's `resources/views/layouts`>, 'pages' => <path to the package's `resources/views/pages`>]` | Livewire's own default config points `'layouts'`/`'pages'` at `resource_path('views/{layouts,pages}')` — correct for a plain single-repo app, wrong here: those files ship from the package, not the host. Without this override, `Route::livewire('/tenants/mine', 'numerosis::pages.tenant.mine')` and `<livewire:layouts::header />` (used by `resources/views/layouts/app/header.blade.php`) fail with `Unable to find component: [...]`, which reads like a missing route/view rather than a namespace pointed at the wrong directory. A host that vendors the package can point this at `base_path('vendor/nvade/numerosis/resources/views/{layouts,pages}')`, or publish those two directories locally and point at the published copy — either works, since Livewire's Finder just needs a real filesystem path. | `verifyLivewireComponentNamespaces()` |
| `livewire.component_layout` | `'layouts::app'` | Livewire's own default; unaffected by the row above as long as `'layouts'` resolves per that row — listed here only so the two are read together. | — Livewire's own default; nothing to assert unless a host changes it |

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

## `config/app.php`

Ordinary framework file, entirely host-owned — except one non-obvious key
`routes/auth.php` reads directly:

| Key | Required value / shape | Why | Checked by |
|---|---|---|---|
| `domain` | the bare apex domain the app serves, e.g. `example.com` | read with `Config::string('app.domain')` by `DefaultTenantDomainPolicy`, `CreateTenantDomain` and two Filament tenant screens. `Config::string()` throws `InvalidArgumentException` on a missing key rather than returning a default, so an unset key takes out tenant creation *and* the panel screens that list domains. | `verifyAppDomain()` |
| `central.default` | the central app's own hostname, e.g. `'central.'.env('DOMAIN')` | `routes/auth.php`'s OAuth redirect route calls `Route::get(...)->domain(config('app.central.default'))->name('oauth')`. Leaving this key unset doesn't throw where it's read: `Illuminate\Routing\Route::domain(null)` is a *getter* branch, returning the route's current domain string instead of `$this`, so the failure surfaces one line later as `Call to a member function name() on string` — reads like a routing bug in the package, is a missing host config key. | `verifyCentralDefaultDomain()` |

`central.domain` / `central.subdomain` are also referenced by
`config/numerosis.php`'s example default for `NUMEROSIS_TENANT_DOMAIN` — see
`.env.example`'s `DOMAIN`/`CENTRAL_SUBDOMAIN` keys, which is what those
values normally derive from.

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

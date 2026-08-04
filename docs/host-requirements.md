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

| Key | Required value / shape | Why |
|---|---|---|
| `tenant_model` | host's concrete `Tenant` model (extends the package's abstract base) | package ships an abstract base only — see `.claude/plans/package-extraction.md` Phase 4.4 |
| `domain_model` | host's concrete `Domain` model | same |
| `central_user_model` | host's concrete `CentralUser` model | `App\Contracts\Auth\CentralUserModel`; read by `UserModelResolver` |
| `tenant_user_model` | host's concrete tenant `User` model | `App\Contracts\Auth\TenantUserModel`; same resolver |
| `central_domains` | list of every hostname serving the central app | consumed by `Numerosis::routes()` — one `Route::middleware('web')->domain($domain)` group per entry |
| `bootstrappers` | must include, in addition to stancl's own: `App\Services\Tenancy\Bootstrappers\SpatiePermissionsBootstrapper`, `App\Services\Tenancy\Bootstrappers\AuthGuardBootstrapper` | `AuthGuardBootstrapper` is the *entire* enforcement mechanism for "central domain default guard = central guard, inside tenant = tenant guard" — omit it and every ambient `auth()->user()` resolves `CentralUser` on tenant domains. See `auth-guards.md` |
| `migration_parameters` | must point at an **absolute path** (`--realpath`) to the package's tenant migrations, since after installation those files live under `vendor/nvade/numerosis/database/migrations/tenant` | a relative path resolves against the host's `database/migrations`, which doesn't have them |
| `seeder_parameters` | host's tenant seeder class, typically one that calls the package's own tenant seeders | package seeders live under `Nvade\Numerosis\Database\Seeders` |
| `filesystem.disks` | must **not** contain `livewire` | see `config/filesystems.php` row below — this is the one entry that must be *absent*, not present |

**Why the package can't own this file:** `mergeConfigFrom()` merges one
level deep. A host that publishes only a stub and lets the package supply the
rest ends up with the package's `bootstrappers` array (missing
`AuthGuardBootstrapper` if the host forgot to append it) or the package's
`tenant_model` (pointing at the abstract base, which the panel then can't
resolve `{tenant}` route-model-binding against — see `filament-tenancy.md`'s
404 case). The whole file must be reasoned about as one unit.

## `config/database.php`

| Key | Required value / shape | Why |
|---|---|---|
| `connections.central` | host's central DB connection | read by `stancl` and by `App\Actions\Billing\FindTenantByStripeCustomer` and friends |
| `connections.tenant` | template connection stancl clones per-tenant | must not itself be named anything the host also uses directly |
| PDO init command | `SET SESSION lock_wait_timeout = …, innodb_lock_wait_timeout = …` | **both** variables, not just one — `lock_wait_timeout` alone only bounds DDL/metadata locks; ordinary row/FK locks (`INSERT`/`UPDATE`/`DELETE`) wait out `innodb_lock_wait_timeout`, whose MySQL default is 50s. Setting only the first turns "collision, 10s, loud" into "collision, 50s, still loud but 5x more expensive" — see `testing.md` |

The `tenant` connection inherits this init command automatically (stancl's
`DatabaseConfig::connection()` merges the central connection's config), so it
does not need to be set twice.

## `config/auth.php`

| Key | Required value / shape | Why |
|---|---|---|
| `defaults.guards.context.central` | e.g. `'web'` | never the literal `'web'` at call sites — `App\Support\Routes\RouteNames`-style indirection, read through this key, is how `AuthGuardBootstrapper` and every panel `authGuard()` call agree on a name |
| `defaults.guards.context.tenant` | e.g. `'tenant'` | same |
| both providers | pointing at the host's concrete central/tenant user models | Spatie's guard-name resolution (`Guard::getConfigAuthGuards()`) matches `auth.guards.*.provider` against the model class — this is what makes `CentralUser::guardName()` / `Tenant\User::guardName()` resolve correctly without hardcoding either name on the model |

**Do not** name a guard literally `central` alongside `web` — saas-m tried
this, found two session guards over one model meant a user could authenticate
under one and not the other, and deleted the duplicate. `web` *is* the
central guard; the config key above is the only indirection needed.

## `config/session.php`

| Key | Required value / shape | Why |
|---|---|---|
| `domain` | a hostname starting with a **leading dot** (e.g. `.example.com`) | without the dot, the session cookie is scoped to exactly one hostname and does not carry across tenant subdomains — but see the next row, because carrying across subdomains is exactly what makes the next bug possible |

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

| Key | Required value / shape | Why |
|---|---|---|
| `filesystems.disks.livewire` | separate disk, same physical root as `local` (`storage_path('app/private')`), **not** listed in `tenancy.filesystem.disks` | Livewire's `livewire/upload-file` route is registered by Livewire's own service provider with only the `web` middleware group — it never passes through `tenancy.identification`, so it always runs central. If `local`'s root is tenant-suffixed (correct, for genuinely tenant-isolated files) and Livewire uploads also go through `local`, the upload writes to the un-suffixed central path while the tenant-panel page validating it looks in the tenant-suffixed path. The failure reads as `"The logo field must be a file of type: image/*."` — a mimetype rejection — not a 404 or a missing-file error, because `TemporaryUploadedFile::getMimeType()` silently can't find the file at the wrong path. See `tenant-filesystem.md`. |
| `livewire.temporary_file_upload.disk` | `'livewire'`, not `'local'` | points Livewire's own config at the fixed-root disk above |

## `config/cashier.php`, `config/permission.php`, `config/broadcasting.php`

Published as-is by their own packages (`laravel/cashier`,
`spatie/laravel-permission`, the framework's broadcasting stub). The package
does not modify these; it only assumes their default shape.

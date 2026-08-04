# Caching Audit & Remediation Plan

**Status: ✅ Executed.** All three phases (F1–F9 + hygiene) shipped on
`fix/caching-invalidation` — self-declared in the "Status" section below,
confirmed by `.claude/rules/tenant-caching.md`.

Date: 2026-07-28
Branch base: `refactor/billing-provisioning-clarity` (merged to master)

## Status

**All three phases (F1–F9 + hygiene) shipped** on `fix/caching-invalidation`.
Each fix was verified load-bearing by reverting it and confirming the paired
test fails — see the "Testing" section below for the mechanism
(`PinsGlobalCache` trait).

Scope: every cache read/write in `app/`, `app-modules/`, plus the cache-related
config of the two packages that cache on our behalf (stancl/tenancy,
spatie/laravel-permission).

Related rules: `.claude/rules/tenant-caching.md` (existing findings — this plan
extends them), `.claude/rules/auth-guards.md`.

---

## Inventory

Every cache call site in application code:

| Location | Key | Store | TTL | Invalidated? |
|---|---|---|---|---|
| `app/Actions/Queries/FindUserByGlobalId.php:19` | `central_model` / `tenant_model:{tenant}` tagged `user:{globalId}` | `global_cache()` | forever | **no** |
| `app/Actions/Queries/GetTenantsByGlobalId.php:19` | `tenants` tagged `user:{globalId}` | `global_cache()` | forever | partially (`Membership::saved` only) |
| `app/Models/Central/Tenant.php:130` | `primary_domain` tagged `tenant:{id}` | `global_cache()` | forever | **no** |
| `app/Models/Central/Membership.php:54` | forget `tenants` | `global_cache()` | — | `saved` only |
| `app/Models/Central/PaymentPlan.php:164` | `billing:popular_plan_id` | `Cache::` (tenant-scoped in tenant ctx) | 5 min | TTL only |
| `app/Actions/Chat/UpdateUserStatus.php:27` | `chat:status:{userId}` | `Cache::` | 5 min | never read |
| `app/Models/Tenant/User.php:128` | `chat:presence:{userId}` | `Cache::` (read) | — | n/a |
| `resources/views/components/chat/⚡chat-panel/chat-panel.php:80` | `chat:presence:{userId}` | `Cache::` (write) | 90 s | n/a |
| `app/Actions/Tenancy/CreateTenantWithOwner.php:23` | `Cache::lock("tenant-provision:{domain}")` | atomic lock | 10 s | n/a |
| `app/Actions/Billing/Subscriptions/LinkSubscriptionToTenant.php:35` | `Cache::lock("reconcile-subscription:{id}")` | atomic lock | 10 s | n/a |

Package-owned caches:

- `spatie/laravel-permission` — permission/role collection, keyed by
  `PermissionRegistrar::$cacheKey`, swapped per tenant by
  `App\Services\Tenancy\Bootstrappers\SpatiePermissionsBootstrapper`.
- `stancl/tenancy` — `DomainTenantResolver` has caching support but
  `$shouldCache = false` (the package default). Currently unused.

---

## Findings

### F1 — P0: tenant switch does not clear Spatie's in-memory permission collection [FIXED]

`SpatiePermissionsBootstrapper::bootstrap()` swaps `$registrar->cacheKey` and
nothing else. But `PermissionRegistrar` keeps the hydrated collection in a
plain object property and `loadPermissions()` short-circuits on it
(`vendor/spatie/laravel-permission/src/PermissionRegistrar.php:180-183`):

```php
private function loadPermissions(int $retries = 0): void
{
    if ($this->permissions) {
        return;                       // <- cacheKey is never consulted again
    }
    ...
}
```

`PermissionRegistrar` is a singleton. So the *first* tenant to load permissions
in a given PHP process pins its permission and role rows for every tenant
handled later by that same process. `$wildcardPermissionsIndex` has the same
problem, keyed by class + primary key — and tenant user ids are per-database
integers, exactly the collision documented in `tenant-caching.md`.

Under FPM a web request handles one tenant, so this is mostly invisible there.
It bites where one process serves many tenants:

- queue workers — `QueueTenancyBootstrapper` initializes tenancy per job, so a
  worker that runs a job for tenant A then tenant B evaluates B's roles against
  A's rows;
- Octane (this is precisely the case Spatie added
  `clearPermissionsCollection()` for);
- `artisan tenants:*` loops;
- the test suite, which is single-process — meaning a regression test for this
  will be able to reproduce it.

Symptom shape: a user holds a role name that exists in both tenants and gets the
*other* tenant's permission set; or a role that exists only in tenant A appears
to resolve in tenant B. Both fail open.

**Fix.** Clear the in-memory collection on both edges of the swap:

```php
public function bootstrap(Tenant $tenant): void
{
    $this->registrar->cacheKey = 'spatie.permission.cache.tenant.'.$tenant->getTenantKey();
    $this->registrar->clearPermissionsCollection();
}

public function revert(): void
{
    $this->registrar->cacheKey = 'spatie.permission.cache';
    $this->registrar->clearPermissionsCollection();
}
```

`clearPermissionsCollection()` is public and clears `$permissions`,
`$wildcardPermissionsIndex` and `$isLoadingPermissions` **without touching the
cache store** — which is what we want. Do *not* use
`forgetCachedPermissions()`: it also calls `$this->cache->forget($this->cacheKey)`,
so it would evict a tenant's cached permission payload on every context switch
and turn the cache into a permanent miss.

### F2 — P0: removing a membership does not revoke tenant access [FIXED]

`Membership::booted()` hooks `saved` only:

```php
static::saved(function (self $membership) {
    global_cache()->tags(['user:'.$membership->global_user_id])->forget('tenants');
});
```

`GetTenantsByGlobalId` is `rememberForever`. `User::canAccessTenant()`
(`app/Models/User.php:92`) reads it, and `Authenticate::authenticate()`
(`app/Http/Middleware/Authenticate.php:52`) uses that result to *auto-log-in the
tenant guard*:

```php
if ($centralUser->canAccessTenant(tenant())) {
    ...
    LoginUser::run(user: $centralUser, guard: 'tenant');
}
```

So deleting a `Membership` row leaves the removed user with panel access to that
tenant indefinitely — until Redis evicts the key, which for a `forever` entry
means memory pressure or a manual flush. `Tenant` deletion has the same hole
from the other side: it detaches memberships, but nothing clears the cached
`tenants` collection of each member, so a deleted tenant keeps appearing in the
tenant switcher (`User::getTenants()`).

**Fix.** Hook `deleted` as well, and invalidate every member on tenant deletion.
Prefer a single named invalidator over inline closures so both models and any
future call site agree on the key:

- `App\Actions\Cache\ForgetUserTenants` (`__invoke(string $globalId): void`) —
  forgets `tenants` under tag `user:{globalId}`.
- `Membership::booted()` → `static::saved(...)` **and** `static::deleted(...)`.
  Note `TenantPivot` delete goes through the pivot model only when
  `->using(Membership::class)` is set, which it is (`Tenant::users()`), so
  `detach()` fires model events — verify this in the test rather than assuming.
- `Tenant::booted()` → `static::deleting(fn ($t) => $t->users()->pluck('global_id')->each(...))`.
  Must run on `deleting`, not `deleted`, so the pivot rows are still readable.

### F3 — P1: `FindUserByGlobalId` caches a model forever with no invalidation [FIXED]

```php
return global_cache()->tags(['user:'.$globalId])
    ->rememberForever($this->cacheKey($context), fn () => $model::query()->where('global_id', $globalId)->first());
```

Nothing anywhere forgets `central_model` or `tenant_model:*`. Consequences:

- `LoginUser::run()` (`app/Actions/Auth/LoginUser.php:81`) authenticates the
  cached instance, so a soft-deleted, renamed, or `is_bot`-flipped user is
  logged in with pre-change attributes;
- `Authenticate` compares `$tenantUser->is_bot` against a possibly stale value —
  the same field the `tenant-caching.md` incident turned on;
- a serialized Eloquent model carries whatever relations were loaded at write
  time, so those are stale too.

**Fix**, two parts:

1. **Invalidate.** Add `saved` + `deleted` hooks on `App\Models\Central\CentralUser`
   and `App\Models\Tenant\User` that forget the relevant key(s) under tag
   `user:{global_id}`. For `Tenant\User` the key must carry the tenant, matching
   `FindUserByGlobalId::cacheKey()` — extract that method into a shared
   `App\Support\Cache\UserCacheKeys` (or make it a public static on the action)
   so the writer and the invalidator cannot drift. Drift here is silent.
2. **Stop caching model instances; and stop using `forever`.** Cache the
   attribute array and rehydrate with `newFromBuilder()`, or cache nothing and
   rely on F6/F7 to remove the pressure that motivated this cache. Either way
   put a bounded TTL (e.g. 1 h) behind the explicit invalidation as a backstop —
   `forever` means one missed invalidation path is permanent. Apply the same TTL
   change to `GetTenantsByGlobalId` and `Tenant::primaryDomain()`.

   Note `rememberForever` stores `null` but never returns it as a hit
   (`Repository.php:621` checks `! is_null($value)`), so lookup misses are
   re-queried rather than cached — a null result is *not* poisoned. Only
   non-null staleness is a live bug. Worth stating because it looks like a bug
   and isn't.

### F4 — P1: `Tenant::primaryDomain()` is cached forever, never invalidated [FIXED]

```php
return global_cache()->tags(["tenant:{$this->id}"])
    ->rememberForever('primary_domain', fn () => $this->domains()->orderByDesc('created_at')->limit(1)->first());
```

`Domain` has no invalidation hook. Adding a newer domain is supposed to change
the answer (`orderByDesc('created_at')`) and does not; deleting the primary
domain leaves a cached model pointing at a row that no longer exists. Callers
include outbound URLs, so a stale value ships in email:
`app/Notifications/Auth/VerifyEmail.php:55`,
`app/Http/Controllers/Socialite/Login.php:129`,
`resources/views/layouts/⚡header.blade.php:109`,
`resources/views/pages/tenant/⚡mine.blade.php:169-177`.

**Fix.** `Domain::booted()` → `saved` + `deleted` forget `primary_domain` under
tag `tenant:{$domain->tenant_id}` (use the foreign key, not `$domain->tenant`,
to avoid a lazy load during a delete event).

### F5 — P1: `tags()` is unsupported on the default cache store [FIXED — option (b)]

`config/cache.php:20` is `'default' => env('CACHE_STORE', 'database')`.
`Illuminate\Cache\DatabaseStore` and `FileStore` implement `Store` directly —
they do **not** extend `TaggableStore`. Only `redis`, `array`, `memcached`,
`dynamodb` and `apc` do.

This repo works because `.env` sets `CACHE_STORE=redis` and `phpunit.xml` sets
`array`. But all four tagged call sites (F1–F4's neighbours) throw
`BadMethodCallException: This cache store does not support tagging.` the moment
someone deploys with the config default, or with `file`. For a starter kit that
is a first-five-minutes failure in code paths as central as login.

**Fix — pick one, decide before touching F2/F3/F4** since it changes what the
invalidators look like:

- **(a) Require a taggable store.** Change the config default to `redis` and add
  a boot-time assertion (an `AppServiceProvider` check, or an `about` /
  `health` entry) that fails loudly with an actionable message. Cheapest, keeps
  tag-based flush.
- **(b) Drop tags; use composite keys.** `user:{globalId}:tenants`,
  `user:{globalId}:central_model`, `tenant:{id}:primary_domain`. Works on every
  store. Costs the ability to flush a whole tag at once — which we currently
  never do; every existing invalidation is a single `forget()` of a known key.
  **Recommended.** Tags are buying nothing here and are the only reason the
  code is store-dependent.
- (c) Keep tags but wrap every call in a taggable-store check. Rejected — two
  code paths per call site, and the untagged path has no invalidation story.

**Delivered.** `App\Support\Cache\CacheKeys` holds all three key builders
(`userTenants`, `userModel`, `tenantPrimaryDomain`); every F1–F4 read and
invalidation goes through it. `App\Actions\Cache\ForgetUserTenants` is the one
place that forgets a user's tenant list — `Membership` (`saved`+`deleted`) and
`Tenant` (`deleting`) both call it instead of touching `global_cache()`
directly. `tests/Concerns/PinsGlobalCache` replaces the copy-pasted
`$this->app->singleton('globalCache', ...)` line so future tests get it in one
line. `FindUserByGlobalId` now caches `getAttributes()` and rehydrates via
`newFromBuilder()` rather than caching the model instance, closing the
stale-eager-relation trap for free.

### F6 — P2 opportunity: enable stancl's cached domain resolver [FIXED]

`DomainTenantResolver::$shouldCache` is `false`, so **every request to a tenant
subdomain runs a `whereHas('domains')` lookup with an eager `with('domains')`
against the central DB** before anything else happens. That is a guaranteed
2-query floor per request, on the hot path, for data that changes ~never.

Enabling it is a config-level change plus wiring the invalidation traits the
package already ships:

1. In `AppServiceProvider::boot()` (or a small `TenancyCacheServiceProvider`):
   `DomainTenantResolver::$shouldCache = true;` and a TTL
   (`$cacheTTL`, default 3600 s — fine).
2. `App\Models\Central\Tenant` → `use Stancl\Tenancy\Database\Concerns\InvalidatesResolverCache;`
   `App\Models\Central\Domain` → `use Stancl\Tenancy\Database\Concerns\InvalidatesTenantsResolverCache;`
   Neither base model includes them; both hook `saved` + `deleting`.

**The trap that makes this non-trivial.** `CachedTenantResolver::__construct()`
does `$this->cache = $cache->store(static::$cacheStore)`, resolving `cache` from
the container. `CacheTenancyBootstrapper` swaps that binding for
`Stancl\Tenancy\CacheManager`, whose `__call()` forwards *everything* through
`->tags(['tenant'.$key])`. Naming a specific store does not escape it — the
manager itself is tenant-aware. So if the resolver is constructed while tenancy
is already initialized (a queue worker under `QueueTenancyBootstrapper`, a
tenant-context console command), the resolver's cache is tenant-prefixed, and
`invalidateCache()` called from a *different* context writes to a different
namespace than `resolve()` read from. Result: a domain change that appears not
to take effect, intermittently. This is the same un-prefixed-store requirement
that `global_cache()` exists to satisfy.

Mitigation: subclass and pin the store —

```php
final class CachedDomainTenantResolver extends DomainTenantResolver
{
    public function __construct(CacheManager $cache) // Illuminate\Cache\CacheManager, concrete
    {
        $this->cache = $cache->store(static::$cacheStore);
    }
}
```

then bind it in place of `DomainTenantResolver` so `InitializeTenancyByDomain`'s
constructor injection and the invalidation traits (which resolve
`CachedTenantResolver` implementations out of the container) both get the pinned
instance. Verify with a test that a `Domain` update in central context
invalidates a key written in tenant context.

Sequence this **after** F5, because the answer to F5 tells us whether an
un-prefixed-store helper already exists to reuse.

### F7 — P2 opportunity: `UpdateUserLastSeenMiddleware` writes on every request [FIXED]

```php
if ($user = GetAuthenticatedUser::run()) {
    $user->updateQuietly(['last_seen_at' => now()]);
}
```

One `UPDATE` per authenticated request per tenant, including asset and polling
requests. `User::isOnline()` (`app/Models/User.php:74`) only asks whether
`last_seen_at` is within 5 minutes, so second-level precision is not used by
anything.

**Fix.** Throttle with a cache marker — this is a cache *opportunity* that
removes writes rather than reads:

```php
$key = "last-seen:{$user->getKey()}";

if (Cache::add($key, true, now()->addSeconds(60))) {
    $user->updateQuietly(['last_seen_at' => now()]);
}
```

`Cache::add()` is atomic, so concurrent requests collapse to one write. In
tenant context `Cache::` is already tenant-tagged, so the key needs no tenant
component — but if F5 lands as option (b) and we move off tags, make the tenant
explicit. 60 s against a 5-minute window keeps `isOnline()` exact.

Cuts writes on a chatty page by ~1-2 orders of magnitude. Worth measuring before
and after with Debugbar/Telescope on the chat page, which polls.

### F8 — P2: central billing data cached in a tenant-scoped store, plan lists uncached [FIXED]

`PaymentPlan::popular()` uses the `Cache` facade. In tenant context that is
`Stancl\Tenancy\CacheManager`, so `billing:popular_plan_id` is written under tag
`tenant{id}` — a *central* aggregate (`Subscription` grouped by plan) recomputed
and stored once per tenant. The query is a full group-by over `subscriptions`.

Meanwhile the plan catalogue itself is uncached:
`EloquentPaymentPlanRepository::all()` / `available()` / `findBySlug()`
(`app/Services/Billing/Plans/EloquentPaymentPlanRepository.php:16,21,33`) hit the
DB on every pricing-page and checkout render, for rows that change when an
operator edits them in Filament.

**Fix.**
- `popular()` → `global_cache()` (or the composite-key helper from F5), so one
  computation serves all tenants. Keep the 5-minute TTL.
- Cache `available()`/`all()` in `EloquentPaymentPlanRepository` behind
  `global_cache()`, invalidated from `PaymentPlan::booted()` (`saved`/`deleted`)
  and `PaymentPlanFeature::booted()` — features are rendered with the plan, so a
  feature edit must bust the plan payload too. Cache arrays, not models, so
  Filament edits can't resurrect a stale instance.
- Leave `ConfigPaymentPlanRepository` alone; config is already in memory and
  `config:cache` covers it.

### F9 — P3: dead cache write [FIXED]

`UpdateUserStatus` writes `chat:status:{$user->id}` (5 min) and nothing reads it —
the only chat cache reads are `chat:presence:*`. The same data is persisted on the
user row and broadcast via `UserStatusChanged` in the same method. Delete the
write, or if it was meant to back a read path, wire that read. Dead cache writes
are how the *next* person concludes the value is authoritative.

---

## Cross-cutting recommendation

Five of nine findings are the same shape: an ad-hoc `global_cache()`/`Cache::`
call with a hand-built key, `forever`, and invalidation either absent or written
in a different file from the read. Fix the shape, not just the instances:

- **One place that owns cache keys.** `App\Support\Cache\CacheKeys` with static
  methods (`userTenants($globalId)`, `userModel($globalId, $context)`,
  `tenantPrimaryDomain($tenantId)`, `paymentPlans()`). Every read and every
  invalidation goes through it. The `FindUserByGlobalId::cacheKey()` tenant
  suffix is exactly the kind of thing that must not be re-derived by hand in a
  second file.
- **No `rememberForever` for anything derived from a mutable row.** Bounded TTL
  behind explicit invalidation; `forever` only for genuinely immutable data.
- **Cache arrays/ids, never Eloquent models.** Already noted in
  `tenant-caching.md` — a cached model produces no query, so "which row did it
  load?" turns up nothing, and it drags stale relations along.
- **Anything derived from a tenant database carries the tenant in its key.**
  Existing rule; F1 shows the same rule applies to package-owned in-memory
  state, not just to our cache calls.

---

## Testing

The existing `tests/Feature/Actions/Queries/FindUserByGlobalIdTest.php` shows the
required setup — `CACHE_STORE=array` makes `global_cache()` inert because
`globalCache` is a `bind`, so each call builds a fresh `ArrayStore` and nothing
is read back. Every test below needs:

```php
$this->app->singleton('globalCache', fn ($app) => new CacheManager($app));
```

Without it these tests pass against the broken code. Consider promoting that
line to a `Tests\Concerns\PinsGlobalCache` trait rather than copying it a sixth
time.

| Finding | Test |
|---|---|
| F1 | Two tenants with divergent role/permission sets; in one process, resolve permissions in tenant A, then `run()` in tenant B and assert B's set. Must fail before the fix — the suite is single-process, so it will. |
| F2 | Create membership, assert `canAccessTenant()` true, `detach()`/delete, assert false. Second case: delete tenant, assert it leaves `getTenants()`. |
| F3 | Cache a user, rename it via a second query, assert `FindUserByGlobalId` returns the new name. |
| F4 | Cache `primaryDomain()`, add a newer domain, assert the new one is returned; delete it, assert null. |
| F5 | If option (b): assert the actions work with `CACHE_STORE=file` (or any non-taggable store) — the point of the change. If option (a): assert the boot assertion throws with a non-taggable store. |
| F6 | Resolve a tenant by domain twice, assert one query (`DB::listen` count). Then: update the `Domain` from central context and assert re-resolution sees it — this is the prefixed-store trap, so assert it explicitly. |
| F7 | Two requests inside the throttle window produce one `UPDATE`; a request after it produces a second. |
| F8 | `available()` twice = one query; editing a `PaymentPlan` or a `PaymentPlanFeature` busts it. |

Per `.claude/rules/testing.md`: run touched files only while iterating, serially,
never `--parallel`. Baseline any suspicious failure against `git stash` first —
~27 failures are pre-existing.

---

## Sequencing

Phase 1 — correctness, ship first, each independently revertable:
1. **F5 decision** (recommend option (b), composite keys) — it dictates the
   shape of every invalidator below, so decide before writing them.
2. **F1** permission registrar. Smallest diff, largest blast radius. Standalone.
3. **F2** membership/tenant invalidation. Security-relevant.
4. **F3 + F4** user-model and primary-domain invalidation; introduce `CacheKeys`
   here, drop `forever` for bounded TTLs.

Phase 2 — performance:
5. **F7** last-seen throttle. Self-contained, immediately measurable.
6. **F8** billing plan caching + move `popular()` to the global store.
7. **F6** cached domain resolver. Last in the phase: biggest per-request win but
   the most subtle failure mode, and it wants the Phase 1 store decision settled.

Phase 3 — hygiene:
8. **F9** delete the dead `chat:status:` write.
9. Extract `CacheKeys` fully if not already; extract `PinsGlobalCache` test trait.
10. Update `.claude/rules/tenant-caching.md`: Spatie's in-memory collection
    survives a tenant switch; non-taggable default store; `rememberForever`
    doesn't poison null; the resolver-cache prefixed-store trap. Add a line to
    `.claude/rules/INDEX.md` if a new topic file is warranted.

## Deliberately out of scope

- Response/fragment caching for Livewire views — different problem, needs its own
  measurement pass.
- `config:cache` / `route:cache` / `view:cache` in deploy — deployment concern,
  not application caching.
- The two `Cache::lock()` sites. They are correctness locks, not caches, and
  `.claude/rules/tenant-provisioning.md` is explicit that they must stay.

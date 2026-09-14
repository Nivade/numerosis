---
paths:
  - 'src/Cache/**'
  - 'src/Providers/TenancyServiceProvider.php'
---
# Tenant Caching

> **This file is written against stancl/tenancy v3's tag-based isolation,
> which is the only version this package supports.** It does *not* need the
> rewrite the package-split plan once scheduled for it: dev-master has no
> `CachePrefixingBootstrapper` — it keeps `CacheTenancyBootstrapper` and
> *adds* `CacheTagsBootstrapper` / `DatabaseCacheBootstrapper`, with
> `tenancy.cache.prefix` and `tenancy.cache.stores` beside the existing
> `tag_base`. Verify against `src/Bootstrappers/` and `assets/config.php` in
> a real checkout before changing anything here on the eventual v4 port; see
> `.ai/rules/stancl-tenancy-v4.md`.

- **Nothing in `src/` calls `global_cache()` any more — go through `Nvade\Numerosis\Cache\GlobalCache::store()`.** dev-master declares the helper `global_cache(): mixed` (v3 does not), so every `global_cache()->remember(...)` read as `Cannot call method remember() on mixed` at level 9 there — ten call sites, no defect at any of them. **Kept after the v3-only cut**, since the narrowing is correct on v3 too and it is one fewer thing for the eventual port to redo. `GlobalCache::store()` narrows once and returns an `Illuminate\Contracts\Cache\Repository`. Note the branch that actually runs is the **`Factory`** one: stancl binds `globalCache` to a `CacheManager`, which implements `Factory` and *not* `Repository`, so every historical `global_cache()->remember()` reached the default store through `CacheManager::__call()` — which is exactly what `->store()` returns, hence no behaviour change. Everything the bullet below says about the binding still applies unchanged; only the accessor moved.

- **`global_cache()` is not tenant-scoped, and the name does not say so.**
  Despite living next to stancl's tenant-aware `CacheManager`, the `globalCache`
  binding in `Stancl\Tenancy\TenancyServiceProvider` points at
  `Illuminate\Cache\CacheManager` — no tenant tag, no tenant prefix. Anything
  stored through it is visible to every tenant. That is the point of the helper
  (central data that must survive `CacheTenancyBootstrapper`'s prefixing), but
  it means **any value derived from a tenant database must carry the tenant in
  its cache key**. `FindUserByGlobalId` did not: it cached the tenant-context
  `Tenant\User` under `tenant_model` tagged `user:{globalId}`, so the first
  tenant a user visited pinned their row for every other tenant.

  The failure is silent and severe because tenant user ids are per-database
  integers. Visiting `broodjes` (where the user is id 2) then `pookie` (where id
  2 is the seeded Chat Bot) authenticated the session as the *bot*: navigation
  items vanished (bot holds no roles), `UpdateUserLastSeenMiddleware` wrote
  `last_seen_at` onto the bot row, and `broadcasting/auth` handed out presence
  as `Chat Bot`. The tell in Telescope is a request that resolves a user with
  **zero `select … from users`** queries — a cached Eloquent model, not a guard
  retrieval.

- **The tenant guard's session key is shared by every tenant subdomain.**
  `SESSION_DOMAIN=.nvade.dev`, so one session spans `*.nvade.dev`, and
  `SessionGuard` stores nothing but a primary key. The id written on tenant A is
  read as a *different person* on tenant B. `Nvade\Numerosis\Http\Middleware\Authenticate`
  repairs this on panel routes only (it compares `global_id` against the central
  guard); routes wired to the framework's `auth:tenant` — `broadcasting/auth`,
  `routes/tenant.php` — had no such check and would authenticate whoever holds
  that id in the current tenant. `Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant`
  now fails the mismatch closed by forgetting the tenant guard's session key and
  recaller cookie whenever the session's recorded tenant changes. It must be
  registered **after** `StartSession` in every stack that can reach a tenant
  route; tenancy identification is already forced ahead of it by
  `TenancyServiceProvider::makeTenancyMiddlewareHighestPriority()`.

- **`CACHE_STORE=array` makes `global_cache()` inert, so cross-tenant cache bugs
  cannot reproduce in tests by default.** `globalCache` is `bind`, not
  `singleton`, so every `global_cache()` call constructs a fresh `CacheManager`
  and therefore a fresh `ArrayStore`; nothing written is ever read back. A test
  that needs the production behaviour has to pin one manager first:

  ```php
  $this->app->singleton('globalCache', fn ($app) => new CacheManager($app));
  ```

  Without that line a regression test for this class of bug passes against the
  broken code. The pin now lives in `tests/Concerns/PinsGlobalCache`; see
  `tests/Feature/Actions/Queries/FindUserByGlobalIdTest.php`.

- **Caching Eloquent models is what makes these bugs invisible.** A cached
  model produces no query, so the usual "which row did it load?" debugging
  turns up nothing at all. `FindUserByGlobalId` now caches
  `getAttributes()` and rehydrates through `newFromBuilder()`, which also
  drops the eager-loaded relations a cached instance would otherwise carry
  (and which no invalidation hook watches). Cache arrays and ids, never
  models.

- **Every cache key lives in `Nvade\Numerosis\Cache\CacheKeys`, and nothing
  rebuilds one by hand.** The keys that caused the incidents above were
  string-interpolated at each call site, so a read and its invalidation were
  written in different files and only agreed by luck —
  `FindUserByGlobalId`'s tenant suffix is exactly the part that is easy to
  get subtly wrong twice. `CacheKeys` also documents which keys are
  tenant-scoped (`Cache::`, prefixed by Stancl's manager) versus global
  (`global_cache()`, not prefixed at all), which is the distinction the whole
  file exists to protect. `userModel()` takes an `Nvade\Numerosis\Enums\Tenancy\Context`
  rather than a `'central'`/`'tenant'` string, so a typo is a type error.

- **`rememberForever` is gone from anything derived from a mutable row.**
  Every cached value now has a bounded TTL *and* an explicit invalidator
  (`CentralUser`/`Tenant\User`/`Domain`/`PaymentPlan` `booted()` hooks,
  `ForgetUserTenants`). The TTL is the backstop for an invalidation path
  nobody thought of; the invalidator is what makes the common case correct.
  `forever` is only defensible for genuinely immutable data.

- **`DomainTenantResolver` gets its own `CacheManager`, and must stay a
  singleton.** `TenancyServiceProvider::registerCachedDomainResolver()` builds
  `new CacheManager($app)` rather than resolving the container's, which becomes
  tenant-scoped inside tenant context: a resolver built there writes to one
  namespace while invalidation clears another, so a domain change appears not
  to take effect. It is a `singleton` so the resolver and its invalidators
  share one store; rebinding it as `bind` reintroduces the same split. The
  `new` is needed because `CachedTenantResolver::__construct()` type-hints
  `Contracts\Cache\Factory` on `stancl/tenancy` v3 and never resolves
  `globalCache` itself.

- **`cache.serializable_classes` silently disables the resolver cache, and the
  symptom looks like a tenancy bug.** `DomainTenantResolver` caches a whole
  tenant *model*, and a fresh Laravel app ships `cache.serializable_classes`
  as `false` (hardening against gadget chains). The read does not fail: it
  returns `__PHP_Incomplete_Class` with no exception and no log line. The first
  request after a cache clear resolves fine (a miss) and every request after it
  dies on `DomainTenantResolver::resolved(): Argument #1 ($tenant) must be of
  type Tenant, __PHP_Incomplete_Class given`, which is two config defaults
  disagreeing. `TenancyRouting::shouldCacheResolvedTenants()` therefore
  follows what the host's cache config can actually store — an allowlist has to
  name the tenant model, `false` disables the cache, and
  `numerosis.tenancy.cache_resolved_tenants` overrides either way — and
  `numerosis:install`'s `verifyTenantResolverCache()` reports when that has
  turned the cache off, since losing it costs a central lookup per tenant
  request. `null` is Laravel's "no restriction" value: the stores only pass
  `allowed_classes` to `unserialize()` when it is non-null.

- **"Cache arrays and ids, never models" had three live violations, found by
  auditing every call site rather than by a failure.**
  `EloquentPaymentPlanRepository::available()` cached an
  `Eloquent\Collection<PaymentPlan>` with `features` eager-loaded,
  `Tenant::primaryDomain()` cached a `Domain`, and `GetTenantsByGlobalId`
  cached `Collection<Tenant>`. All three carried the same two faults: a
  relation frozen at write time that no invalidator watched end to end, and
  the `cache.serializable_classes` trap below with nothing defending them —
  a host whose allowlist omits those classes reads `__PHP_Incomplete_Class`
  back with no exception and no log line. `available()` now caches attribute
  rows plus a parallel array of feature rows and rehydrates through
  `newFromBuilder()` + `setRelation()`, `primaryDomain()` caches attributes and
  rehydrates against `Numerosis::model(Domain::class)`, and
  `GetTenantsByGlobalId` caches tenant ids behind its existing per-request
  memo. The rule is not self-enforcing: nothing goes red when a new call site
  breaks it, so the review question is "what class comes back out of this
  key?"

- **`Cache::lock` is tenant-prefixed inside tenant context, and the rest of
  this file only documents the reads.** `LinkSubscriptionToTenant` and
  `WebhookController` take one lock name, `reconcile-subscription:{id}`, and
  that name is what makes the checkout redirect and the Stripe webhook
  mutually exclusive. It held only because provisioning happens to be
  dispatched from a central request in the shipped configuration: a host that
  inserts a provisioning step leaving tenancy on, or dispatches from tenant
  context, splits one lock into two namespaces and the symptom is a duplicated
  subscription row under load. All three lock sites now go through
  `GlobalCache::lock()`, which takes the lock off the explicitly non-tenant
  store.

- **Resolver caching is per resolver class, and path mode had none for its
  whole existence.** `PathTenantResolver::$shouldCache` is a separate static
  on a separate class from `DomainTenantResolver`'s, defaults to `false`, and
  nothing set it — so every `IdentificationMode::Path` request ran
  `tenancy()->find($id)` against the central connection, the exact cost
  `numerosis:install`'s `verifyTenantResolverCache()` warns about losing for
  domain mode. `registerCachedPathResolver()` now mirrors the domain-mode
  registration, singleton and explicit `new CacheManager($app)` included.
  Stancl's own key shape is broken here as well:
  `CachedTenantResolver::getCacheKey()` json-encodes whatever `resolve()` was
  handed, which for this resolver is a `Route`, while `getArgsForTenant()`
  hands invalidation `[$tenant->id]` — so nothing cached was ever forgotten.
  `PreservingPathTenantResolver::getCacheKey()` overrides it to key on the id
  either way.

- **Negative caching: `null` is cached for `tenantPrimaryDomain()` and
  deliberately not for `FindUserByGlobalId`.** `Repository::remember()` never
  stores `null`, so a lookup that finds nothing re-queries on every call. For
  `primaryDomain()` that is every call in path mode, where no tenant has a
  domain row at all, and `DomainObserver` already busts the key when one is
  added — so it stores `false` and carries no staleness. For
  `FindUserByGlobalId` a sentinel would make a user created moments after a
  failed lookup invisible for the TTL, and the miss only happens for an
  unknown or deleted `global_id`, which is not the common case on the
  authenticated path. Decided against; do not re-derive it.

- **TTLs and the store are config, not literals.** `numerosis.cache.ttl.*` is
  keyed by the same names `CacheKeys` uses and read through `CacheTtl`, never
  at the call site. A `null` TTL means "do not cache" and must bypass
  `remember()` rather than pass `0`, which several drivers read as forever —
  `GlobalCache::remember()` is where that happens. `numerosis.cache.store`
  routes every global write; `GlobalCache::store()` memoizes on the container
  *and* the store name, or a changed setting never takes effect.

- **The `tenants` column listing is cached, and a migration is its
  invalidator.** `getCustomColumns()` runs on every tenant hydration and every
  save, so a cold static cost a schema round-trip per request and one per row
  on a list page. `ForgetTenantColumnListing` listens for `MigrationsEnded`,
  which is the only thing that changes that schema — a host adding a column
  therefore has nothing to remember. A miss taken before the table exists is
  never cached, or the first request after `migrate` reads an empty list.

- **A cache write nobody reads is worse than no cache.** `UpdateUserStatus`
  wrote `chat:status:{id}` for months; the only chat reads are
  `chat:presence:*`, and the same data was already on the user row and in the
  `UserStatusChanged` broadcast. It was deleted rather than wired to a read
  path. A dead write is how the next person concludes the cache is
  authoritative and starts trusting it.

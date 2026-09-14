# Cache audit

**Status: not executed.** Written 2026-09-14, from an audit of every
`GlobalCache`/`Cache::` call site in `src/`. Ten phases: 1–3 fix what is
already cached, 4–7 add caching where a hot path has none, 8–10 are
utilization and documentation.

Phases are independent and can land in any order, with one exception noted in
Phase 8. Phase 1 is the only one that can break a host silently today.

Relationship to `findings-cleanup.md` Phase 1: that phase *adds*
`SubscriptionObserver` to invalidate `CacheKeys::popularPaymentPlanSlug()`,
and it is correct — a deleted or renamed plan leaves a cached slug resolving
to nothing, which no TTL fixes. Phase 8 below does not undo it; it removes the
stampede that aggressive invalidation creates.

## Phase 1 — stop caching Eloquent models

`.ai/rules/tenant-caching.md` ends with "Cache arrays and ids, never models",
learned from `FindUserByGlobalId`. Three of the five cached values still store
models:

| Site | Cached value |
|---|---|
| `Services/Billing/EloquentPaymentPlanRepository::available()` | `Eloquent\Collection<PaymentPlan>` with `features` eager-loaded |
| `Models/Central/Tenant::primaryDomain()` | a `Domain` model |
| `Actions/Queries/GetTenantsByGlobalId::handle()` | `Collection<Tenant>` |

Two problems, one shared. The eager-loaded relation is baked into the cache at
write time and no invalidator watches it end to end. And the
`cache.serializable_classes` trap that `TenancyRouting::shouldCacheResolvedTenants()`
exists to defend against applies to all three with nothing defending them: a
host with an allowlist that does not name `PaymentPlan`, `Domain` or the
tenant model gets `__PHP_Incomplete_Class` back with no exception and no log
line, exactly as documented for `DomainTenantResolver`.

- `available()`: cache `$plans->map->getAttributes()` plus a parallel array of
  each plan's feature rows, rehydrate through `newFromBuilder()` and
  `setRelation('features', …)`. If that shape gets ugly, cache the plan ids
  and re-query — the catalogue is small and the query is indexed.
- `primaryDomain()`: cache the domain's attributes, rehydrate the same way.
  `Domain` is resolved through `Numerosis::model()`, so rehydrate against the
  resolved class, not the package one.
- `GetTenantsByGlobalId`: cache the list of tenant ids; resolve to models
  behind the existing per-request memo so a page reading it repeatedly still
  pays one query. This is where `Tenant::getCustomColumns()` (Phase 5) is hit
  hardest, so land Phase 5 first if both are in the same session.

**Tests:** `tests/Feature/Models/Central/TenantPrimaryDomainCacheTest.php` and
the payment-plan cache tests already cover hit/miss; add a case to each that
sets `cache.serializable_classes` to an allowlist naming nothing relevant and
asserts the value still comes back as the right class. Compose
`PinsGlobalCache` or the array store makes the whole thing vacuous. Make each
new assertion fail once against current code first
(`.ai/rules/testing.md`).

## Phase 2 — make the two `Cache::lock` sites store-explicit

`Actions/Billing/Subscriptions/LinkSubscriptionToTenant.php:35` and
`Http/Controllers/Billing/WebhookController.php:97` take the same lock name,
`reconcile-subscription:{id}`, and the docblock says that is what makes the
checkout redirect and the Stripe webhook mutually exclusive.
`WebhookController.php:190` takes `checkout-settle:{id}` alone.

Checked: `LinkTenantSubscription` runs as its own queued chain link, the
earlier steps restore context through `RunsInTenant`'s `finally`, and
provisioning is dispatched from a central request — so tenancy is not
initialized there in the shipped configuration and both locks land in the same
namespace. But `Cache::` resolves stancl's prefixing manager whenever tenancy
*is* initialized, so this holds by circumstance, not by construction: a host
inserting its own provisioning step that leaves tenancy on, or dispatching
provisioning from tenant context, silently splits the lock in two and the
symptom is a duplicated subscription row under load.

- Take both locks off an explicitly non-tenant store rather than the `Cache`
  facade. `GlobalCache::store()` is already the package's "central, no tenant
  prefix" accessor — give it a `lock(string $name, int $seconds)` passthrough
  and route all three call sites through it.
- Note in `.ai/rules/tenant-caching.md` that `Cache::lock` is tenant-prefixed
  inside tenant context; the file documents the reads and not the locks.

**Tests:** a test that initializes tenancy, takes the lock through the new
accessor, and asserts a central-context attempt at the same name blocks.
`tests/Feature/Cache/GlobalCacheTest.php` is the home.

## Phase 3 — a real cache config surface

`config/numerosis.php`'s `cache` key holds `prefix` and nothing else, while
five call sites hardcode a TTL (`now()->addHour()` ×4, `addMinutes(5)` ×1) and
every write rides the default store.

- Add `numerosis.cache.ttl`, keyed by the same names `CacheKeys` uses
  (`user_tenants`, `user_model`, `tenant_primary_domain`,
  `available_payment_plans`, `popular_payment_plan_slug`), each an integer of
  seconds, `null` meaning "do not cache".
- Add `numerosis.cache.store`, `null` meaning the default store.
  `GlobalCache::store()` is the single chokepoint that reads it — it already
  memoizes per container, so the memo key needs the store name in it.
- Give `CacheKeys` a sibling `CacheTtl` (or a `ttl()` method per key, matching
  the existing static-per-key shape) so a call site never reads config
  directly. A `null` TTL must skip the `remember()` entirely, not pass `0`.

**Tests:** one test per key asserting a configured `null` TTL produces a fresh
query every call; one asserting `numerosis.cache.store` routes writes to a
named store.

## Phase 4 — cache tenant resolution in path mode

`TenancyServiceProvider::registerCachedDomainResolver()` sets
`DomainTenantResolver::$shouldCache`. `PathTenantResolver::$shouldCache` is a
*separate* static on a separate class (`vendor/stancl/tenancy/src/Resolvers/PathTenantResolver.php:16`),
defaults to `false`, and nothing in this package ever sets it. Every
`IdentificationMode::Path` request therefore runs `tenancy()->find($id)`
against the central connection — the exact central lookup per tenant request
that `numerosis:install`'s `verifyTenantResolverCache()` warns about losing
for domain mode.

Mirror the domain-mode registration, including its trap:

- Set `PreservingPathTenantResolver::$shouldCache` from
  `TenancyRouting::shouldCacheResolvedTenants()` in the same `booting()`
  callback (it reads `tenancy.tenant_model`, which `HostConfig::apply()` fills
  in from an earlier `booting()`). The property is inherited from
  `PathTenantResolver`, so assign it on the concrete subclass and confirm
  which class stancl reads it off.
- The current `bind(PathTenantResolver::class, PreservingPathTenantResolver::class)`
  lets the container auto-inject the `Contracts\Cache\Factory`, which *is*
  tenant-scoped inside tenant context — the split-namespace bug
  `registerCachedDomainResolver()`'s docblock describes. Change it to a
  `singleton` constructing `new CacheManager($app)` explicitly.
- Confirm the tenant-saved/deleted invalidation stancl wires for the domain
  resolver also clears the path resolver's key; if it does not, add it to the
  event map in `TenancyServiceProvider::events()`.
- Extend `verifyTenantResolverCache()` to report on whichever resolver the
  host's `IdentificationMode` actually uses, not `DomainTenantResolver`
  unconditionally.

**Tests:** `tests/Feature/Services/Tenancy/PreservingPathTenantResolverTest.php`
and `TenantResolverCacheTest.php` are the homes. Assert a second path-mode
resolution issues no central query, and that a resolver built inside tenant
context reads the same namespace its invalidator clears.

## Phase 5 — cache the `tenants` column listing

`Models/Central/Tenant::introspectedColumns()` calls
`Schema::connection(…)->getColumnListing('tenants')`, memoized in a static
that `Numerosis::resetModelCache()` clears on every boot. Stancl calls
`getCustomColumns()` on every tenant model hydration and save, so this is a
schema round-trip on effectively every request that touches a `Tenant` — and
on every tenant in a list page's collection, once the memo is cold.

- Back the static with a `GlobalCache` entry under a new
  `CacheKeys::tenantCustomColumns()`, long TTL (the schema changes at deploy
  time), keeping the existing static as the per-request layer in front of it.
- Keep the "nothing is memoized while the table is missing" behaviour: a miss
  on a pre-migration boot must not be cached, or the first post-`migrate`
  request reads an empty list.
- Forget the key from `numerosis:install` and document that a host adding a
  `tenants` column outside that command must clear it. A migration-run hook
  would be better if one exists that the package can reach without owning the
  host's migration flow.

**Tests:** new file under `tests/Feature/Models/Central/`. Assert a second
hydration issues no schema query, and that the key is not written when the
table is absent.

## Phase 6 — tenant switcher reads the cached list

`resources/views/layouts/⚡header.blade.php:123` iterates
`$this->user->tenants` — the raw relation, a fresh query — while
`GetTenantsByGlobalId` caches the identical set behind a global key *and* a
per-request memo. Two paths to one fact, one of them cached.

- Route the view through `GetTenantsByGlobalId::run($user->global_id)`.
- Same line: `$tenant->primaryDomain()->getHost()` null-derefs for a tenant
  with no domain row. Guard it, and skip the entry rather than rendering a
  broken link. This is a separate defect noticed in the same audit; it lands
  here because it is the same line.
- Grep for other readers of `->tenants` that should go through the action —
  `⚡mine.blade.php:73` deliberately does not (it eager-loads `subscriptions`
  for the awaiting-payment notice), and that exception should stay and be
  commented as one.

**Tests:** a Livewire/Blade test asserting the header renders one query's
worth of tenants on a warm cache, and renders a tenant with no domain without
erroring.

## Phase 7 — cache the tenant owner's global id

`Tenant::owner()` is a `belongsToMany` with a pivot filter and a `first()` —
a fresh query at five call sites (`WebhookController:361`,
`NotifiesTenantOwnerDirectly`, `MarkTenantProvisioned`, `RestoreTenant`,
`⚡suspended.blade.php`), several of them on request paths.

- Add `CacheKeys::tenantOwnerGlobalId(string $tenantId)`, cache the
  `global_id` only — never the `CentralUser` — and resolve the model through
  `FindUserByGlobalId`, which is already cached and already rehydrates from
  attributes.
- `MembershipObserver` already fires on the mutation that changes this; add
  the forget beside its existing `ForgetUserTenants::run()`. Ownership also
  moves on `Membership` update, so hook `saved` and `deleted`, matching
  `PaymentPlanObserver`'s shape.
- `ownerEmail()` (`Tenant.php:243`) goes through `owner()` and needs no
  separate key.

**Tests:** new file under `tests/Feature/Models/Central/`. Assert the second
call issues no query, and that transferring ownership through a `Membership`
write is visible on the next read.

## Phase 8 — `flexible()` for the two billing keys

`availablePaymentPlans` and `popularPaymentPlanSlug` are both bust-then-herd
shaped: an observer forgets the key, and the next N concurrent requests all
recompute. `popularPaymentPlanSlug` is the worse of the two — a full
`GROUP BY payment_plan_id` scan of central `subscriptions`, invalidated on
every subscription create and delete once `findings-cleanup.md` Phase 1 lands.

- Move both to `Cache::flexible()` (stale-while-revalidate), fresh window from
  the Phase 3 TTL config, stale window a small multiple of it.
- Land this **after** `findings-cleanup.md` Phase 1, not instead of it: the
  invalidator is what makes a deleted plan's slug stop being served, and
  `flexible()` only changes who pays for the recompute.
- Confirm `flexible()` is available on the store the package targets and
  degrades sanely on stores without atomic locks, since `GlobalCache` hands
  back whatever the host configured.

**Tests:** extend `PaymentPlanPopularCacheTest.php` — assert a read inside the
stale window returns the old value and does not block.

## Phase 9 — decide on negative caching

`Repository::remember()` never stores `null`, so `FindUserByGlobalId` and
`Tenant::primaryDomain()` re-query on every call for a row that does not
exist. `FindUserByGlobalId` sits on the authenticated-request path via
`Authenticate` → `canAccessTenant()`, so an unknown or deleted `global_id`
means an uncached central query per request.

Decide, do not default: a sentinel value with a short TTL fixes it, and also
means a user created moments after a failed lookup stays invisible for that
window. The TTL has to be short enough that registration-then-immediate-use
still works. If the answer is "not worth it", record that in
`.ai/rules/tenant-caching.md` so the next audit does not re-derive it.

**Tests:** only if the sentinel lands — assert a miss is cached and that the
observer forget path clears the sentinel too.

## Phase 10 — fold the audit back into the rules

`.ai/rules/tenant-caching.md` is the file that made this audit possible and it
is now behind the code in three places.

- Record that the "never cache models" rule had three live violations and what
  replaced them (Phase 1).
- Record the `Cache::lock` tenant-prefixing trap (Phase 2).
- Record that resolver caching is per-resolver-class and path mode was
  uncached for its whole existence (Phase 4).
- Add the new keys to `CacheKeys`' own inline documentation of which keys are
  global versus tenant-scoped.
- `docs/host-requirements.md` gains the `numerosis.cache.ttl` / `.store` keys
  from Phase 3.

Use `record-rule`, and diff `.ai/rules/index.md` afterwards — it regenerates
the whole table from `paths:` frontmatter and drops the preamble.

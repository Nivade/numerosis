---
topic: stancl-tenancy-v4
updated: 2026-08-31
---

# Port map: stancl/tenancy v3 → dev-master ("v4")

> **This package is v3-only. The dual-version layer described below was
> built, measured, and deliberately deleted on 2026-08-31** (section A of
> `.claude/plans/numerosis-consolidation.md`). What existed: 8
> `Support\Compat\Tenancy\*` shims, `Support\Tenancy\{TenancyVersion,
> TenancyConfigKeys}`, 27 runtime branches across 12 files, 427 LOC of
> hand-written PHPStan reflection stubs that had to mirror real signatures
> exactly, two baselines (~405 entries), three PHPStan configs, and a 4-job
> CI matrix. It delivered exactly one bug — `PreservingPathTenantResolver`
> reading a static property dev-master turned into a static method, in path
> mode, which has no HTTP coverage either way. **It protected no host**: with
> no v4 tag, a host reaches dev-master only through the two root-level
> opt-ins below, which no host has.
>
> **This file is the durable asset and survives deleting all of that code.**
> The symbol map, the config-key map, the signature changes and the three
> "the v4 docs are wrong" corrections are all still accurate. Port as one
> hard cut when stancl tags a release, driven by this file. Every eager
> symbol it names carries an inline `.ai/rules/stancl-tenancy-v4.md`
> pointer at its site in `src/` — grep for that string to find them.
>
> `composer.json` is `"stancl/tenancy": "^3.10"`. CI runs one leg.

Everything here was derived by cloning the branch and diffing it against what
this package actually references — **not** from the v4 docs site, which stancl
themselves mark WIP and which is wrong on at least two points recorded below.
Reproduce with:

```bash
git clone --depth 1 --branch master https://github.com/archtechx/tenancy.git /tmp/v4
grep -rhoE 'Stancl\\Tenancy\\[A-Za-z0-9_\\]+' src/ config/ database/ tests/ routes/ workbench/ | sort -u
# then check each against /tmp/v4/src/<path>.php
```

Measured 2026-08-29 against `archtechx/tenancy@master`, which at that point
was the same commit day as the `v3.10.1` tag (2026-08-05).

## There is no v4 release, and that is not a detail

`composer show stancl/tenancy --all` lists `dev-master, 3.x-dev, v3.10.1, …`.
**No v4 tag, and `dev-master` carries no `branch-alias`**, so the constraint
has to be the literal string `dev-master` — it cannot be written as `^4.0`
or aliased to `4.x-dev`.

Consequence a version-constraint edit alone does not fix: **Composer stability
flags come from the root package only.** A library requiring `dev-master`
is not installable by a host that hasn't opted in. `dev-master` additionally
hard-requires `stancl/jobpipeline: 2.0.0-rc7`, so a host needs *two* opt-ins:

```jsonc
// host composer.json
"minimum-stability": "dev",
"prefer-stable": true,
"require": {
    "stancl/tenancy": "dev-master",
    "stancl/jobpipeline": "2.0.0-rc7"
}
```

**This is the measurement that killed the dual-version layer.** The
constraint was `^3.10 || dev-master`; under it every ordinary host still
resolved to v3.10.1, because only a host writing the block above reaches
dev-master at all. So the second leg cost real maintenance to protect a
population of zero. Anyone reading "numerosis supports v4" as "hosts get v4"
is wrong until stancl tags a release, and no change on this side can alter
that — which is why the answer is to port on the tag, not to carry both.

`dev-master` also adds hard requires this package did not previously pull:
`laravel/tinker`, `laravel/prompts`, `spatie/invade`; and drops
`facade/ignition-contracts`.

## Symbol map — 11 of the 71 symbols this package references moved

Nine of the eleven are reached through `extends`/`implements`/`use <Trait>`,
which resolve **eagerly at class-declaration time** — the exact asymmetry
`.ai/rules/optional-dependencies.md` documents, and the reason those nine
need `Support\Compat`-style conditional-definition shims rather than a
find-and-replace. The other two are `::class` strings and only need the right
class name chosen at the point of use.

| v3 | dev-master | this package's use | eager |
|---|---|---|---|
| `Contracts\SyncMaster` | `ResourceSyncing\SyncMaster` | `Contracts\Auth\CentralUserModel extends` | yes |
| `Contracts\Syncable` | `ResourceSyncing\Syncable` | `Contracts\Auth\TenantUserModel extends`, `Models\User implements` | yes ×2 |
| `Database\Concerns\ResourceSyncing` | `ResourceSyncing\ResourceSyncing` | `use` in `Models\Tenant\User`, `Models\Central\CentralUser` | yes ×2 |
| `Database\Models\TenantPivot` | `ResourceSyncing\TenantPivot` | `Models\Central\Membership extends` | yes |
| `Contracts\TenantWithDatabase` | `Database\Contracts\TenantWithDatabase` | `Models\Central\Tenant implements`, + 5 type-hints | yes |
| `Listeners\UpdateSyncedResource` | `ResourceSyncing\Listeners\UpdateOrCreateSyncedResource` | `Listeners\Tenancy\UpdateSyncedResource extends` | yes |
| `Concerns\HasATenantsOption` | `Concerns\HasTenantOptions` | **none since 2026-09-03** — was `use` in 3 `Console\Commands\*TenantModule`, deleted with the module system | no |
| `Events\SyncedResourceSaved` | `ResourceSyncing\Events\SyncedResourceSaved` | event map + listener param | no |
| `Events\SyncedResourceChangedInForeignDatabase` | `ResourceSyncing\Events\SyncedResourceSavedInForeignDatabase` | event map + `Listeners\Tenancy\LogSyncedResourceChangedInForeignDatabase` | no |
| `Middleware\PreventAccessFromCentralDomains` | `Middleware\PreventAccessFromUnwantedDomains` | 4× `::class` (`NumerosisServiceProvider:384`, `Numerosis:241`, `TenancyServiceProvider:275`, `NumerosisTenantPlugin:182`) | no |
| `UUIDGenerator` | `UniqueIdentifierGenerators\UUIDGenerator` | 1× `::class` in `tests/TestCase.php` | no |

**Version sentinel: `class_exists(\Stancl\Tenancy\Enums\RouteMode::class)`.**
v3 has no `Enums` namespace at all, so this is a clean boolean with no version
string parsing. Do not sniff `composer.lock` or `InstalledVersions` — a
`dev-master` install reports a branch name, not a comparable version.

The other 60 symbols — every `Events\*`, `Jobs\*`, `Database\Models\{Tenant,Domain,ImpersonationToken}`,
`Bootstrappers\*` this package names, `Database\Concerns\{CentralConnection,HasDatabase,HasDomains,InvalidatesResolverCache,InvalidatesTenantsResolverCache}`,
`Features\UserImpersonation`, `Resolvers\DomainTenantResolver`, all five
`Middleware\InitializeTenancyBy*` — are unchanged in name and location.

## Config-key map — the part no `class_exists` shim can reach

A moved config key is invisible to PHPStan and to autoloading; it fails as a
`null` at runtime, usually far from the read. Four keys this package reads
moved, across ~47 call sites:

| v3 | dev-master | sites |
|---|---|---|
| `tenancy.central_domains` | `tenancy.identification.central_domains` | 25 |
| `tenancy.tenant_model` | `tenancy.models.tenant` | 17 |
| `tenancy.domain_model` | `tenancy.models.domain` | 4 |
| `tenancy.id_generator` | `tenancy.models.id_generator` | 1 |

New in dev-master with no v3 equivalent:
`tenancy.identification.{default_middleware,middleware,domain_identification_middleware,path_identification_middleware,resolvers}`,
`tenancy.default_route_mode` (a `Stancl\Tenancy\Enums\RouteMode` case, not a
string), `tenancy.cache.{prefix,stores}`, `tenancy.database.template_tenant_connection`,
`tenancy.database.tenant_host_connection_name`, `tenancy.rls.*`,
`tenancy.pending.*`.

`tenancy.central_user_model` and `tenancy.tenant_user_model` are **this
package's own keys** (`HostConfig::tenancyModels()`), not stancl's — they
appear in no stancl config stub, v3 or v4, and are unaffected by any of this.
The two that do move (`tenant_model`, `domain_model`) are written from that
same method and carry a pointer to this file.

**`HostConfig::apply()` gets more dangerous, not less, under v4.**
`.ai/rules/package-host-bootstrap.md` already records that a multi-segment
dotted `Config::set()` into a namespace another package's `mergeConfigFrom()`
also populates hits `Arr::set()`'s auto-vivification hazard and can truncate
the parent array — that is exactly how `tenancy.database` lost its
`prefix`/`suffix`/`managers`. v4 pushes these keys **one segment deeper**
(`tenancy.identification.central_domains` is three segments under a namespace
stancl still merges into).

**Corrected 2026-09-01.** This paragraph used to point at that rule's own
"Suggested better approach" — read-modify-write the whole parent via
`Config::array($parent, [])` — and say it "stops being optional the moment
`HostConfig` targets v4 keys". That suggestion has since been retracted in
`package-host-bootstrap.md`: the hazard is an *absent* parent, not a scalar
one, and read-modify-write of an absent parent produces the identical
truncated array. What v4 makes non-optional is the replacement guard recorded
there — `HostConfig::set()` asserting every intermediate segment of a
non-`numerosis.*` key already exists as an array, and throwing naming the key
when it does not. One segment deeper means one more chance to truncate
silently, so port the keys and add the assertion in the same change.

## Three things smaller than expected

- **`UpdateOrCreateSyncedResource` keeps the shape this package subclasses.**
  Still `handle(SyncedResourceSaved $event): void`, still
  `public static bool $shouldQueue = false` (set to `true` in
  `TenancyServiceProvider:160`). `Listeners\Tenancy\UpdateSyncedResource`
  ports by swapping its parent and the event's namespace — no rewrite of the
  `$tries = 20` / `$backoff = 20` retry behaviour it exists for.
- **`Stancl\Tenancy\Commands\Seed` is fixed in dev-master.** Both independent
  breaks recorded in `.ai/rules/tenant-provisioning.md` are gone: it now
  sets `$this->signature = 'tenants:seed …'` explicitly and calls
  `specifyParameters()` in its own constructor (guarded on
  `version_compare(app()->version(), '13.24.0', '>=')`, per archtechx/tenancy#1474),
  and composes `Concerns\HasTenantOptions`. `Jobs\SeedTenantDatabase`'s
  resolve-the-seeder-from-the-container workaround stays required for the v3
  path and must not be deleted while `^3.10` is still in the constraint.
- **`JobPipeline` survives.** `assets/TenancyServiceProvider.stub.php` still
  builds `TenantCreated` listeners with `JobPipeline::make([...])`, so the
  `JobPipeline`-vs-`AsAction` calling-convention conflict documented in
  `.ai/rules/tenant-provisioning.md` (and the reason `SeedTenantDatabase`
  stayed a plain `Illuminate` Job) is unchanged. jobpipeline itself goes
  v1 → `2.0.0-rc7`; re-verify `new $job(...$passable)` + `app()->call([$instance, 'handle'])`
  against the rc before trusting that rule verbatim.

## Three things the v4 docs get wrong about this upgrade

- **There is no `CachePrefixingBootstrapper`.** dev-master's
  `src/Bootstrappers/` still contains `CacheTenancyBootstrapper`, and *adds*
  `CacheTagsBootstrapper` and `DatabaseCacheBootstrapper` beside it; config
  gains `tenancy.cache.prefix` and `tenancy.cache.stores` alongside the
  existing `tag_base`. Any plan to rewrite `.ai/rules/tenant-caching.md`
  around "tags were replaced by prefixing" is starting from a false premise —
  read `src/Bootstrappers/` and `assets/config.php` first.
- **`TenantDatabaseManager` gained no `database(): Connection` method.**
  dev-master's `src/Database/Contracts/TenantDatabaseManager.php` declares
  exactly `createDatabase`, `deleteDatabase`, `databaseExists`,
  `makeConnectionConfig` — one method *fewer* than v3, which also had
  `setConnection(string): void` (now on `StatefulTenantDatabaseManager`).
  This package never calls `setConnection`, and its own
  `Contracts\Tenancy\TenantDatabaseManager` is an unrelated interface over
  `Nvade\Numerosis\Models\Central\Tenant`, so nothing here breaks either way.
- **`BatchTenancyBootstrapper` was never referenced here**, so its removal is
  a non-event — confirmed by grep, not by the changelog.

## Signature changes that only bite on the v4 path

`Concerns\HasTenantOptions` is not a straight rename of `HasATenantsOption`:
`getTenants()` becomes `getTenants(?array $tenantKeys = null)`, `__construct()`
becomes `__construct(mixed ...$args)`, and it adds `--skip-tenants` /
`--with-pending` options plus a `getTenantsQuery()` method. Nothing composes
it here any more — the three `src/Console/Commands/*TenantModule.php` commands
that did were deleted with the module system on 2026-09-03, and they composed
cleanly on both versions only because they declared no constructor and no
`getTenants()` override. **The next command that composes this trait and
overrides either member will compile against one version and fatal on the
other**, and there is no longer an in-repo example to copy the safe shape
from. Same trap class as the `WithRateLimiting` collision in
`.ai/rules/auth-login.md`: check the trait's real signature on both
versions before overriding a member, not just the name.

`ResourceSyncing` also went from roughly two events and one listener to
**seven events and eight listeners** (`CentralResourceAttachedToTenant`,
`SyncMasterDeleted`, `SyncedResourceDeleted`, `RestoreResourcesInTenants`, …).
That is a genuine behaviour surface, not a namespace move — the
`is_bot`-column crash recorded in `.ai/rules/tenant-provisioning.md`
("`UpdateSyncedResource` creates a central row from *every* attribute")
must be re-tested against `UpdateOrCreateSyncedResource` + `ParsesCreationAttributes`
rather than assumed fixed or assumed unchanged.

## Suggested better approach

The port is expensive for one reason that no compat layer fixed: **this
package reads another package's names directly, in ~120 places, with no
indirection.** Two layers were built to absorb that and both are now gone —
which does not make the observation wrong, only the timing.

The eager-symbol half genuinely needs conditional definition when the day
comes (PHP has no conditional `implements`), so redo it then, one file per
symbol, using `src/Support/Compat/{FilamentUserContract,LogsActivityIfInstalled}.php`
as the pattern that survived. Two things learned building it that are not
obvious and would otherwise be rediscovered:

- **PHPStan resolves a conditionally-declared class to one canonical branch
  regardless of which version is installed** — an `if
  (SomeHelper::isDevMaster())` gate let it pick the wrong one and cascaded
  into ~68 unrelated errors. The literal, inlined
  `class_exists(\Stancl\Tenancy\Enums\RouteMode::class)` is recognised and
  picks the branch matching the real environment; a helper-method gate is not.
- **A `scanFiles` stub shadows the real class rather than merging with it**,
  so the stub for the version that is *not* installed can never be loaded
  beside the one that is, and a stricter-than-real stub makes the analysis
  wrong in the opposite direction (declaring `DatabaseConfig::getName()` as
  `string` when v3 returns `?string` turned this package's own `?? throw`
  guards into "always true" reports).

The config-key half should **not** come back as a key-routing class. The
structurally simpler shape is for `HostConfig` to be the only thing here that
ever names a raw `tenancy.*` key, normalizing them into `numerosis.*` keys
this package owns at boot, with everything downstream reading only its own
namespace. That was true before the port and is true after it; it was not
done because it moves a real risk (a normalization bug becomes global rather
than local), so it stays a recommendation.

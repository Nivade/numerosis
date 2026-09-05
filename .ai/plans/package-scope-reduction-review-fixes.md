# Package-scope-reduction review fixes

Findings from a full-branch review of `main...package-scope-reduction`
(47 commits, 460 files) on 2026-09-02, scoped to non-moved code: the package
split seams, the config split, identification modes, and the new path-mode
Livewire middleware.

Every item below was re-verified against the code before it was written down.
Ordered by severity, not by file. Each carries the failure it produces, not
just the smell.

## Blockers

### 1. `src/Support/Assets.php:60,70` — unguarded `FilamentAsset` in core

`tags()` calls `Filament\Support\Facades\FilamentAsset::getStyleHref()` and
`::getScriptSrc()` with no guard. This branch demoted `filament/filament`
from `require` to `require-dev` plus a `suggest` entry that explicitly
invites installing without it:

> "Without it this package registers no panels, and filament/filament is not
> needed at all."

A host on `nvade/numerosis` + `nvade/numerosis-ui` without
`nvade/numerosis-filament` fatals with `Class "Filament\Support\Facades\
FilamentAsset" not found` on every page that renders
`resources/views/partials/styles.blade.php`, which calls
`Numerosis::assetTags()` → `Assets::tags()`.

`NumerosisServiceProvider::registerFilamentAssets()` already guards the same
symbol with `class_exists()`. This call site does not. Also violates the
standing filament rule in `CLAUDE.md` / `.ai/rules/optional-dependencies.md`:
core must never eagerly reference a `Filament\` symbol; `class_exists()`-
guarded calls are fine.

**Fix:** guard the two `FilamentAsset` calls and decide the no-Filament
fallback — either emit the published-Vite tags alone, or nothing. Cover it
with the subprocess-absence pattern `optional-dependencies.md` documents.

### 2. `src/Http/Middleware/InitializeLivewireTenancyByPath.php:43` — tenant chosen from `Referer`

The middleware reads the first path segment of the client-supplied `Referer`
header and calls `$this->tenancy->initialize($tenant)` unconditionally. There
is no check that the caller may access that tenant.

The route stack registered at `TenancyServiceProvider:309` is `web`,
`universal`, this middleware, `EnsureSessionMatchesTenant` — no auth
middleware, no `canAccessTenant`, unlike the panel routes
`InitializeTenancyByPath` normally protects.

`EnsureSessionMatchesTenant` does not abort on a mismatch. It forgets the
tenant guard's session key, queues a recaller-cookie forget, and *rewrites*
`tenancy.session_tenant` to the spoofed tenant, then continues.

Attack: a user authenticated on tenant-a POSTs `/livewire/update` with a
valid CSRF token, a snapshot from a component they legitimately hold, and
`Referer: https://app.test/tenant-b/...`. Tenancy initializes for tenant-b.
Filament pages authorize in `mount()`, which does not re-run on an update
commit, so any component that reads `tenant()`-scoped data rather than
re-authorizing serves tenant-b's rows.

**Fix:** after resolving the tenant from the referer, require it to match
either the session's `EnsureSessionMatchesTenant::SESSION_KEY` or a tenant the
authenticated central user can access; otherwise leave tenancy
un-initialized (the documented degrade path) rather than initializing.
Add a feature test for the spoofed-referer case alongside the three in
`tests/Feature/Http/Middleware/InitializeLivewireTenancyByPathTest.php`.

### 3. `src/Support/HostConfig.php:60` — `numerosisConfig()` runs last

`apply()` calls `numerosisConfig()` (the deep-fill of missing `numerosis.*`
keys) as its final step, after `centralDomains()` and `sessionDomain()` have
already read from that namespace:

- `centralDomains()` at line 140 reads `numerosis.domains.central`
- `sessionDomain()` at line 349 reads `numerosis.domains.apex`

`mergeConfigFrom()` is a one-level `array_merge`. A host that publishes the
new `config/stubs/numerosis.php` and sets only
`'domains' => ['apex' => 'acme.com']` loses `domains.central` entirely.
`centralDomains()` then reads `''`, returns without writing, and
`tenancy.central_domains` stays at stancl's stock
`['127.0.0.1', 'localhost']` — every central route bound to the wrong
hostname, 404 across the board. `numerosisConfig()` restores the key four
steps too late.

**Fix:** move `numerosisConfig()` to the first call in `apply()`. Add a test
that applies a partial `numerosis.domains` override and asserts
`tenancy.central_domains`.

### 4. `tests/TestCase.php:143` — `Pdo\Mysql` used under a `^8.4` constraint

The branch deleted the version guard:

```php
-  (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND) => ...
+  Mysql::ATTR_INIT_COMMAND => ...
```

`composer.json:21` still declares `"php": "^8.4"`. `Pdo\Mysql` exists only on
8.5, so `composer test` on a supported version dies with
`Class "Pdo\Mysql" not found` in `getEnvironmentSetUp()` — every test, not
one.

**Fix:** restore the ternary, or raise the constraint to `^8.5` and say so in
`README.md` / `docs/host-requirements.md`. Pick one deliberately; the two
answers are different products.

## Medium

### 5. `src/Support/Features.php:60` — a registered feature cannot be turned off

`all()` returns `array_unique([...config('numerosis.features'), ...$registered])`.
Satellite providers call `Features::register()` unconditionally from
`packageRegistered()`, so merely installing `nvade/numerosis-filament` forces
`AdminPanelFeature`, `TenantPanelFeature`, `ActivityLogFeature`,
`AccountPagesFeature`, `SocialLoginFeature` and `RegistrationWizardFeature` on.
A host setting `'features' => []` still gets the panels. There is no
un-register seam, and `resetRegisteredForTesting()` is explicitly test-only.

The class docblock's "the single place features are switched on and off" is
no longer true.

**Fix:** decide the intended semantics and make the code and docblock agree.
Suggested: a registered feature is a *default*, overridable by an explicit
host opt-out (a `numerosis.features_disabled` list, or making a host-supplied
`features` array authoritative). Either way, correct the docblock.

### 6. `src/Support/Contributions.php:90` — contributions accumulate per application instance

`$centralRouteCallbacks`, `$tenantRouteCallbacks`, `$tenantSeeders`,
`$centralSeeders` and `$tenantMigrationPaths` are process-lifetime statics
with no dedup. `packageRegistered()` in `packages/account`,
`packages/auth-ui` and `packages/onboarding` appends on every application
boot; Testbench builds a fresh application per test in the same process.
`tests/TestCase.php:476` then calls `Numerosis::routes()`, which `require`s
every accumulated callback's route file — 3 on the first test, ~1500 on the
five-hundredth, in one worker. O(n²) route registration, duplicate names,
unbounded memory. Same shape for seeders (duplicated seeder runs) and for
long-lived Octane workers.

Only `tests/Feature/Support/PackageContributionSeamsTest.php` calls
`flushRouteContributions()`; the base `TestCase` does not.

**Fix:** dedup on registration (a source+file key), or flush in the base
`TestCase::setUp()`. Prefer dedup — it fixes Octane too.

### 7. `tests/Browser/ModuleMarketplaceTest.php:218` — publish leaks outside the temp public path

The test swaps `publicPath()` to a temp directory, then runs
`vendor:publish --tag=numerosis-assets --force`. That tag maps to
`Assets::sourcePaths()`, i.e. `resource_path('css')` and `resource_path('js')`
— not the public path. The `finally` restores `publicPath()` and deletes the
temp directory only, so `workbench/resources/js/numerosis.js` survives.

From then on, in this run and every future run, `Assets::tags()` takes its
`File::exists(resource_path('js/numerosis.js'))` branch first and depends on
the `ViteException` catch, changing behaviour for every other test that
renders `partials/styles.blade.php`.

**Fix:** capture the published resource paths and delete them in the same
`finally`.

### 8. `packages/filament/src/Http/Middleware/ApplyDefaultBranding.php:33` — brand name persists across requests

`brandName()` is now called only when a tenant is present. The guard fixed a
`TypeError` but has no else-branch. `Panel` objects live in Filament's
`PanelRegistry` and survive across requests in any persistent worker, so a
request with no tenant (or a tenant whose model is not a Numerosis `Tenant`)
renders the previous tenant's brand.

**Fix:** add the else-branch restoring the panel default.

### 9. `src/Http/Requests/Billing/StartCheckoutRequest.php:55` — `custom_domain` dropped

Neither validated nor carried, so `toRegistrationData()` builds
`TenantRegistrationData` with `custom_domain = null`. Under
`IdentificationMode::CustomDomain`, provisioning reaches
`CreateTenantDomain::handle()`, whose match arm is
`$customDomain ?? throw new RuntimeException(...)` — the queued
`ProvisionTenant` chain dies after payment is taken.
`assertCustomDomainAvailable()` is never called on this path either. (The
wizard bypasses this request entirely — see `.ai/rules/billing-checkout.md`.)

**Fix:** validate and carry `custom_domain` when the mode requires it, and
call the policy's availability assertion here.

### 10. `src/Services/Tenancy/DefaultTenantDomainPolicy.php:53` — custom domains race

`assertCustomDomainAvailable()` checks the `domains` table only, never
`pending_tenant_provisions.custom_domain`. `ReserveTenantDomain`'s
`firstOrCreate` keys on `domain`, not `custom_domain`, so nothing enforces
uniqueness at reservation time. Two in-flight registrations can both claim
one custom domain; whichever provisions second hits the `domains.domain`
unique index inside the queued chain, after payment.

**Fix:** extend the assertion to the reservation table, mirroring the
slug rule the docblock says "lives with the reservation".

## Minor

- `src/Enums/Tenancy/IdentificationMode.php:49` — `self::from()` on a raw
  config string. A typo'd `NUMEROSIS_TENANCY_IDENTIFICATION_MODE` throws an
  uncaught `ValueError` from `withMiddleware()`'s `afterResolving(HttpKernel)`
  callback, before `RegisterFacades` and before an exception handler exists.
  The method already defends against the null-facade case for exactly this
  reason. Use `tryFrom()` with the documented `Subdomain` default.
- `src/Models/Central/Tenant.php:194` — the custom-domain
  `resolveRouteBinding()` override fires for *any* binding with
  `$field === 'id'`, not only Filament's panel tenant resolution. An explicit
  `{tenant:id}` binding resolves against `domains.domain`, finds nothing and
  404s a valid tenant id, with no opt-out. The docblock's justification holds
  for `Filament\Panel\Concerns\HasTenancy` only.
- `tests/Browser/ModuleMarketplaceTest.php:241` — `$page->wait(2)` instead of
  waiting for the modal: flaky on a loaded CI box, and burns two seconds when
  it passes. Pest's browser plugin polls; use it.
- `config/numerosis/tenancy.php:27-28` — `Domain` and `Tenant` imports unused
  after the config split (the file uses the fully-qualified
  `IdentificationMode`). Pint's `no_unused_imports` would strip them.
- `src/Support/HostConfig.php:480` — `numerosisConfig()` `require`s
  `config/numerosis.php` plus its fifteen partials on every `apply()`, i.e.
  every request, then deep-walks the merged tree. That is work
  `php artisan config:cache` is meant to eliminate. Memoize the parsed
  defaults in a static.

## Suggested execution order

1. Blockers 1–4 as one commit — they are independent single-site fixes, and
   two of them (1, 4) make the package unusable for a supported
   configuration.
2. Medium 5–6 next; both are seam-semantics decisions, not mechanical edits,
   and 5 needs a documented answer before code changes.
3. Medium 7–10 and the minors can land individually.

Every item needs a test per the repo's test-enforcement rule. Run
`composer format` before `composer test` (Pint edits files; formatting after
testing means testing twice).

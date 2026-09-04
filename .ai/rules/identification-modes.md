---
paths:
  - 'src/Resolvers/**'
  - 'config/numerosis/tenancy.php'
---
> **Header note, 2026-09-03.** Every reference below to
> `.ai/rules/filament-tenancy.md`, to a panel, or to Filament's own tenancy is
> **void**: `packages/filament` and that rule file were deleted in Phase 1 of
> `.claude/plans/archive/humming-nibbling-flame.md`. The three identification modes,
> the slug-vs-domain distinction and `PreservingPathTenantResolver` are all
> unchanged and still current — only the second tenancy system they used to
> have to coexist with is gone.

# Tenant Identification Modes

`config('numerosis.tenancy.identification.mode')` selects one of
`Nvade\Numerosis\Enums\Tenancy\IdentificationMode`'s three cases —
`subdomain` (default, the original and only behaviour before 2026-08-30),
`custom_domain`, `path`. Added in Phase 5 of
`.claude/plans/archive/memoized-tinkering-meadow.md`.

Everything below was found by reading Filament's and stancl's own source
against a real run, not from either project's docs. Read
`.ai/rules/filament-tenancy.md` first — the two tenancy systems it
describes are exactly what makes the non-subdomain modes fiddly.

Where the branching code lives: the enum, both resolvers, `CreateTenantDomain`
and `DefaultTenantDomainPolicy` are **core**; `NumerosisTenantPlugin`,
`TenantResource` and `DomainsRelationManager` are **`packages/filament`**
(`Nvade\NumerosisFilament\`); the wizard's two fields are
**`packages/onboarding`** (`Nvade\NumerosisOnboarding\Livewire\Steps\TechnicalSetup`).

## The identifier and the domain are two different values, always

**`tenants.id` is also the physical database name and the `domains.id`, so it
can never be a raw custom domain.** `.ai/rules/testing.md` already records
what a dot or an apostrophe in a tenant id costs (unquoted `SCHEMA_NAME`
lookups crashing teardown, orphaned databases), and dev-master adds
`ValidatesDatabaseParameters`, which rejects `.` outright.

So the wizard has **two** fields under `custom_domain` mode, not one renamed
field: `TechnicalSetup::$domain` stays the safe slug in every mode, and
`$customDomain` is the fully-qualified host. They travel separately all the
way down — `TenantRegistrationData::$custom_domain`,
`pending_tenant_provisions.custom_domain`, and
`CreateTenantDomain::run($tenant, $slug, $customDomain)`. **Do not "simplify"
this by putting the custom domain in `$domain`**; the tenant id derived from
it is what breaks, several layers away, in database creation.

`CreateTenantDomain` returns **`null`** under path mode — that mode resolves
purely by id and creates no `domains` row at all. Any caller that assumes a
`Domain` comes back needs a null check; `CreateTenant` is the only one today.

## `Tenant::resolveRouteBinding()` cannot tell the modes apart by `$field`

`Filament\Panel\Concerns\HasTenancy::getTenant()` calls
`resolveRouteBinding($key, $this->getTenantSlugAttribute())`, and the slug
attribute is fixed at panel registration by `->tenant(Tenant::class, 'id')`.
So **`$field` is the literal string `'id'` in every mode**, including the one
where `$value` is `app.acme.com` and no `tenants.id` will ever equal it. The
override has to consult `IdentificationMode::current()` itself; there is no
way to infer it from the arguments. That is why the check reads
`$field === 'id' && IdentificationMode::current() === CustomDomain` rather
than something that looks at the value's shape.

## `numerosis.domains.tenant_pattern` only means anything under Subdomain mode

`config('numerosis.domains.tenant_pattern')` is read only when
`IdentificationMode::current() === IdentificationMode::Subdomain`.
Custom-domain mode uses a fixed `{tenant}` pattern internally (see the
`->tenantDomain('{tenant}')` section below), and path mode uses no domain
pattern at all — `InstallNumerosisCommand::verifyDomainConfig()` skips the
pattern check for both.

## `->tenantDomain('{tenant}')` is load-bearing punctuation

`Filament\Panel::register()` does:

```php
if (str($this->getTenantDomain())->is(['{tenant}', '{tenant:*}'])) {
    Route::pattern('tenant', '[a-z0-9.\-]+');   // dots, specifically
}
```

Laravel's default route-parameter pattern excludes `.`, so a tenant domain of
`app.acme.com` only ever matches when the panel's tenant-domain string is
**exactly** `{tenant}` (or `{tenant:*}`). A "more descriptive" value like
`{tenant}.{apex}` or `{tenant}` with any suffix silently stops matching every
customer whose domain has more than one label — i.e. all of them. Hence
`NumerosisTenantPlugin::tenantDomainPattern()` returns the bare literal for
custom-domain mode, and `null` for path mode (which makes Filament fall back
to its own `{tenant}`-prefixed path routing).

## Path mode: stancl forgets the route parameter Filament still needs

`Stancl\Tenancy\Resolvers\PathTenantResolver` calls
`$route->forgetParameter('tenant')` in **both** `resolveWithoutCache()` and
`resolved()`. Our identification middleware runs early (forced to highest
priority by `TenancyServiceProvider::makeTenancyMiddlewareHighestPriority()`),
so by the time Filament's own `Filament\Http\Middleware\IdentifyTenant` runs,
`$request->route()->hasParameter('tenant')` is **false** — and that middleware
returns `$next($request)` early on exactly that condition. Result: tenancy is
initialized, `Filament::getTenant()` is null, and nothing errors at the point
of the mistake. `Nvade\Numerosis\Resolvers\PreservingPathTenantResolver`
overrides both methods to leave the parameter alone, bound over
`PathTenantResolver` in `TenancyServiceProvider::register()`. Nothing in this
package relies on stancl's removal of it.

**Path mode also has to opt out of two central-domain guards**, because its
tenant routes deliberately live *on* the central domain:

- `TenancyServiceProvider::tenancyRouteMiddleware()` returns
  `Http\Middleware\NullMiddleware` instead of
  `PreventAccessFromCentralDomains`, which would otherwise 404 every tenant
  request.
- `NumerosisTenantPlugin::shouldRegisterPanel()` returns `true`
  unconditionally. Its central-domain skip exists to stop a `{tenant}.<apex>`
  pattern from beating the central app's own `/` route
  (`.ai/rules/filament-tenancy.md`); with no domain pattern there is no
  such race, and keeping the skip would make the panel unreachable outside a
  console process.

## The uniqueness check reads a different table per mode

`DefaultTenantDomainPolicy::alreadyTaken()`: subdomain mode checks
`domains.domain` for `"{$slug}.{$apex}"`; every other mode checks
`tenants.id` directly, because that is what the identifier actually keys.
Getting this wrong is silent in both directions — a taken identifier let
through, or a free one rejected — and the regression test that catches it is
the *negative* one (`test_default_policy_ignores_a_tenant_id_collision_under_subdomain_mode`),
not the positive one. A policy that checked `tenants.id` in every mode would
pass the "rejects a taken subdomain" test perfectly.

`assertCustomDomainAvailable()` is a **separate contract method**, not a
second call to `assertAvailable()` — different format regex (FQDN vs. single
label), different table scope, different error-bag key (`customDomain`).

## On an eventual v4 port, every mode's middleware must be registered

Not a live concern — v3 has no route-mode concept and this package is v3-only
— but it is the first thing that breaks on the port, and it breaks as a 404
rather than an error. dev-master's
`Concerns\DealsWithRouteContexts::routeHasMiddleware()` is an exact-string
`in_array()` against `tenancy.identification.middleware`, so a mode whose
middleware is absent from that list falls through to `RouteMode::CENTRAL`,
and `PreventAccessFromUnwantedDomains` then 404s every tenant request as
"central route from a tenant domain". Whichever class
`TenancyServiceProvider::identificationMiddleware()` returns has to be in
there, including this package's own `InitializeTenancyByDomainOrSubdomain`
subclass, which stancl cannot know about.

**Path mode's middleware belongs in `identification.middleware` only, never
in `identification.domain_identification_middleware`** — that narrower list
is stancl's domain-based subset, and path identification is not domain-based.

A `HostConfig::tenancyIdentificationMiddleware()` doing exactly this existed
and was deleted with the rest of the dual-version layer on 2026-08-31; it is
recoverable from that commit rather than needing rewriting.

## What is NOT proven by the test suite

`tests/Feature/Providers/IdentificationModeTest` covers mode selection,
middleware choice, domain-row creation, policy scoping, and route binding —
all of it below the HTTP layer.

**The path-mode round trip is now covered, as of 2026-08-31**, by
`tests/Browser/PathModeTest` (see `tests/Browser/README.md`).
`PreservingPathTenantResolver` is no longer source-derived: with it, an
authenticated request to `/{tenant}` renders the tenant panel including the
tenant's own name — a value only reachable through `Filament::getTenant()`;
with stancl's `PathTenantResolver` rebound in its place the same request is a
500, `Filament\Panel::getTenantBillingUrl(): Argument #1 ($tenant) must be of
type Illuminate\Database\Eloquent\Model, null given`. **The negative control
is the test.** An earlier version asserted only against the *unauthenticated*
page and passed under both resolvers — the panel's login page is not
tenant-scoped, so it cannot tell them apart. If this test is ever rewritten,
keep it on an authenticated panel page.

**The subdomain and custom-domain tenant-panel round trips are now covered
too, as of 2026-08-31**, by `tests/Browser/SubdomainModeTest` and
`tests/Browser/CustomDomainModeTest` — same shape as `PathModeTest`
(authenticated dashboard render including the tenant's own name, plus an
unauthenticated login-page request), both asserted free of `Server Error`.
Neither test visits a central domain, so neither touches the one thing that
still can't be proven here:

**"Central route wins over the `{tenant}` wildcard" is still not provable,
and a browser test does not fix it.** `pestphp/pest-plugin-browser` serves the
application *in-process*, so `PHP_SAPI` remains `cli` and
`app()->runningInConsole()` is `true` inside a browser request —
`shouldRegisterPanel()`'s console exemption applies exactly as
`.ai/rules/filament-tenancy.md` describes, regardless of what a request's
`Host` header is set to. That needs a genuinely separate FPM or `php -S`
server, which nothing here has.

## Suggested better approach

Six files now branch on `IdentificationMode::current()`, and three of them
(`TenantResource`, `DomainsRelationManager`, the two Blade views) branch only
to decide **presentation** — a label, a prefix, a suffix, a URL preview.
That is the same "one value, three call sites, they drift" shape
`.ai/rules/tenant-registration-wizard.md` documents for
`wizardClassName`. The structurally cleaner move is a single
`IdentificationMode::displayUrlFor(Tenant|string $tenantOrSlug, ?string $customDomain): string`
plus a `label()`/`inputAffixes()` pair on the enum, so the presentation
branches collapse to one place and a fourth mode is a new enum case rather
than a fourth edit in six files. Not done here because the two Blade views
also need the *pre-tenant* (wizard) form of the URL, where there is no
`Tenant` yet — so the signature needs designing rather than guessing, and
doing it wrong would trade three honest branches for one leaky abstraction.

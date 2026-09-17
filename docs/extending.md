# Extending Numerosis

Numerosis adds tenancy, auth, billing, subscriptions and onboarding to an app
you already have. Every default it supplies is replaceable, and adopting it
deletes nothing you already wrote. As of the scope-reduction plan
(`.claude/plans/archive/humming-nibbling-flame.md`), `nvade/numerosis` is one package
(tenancy, Fortify-backed auth, billing, onboarding, views); the only other
split, `nvade/numerosis-ui`, is a reusable Flux component library with no
features or routes of its own, so these seams have exactly one caller: your
app.

There are three tiers, in the order to reach for them:

1. **Convention** — put the file where the package looks (`routes/web.php`,
   `database/migrations/tenant`).
2. **A container binding** — `bind()` your class over the package's in your
   own `AppServiceProvider`. No config key, no opt-in seam.
3. **`registerXUsing()`** — replace a whole mechanism, for a host that wants
   none of the defaults.

`tests/Feature/PackageBoundariesTest.php` enforces the one boundary left:
`nvade/numerosis-ui` may not reference core, `Filament\`, `tenancy()` or a
named `route()` — it has to stay installable on its own.

## The seams

| To contribute | How | Notes |
|---|---|---|
| central-domain routes | `routes/web.php` | Loaded by `Numerosis::routes()` **once per configured central domain**, inside that domain's own `Route::middleware('web')->domain($domain)` group, after the package's own file. Your own `Route::get()` outside that group would answer on every tenant subdomain, and nothing would fail |
| tenant routes | `routes/tenant.php` | Same, inside the single `Route::middleware('tenant')` group. `php artisan vendor:publish --tag=numerosis-routes` writes an empty one |
| API routes | `routes/api.php` | Loaded outside the domain groups, under `Route::middleware('api')->prefix($apiPrefix)`. `withRouting(using: …)` skips everything `ApplicationBuilder` would have built, so without this an app with an API loses it on adoption |
| a feature | `Features::register(class-string<Feature>)` | Merges with `config('numerosis.features')`. `Features::registered()` tells a contributed feature from a host-configured one |
| tenant migrations | `database/migrations/tenant` | Stancl's conventional directory, seeded into `tenancy.migration_parameters['--path']` alongside the package's. Setting that key yourself in `config/tenancy.php` replaces the default outright. **Provisioning only migrates new tenants** — see "Rolling a migration across the fleet" |
| seed data | publish `--tag numerosis-seeders`, or bind over a package seeder | `Seeder::resolve()` goes through the container, so `bind(PackageSeeder::class, YourSeeder::class)` swaps any seeder the package's own `DatabaseSeeder`/`TenantDatabaseSeeder` calls, and you keep inheriting seeders core adds later |
| permissions | subclass `RoleAndPermissionSeeder` (central) or `Tenant\PermissionAndRoleSeeder`, override `contexts()`, bind it | **A missing permission row is a 500, not a 403** — Spatie throws `PermissionDoesNotExist` rather than returning false, so any navigation that gates its own visibility on a check breaks every page carrying it, not just its own screen |
| non-CRUD permission verbs | override `Permission::additionalActions()` on a subclass | Empty in core, read through `Permission::actionsFor()`. See the two caveats below |
| views | `->hasViews('numerosis')` from your own provider | `FileViewFinder::addNamespace()` *appends*, so several packages can serve one namespace. Paths are searched in registration order, so a view must be **moved, never copied** |
| single-file Livewire pages | your own key in `livewire.component_namespaces` | Unlike views, a prefix maps to exactly **one** directory — two packages cannot join the same key. Core's own are `numerosis-layouts` and `numerosis-pages`; the generic `layouts`/`pages` keys are yours and core never touches them. Set the key from `register()`, and set *one key*, never the whole array |
| the `home` page | `numerosis.routes.home_view`, or declare `/` in your `routes/web.php` | Your file loads after core's, and `RouteCollection` keys on method + domain + URI, so your `/` replaces core's. Name it `home` (or point `numerosis.routes.names.home` at your name), or `route('home')` stops resolving once `RouteServiceProvider` rebuilds the name lookup |
| tenant model columns | a migration on the `tenants` table | `Tenant::getCustomColumns()` reads the schema, so a real column is recognised with no registration. The listing is memoized per boot and flushed by `Numerosis::resetModelCache()` |
| a model | publish `--tag numerosis-models`, or set `numerosis.models.<FQCN>` | Convention (`App\Models\<suffix>`) is found automatically; the config key is for a non-conventional location |
| a provisioning step | `numerosis.tenancy.provisioning.steps` | Flat ordered list, every entry `Contracts\Tenancy\ProvisioningStep`, one queued chain link each — see "Provisioning steps and the contribution seam" below |

### The four boot-phase calls

`configure()`, `routes()`, `middleware()` and `exceptions()` are not ordinary
`Numerosis::` calls, and the class groups them together for that reason. They
run while `ApplicationBuilder` is being built — before `RegisterFacades`, and
before `LoadConfiguration` has finished — so they go through the class and
never the facade (see "The `Numerosis` facade" below), anything they touch
that reads `Config` needs a facade-root guard, and their order relative to
your own `bootstrap/app.php` calls decides which wins.

| Call | Where it goes |
|---|---|
| `Numerosis::configure(basePath: …)` | stands in for the whole `Application::configure()` chain, applying the three below |
| `Numerosis::routes(withAuth: …, apiPrefix: …)` | `withRouting(using: …)` |
| `Numerosis::middleware(…)` | `withMiddleware(…)`, first in the closure |
| `Numerosis::exceptions(…)` | `withExceptions(…)` |

Every other method on `Numerosis` runs against a booted application and has
none of these constraints.

### What a container binding swaps

`bind()` these from your own `AppServiceProvider`, which registers after the
package's:

| Bind | Over | Because |
|---|---|---|
| a middleware class | `Http\Middleware\InitializeTenancy` and friends | `Pipeline` resolves every pipe with `$container->make($name)`. The replacement need not extend the package class; the pipeline only calls `handle()` |
| a seeder class | any seeder core's `DatabaseSeeder`/`TenantDatabaseSeeder` calls | `Seeder::resolve()` resolves through the container |
| a feature class | any `Features::all()` entry | `bootstrapFeatures()` does `$app->make($feature)->bootstrap()`; `numerosis.features` still decides whether it runs at all |
| `Contracts\Exceptions\ProvidesExceptionContext` | `Services\Exceptions\TenantAwareExceptionContext` | Adds your keys to every reported exception. Depend on the default in your constructor to decorate rather than replace it |
| a policy class | any `Gate::policy()` pair core registers | `Gate` resolves policies through the container |
| any `numerosis.{billing,tenancy}.implementations` contract | its concrete | See "Swapping an implementation" below. `Contracts\Billing\Entitlements` is bound as a **singleton**, since its per-request memo is the point |

### Overriding `Permission::additionalActions()`

Two things about that seam are easy to get wrong, and both fail silently:

- **It reaches the tenant guard only.** `Database\Seeders\Tenant\PermissionAndRoleSeeder`
  is the only caller of `actionsFor()`; the central `Database\Seeders\RoleAndPermissionSeeder`
  calls `defaultActions()` directly, so a `web`-guard context gets CRUD and
  nothing else however you override it.
- **It needs a seeder of your own.** `Permission` is not in `numerosis.models`,
  so there is no `Numerosis::model()` indirection resolving your subclass, and
  both seeders name the package class literally. `actionsFor()` binds late
  (`static::`, not `self::`), so an override applies when *you* call
  `YourPermission::actionsFor()` — from a `Tenant\PermissionAndRoleSeeder`
  subclass bound over the package's.

### Core enums on these seams still accept your strings

`Permission::defaultActions()`, the seeders' `contexts()`, and every policy's
`permissionContext()` moved onto `Enums\Auth\{PermissionAction,PermissionContext}`
in the enum-vocabulary-sweep, and `numerosis.tenancy.registration.steps`'s
shipped entries are backed by `Enums\Tenancy\WizardStep`. None of the three
seams above narrowed to the enum type: `Permission::actionsFor()` still
returns `list<string>`, `additionalActions()` still keys on a plain string
context, `permissionContext()` still returns `string`, and the registration
steps config is still `list<class-string>`. Core gives you cases; a host
context, action, or step that has no case is still valid at every one of
these seams — an enum that closed them would end the extension points this
section documents.

### Swapping an implementation

`numerosis.{billing,tenancy}.implementations` is a `contract => concrete` map;
`BillingServiceProvider` and `TenancyServiceProvider` each loop theirs and
`bind()` every pair. Name your own class against the contract to replace one —
no provider edit, no subclassing.

That map is why every interface in `src/Contracts/` stays, even though each
ships exactly one implementation (**decided 2026-09-01**; deleting the
"redundant" ones was an open question from `.claude/plans/archive/confusion-cleanup.md`
step 4). They are not speculative abstraction:

- **Most are the swap points themselves**, named in one of those two maps.
  Deleting one removes a documented host capability and leaves nothing to
  `bind()` against.
- **The rest are role interfaces, not service bindings**, and are load-bearing
  as types: `CentralUserModel`/`TenantUserModel` are what `HostConfig` and
  `Enums\Tenancy\Context::userModel()` resolve a host's own model through,
  `Feature`/`NamedFeature` are the feature registry's contract, and
  `Subscribable`/`Plan`/`HasTenants`/`ProvidesTenantIdentity`/`HasTenantOwner`/
  `Suspendable` are the shapes core's own services accept so a host subclass
  satisfies them without extending a package class. `HasTenantOwner` is what
  `Actions\Notifications\NotifiesTenantOwnerDirectly` needs (an `owner()`
  returning the notifiable) and `Suspendable` what
  `Http\Middleware\EnsureTenantSubscriptionActive` reads
  (`isSuspended()`/`suspended_at`); implement either on your own tenant model
  and core keeps working.

"One implementation" is the expected state for a framework whose whole point is
that the *host* supplies the second one.

### Listing sessions on a driver that is not `database`

`Contracts\Auth\SessionRegistry` is what `settings/sessions` reads and
revokes through, bound to `Services\Auth\DatabaseSessionRegistry`. That
implementation returns `listable() === false` on any other driver, and the
screen then offers only "sign out other devices", which works everywhere
because it moves the password stamp `AuthenticateSession` compares rather than
deleting stored rows.

A host on Redis keeping its own per-user index of session ids binds its own
class through `numerosis.tenancy.implementations` and gets the listing back.
Two things any implementation has to reproduce: the guard a session belongs to
is only knowable from the login key inside the payload, since the `user_id`
column holds whichever guard was ambient when the row was written; and
`forgetTenantAccess()` has to strip one tenant's keys from a session rather
than delete it, because the same session carries the user's central login and
every other tenant they are in.

### Rolling a migration across the fleet

`MigrateTenantDatabase` is a provisioning step, so it runs once, for one
tenant, at creation. Tenants that already exist are migrated by
`tenancy:migrate`:

```bash
php artisan tenancy:migrate --dry-run        # who is behind, and on what
php artisan tenancy:migrate                  # inline, every tenant
php artisan tenancy:migrate --queue          # one job per tenant
php artisan tenancy:migrate --resume=<run-id>
```

Both paths go through `Actions\Tenancy\MigrateTenant`, so a rollout and a new
signup cannot end up at different schemas. Every path in
`tenancy.migration_parameters['--path']` is included, which is what makes your
own tenant migrations part of the rollout without registering them anywhere
else.

| Flag | What it does |
|---|---|
| `--tenants=` | Limit to specific tenant ids, repeatable |
| `--pending` | Skip tenants already at the target, recording them `skipped` |
| `--dry-run` | Report pending migrations per tenant, writing nothing |
| `--chunk` / `--delay` | Tenants per chunk, and seconds between chunks. Thousands of `ALTER` statements saturate a database server; the pause is the knob. Defaults in `numerosis.tenancy.migrations` |
| `--queue` | Dispatch one job per tenant to `numerosis.tenancy.migrations.queue` (`migrations`). **Run a worker for that queue** — it is deliberately not `provisioning`, whose depth the health endpoint reports as customers waiting on a signup |
| `--stop-on-failure` | Off by default. A fleet rollout continues past a failing tenant and names every failure at the end |
| `--resume=` | Re-run one run id, skipping the tenants it already finished |

One `tenant_migration_runs` row per tenant per run records what was applied,
how long it took and what failed. That table is what `--resume` reads and what
the staff panel's migrations screen renders. The run exits non-zero if any
tenant failed.

There is no `--rollback`: a fleet-wide rollback is almost always a mistake, and
stancl's own `tenants:rollback --tenants=acme` covers the single-tenant case.

### Operator alerts

`Contracts\Notifications\OperatorRecipient` answers "who does this
installation tell when provisioning fails". Core binds
`Services\Notifications\MailsConfiguredOperator`, which mails
`numerosis.notifications.operator` and sends nothing when that key is unset —
so nothing ships enabled.

Bind your own against the contract (through `numerosis.tenancy.implementations`)
to reach Slack, PagerDuty or an on-call rota. One alert per
`numerosis.notifications.throttle_minutes` window reaches it, whichever failure
gets there first: a bad deploy fails every provision it touches.

### Checkout payment methods, by region

`Contracts\Billing\CheckoutRegionResolver` answers "which country is this
checkout request from". Core binds `Services\Billing\NullCheckoutRegionResolver`,
which always returns `null` — the package ships no IP-to-country lookup, and
`null` means "use the default order", never an error. Bind your own against the
contract (through `numerosis.billing.implementations`, like any other) to turn
the per-region ordering on:

| Key | What it does |
|---|---|
| `numerosis.billing.payment_methods.default_order` | Stripe `paymentMethodOrder` for a request with no resolved country. Ships `['card', 'link']` |
| `numerosis.billing.payment_methods.regions.<ISO-3166-1 alpha-2>` | the order for that country, falling back to `default_order` when the country has no entry. Ships ten European and US entries |

Ordering only. A method absent from a list still appears when Stripe considers
it eligible, and a resolved country also pre-fills the Address Element's
default country. Neither restricts what a customer may pay with — that stays
Stripe's call. An entry naming something that is not an
`Enums\Billing\PaymentMethodType` case is dropped.

## Customizing auth — through Fortify

Auth is `laravel/fortify`'s, wired in `NumerosisServiceProvider::registerFortify()`.
Customize it the way Fortify's own docs describe — there is no
numerosis-specific auth API to learn on top of it:

| To change | Call |
|---|---|
| a screen | `Fortify::loginView(...)` / `registerView(...)` / … — each accepts a closure receiving `$request`, so a tenant-aware screen is one branch |
| where login lands | bind `LoginResponse` |
| how users are created | `Fortify::createUsersUsing(...)` |
| the login pipeline | `Fortify::authenticateThrough(...)` |
| which screens exist at all | `config('fortify.features')` |
| URLs | `config('fortify.paths.*')` |
| routes entirely | `Fortify::ignoreRoutes()` (core already calls this — expose it through `Numerosis::routes(withAuth: false)`) |

`fortify.features` is defaulted by `HostConfig::fortifyFeatures()`, gated by
`numerosis.auth.manage_fortify_features` (default `true`) rather than a
stock-value comparison. Three entries differ from Fortify's own shipped list:
passkeys are dropped, since they have no view under `numerosis::auth.` nor the
column their controllers write, so leaving them on registers screens that fail
only once somebody reaches them; two-factor authentication is on with
`['confirm' => true, 'confirmPassword' => true]`; and password reset follows
`PasswordResetFeature` so the two configs cannot disagree about whether it
exists. Set `numerosis.auth.manage_fortify_features` to `false` and edit
`fortify.features` yourself to own it outright.

Two-factor enrolment is a **central** screen, `settings/two-factor`, and the
package registers Fortify's enrolment routes on the central domains only.
Credentials are checked against the central user provider on every domain — a
tenant session is promoted from the central one by `tenancy.auth` — so a secret
stored on a tenant user would never be challenged. The challenge itself
(`two-factor.login`) is registered in the tenant group too, since a login that
begins on a tenant domain has to finish there.

An owner can require a second factor of every member from the team screen,
which writes `tenants.requires_two_factor` and a
`requires_two_factor_from` deadline (`numerosis.tenancy.two_factor.grace_days`,
7 by default). The `tenancy.two_factor` middleware alias is what enforces it;
core applies it to its own gated tenant routes, so put it on yours too. It is
deliberately off the `tenant` group: it redirects to the central enrolment
screen, and the team screen itself stays outside it so an owner who let the
deadline pass can still reach the switch. Central users holding the staff
permissions need a confirmed factor unconditionally — no grace, no toggle —
and a staff user holding `clearTwoFactor users` can clear someone else's from
the staff users screen, which is always activity-logged.

`numerosis.features` and `fortify.features` stay separate on purpose:
numerosis's gates tenancy/billing surfaces (invitations, the registration
wizard, OTP login), Fortify's gates auth screens (registration, password
reset, email verification). `HostConfig::fortifyFeatures()` derives
`fortify.features` from `numerosis.features` for the two that overlap
(`PasswordResetFeature::NAME` → `FortifyFeatures::resetPasswords()`), so
toggle those through the numerosis key, not the Fortify one.

Numerosis's own auth actions (`CreateRegisteredUser`, `UpdateUserProfile`,
`UpdateUserPassword`, `ResetUserPassword`) are bound as plain `singleton`s in
`packageRegistered()`, not forced. A host's `AppServiceProvider` runs
afterwards and its own `Fortify::createUsersUsing(...)` (etc.) call
overwrites the binding — override is free, no opt-in seam to build.

**Validation lives inside these actions, not a Form Request** — a deliberate
deviation from this repo's usual convention. Every Fortify action contract is
typed `array $input`, called from Fortify's controller *and* from a Livewire
settings component (`Settings/{Profile,Password}`); only one of those has a
Form Request, so the action validates on entry via `Data::validateAndCreate()`
into the **default** error bag. Fortify's own stock actions use
`validateWithBag('updateProfileInformation')` instead — if you swap one of
numerosis's actions back for Fortify's, the Livewire views' `@error('name')`
needs to become `@error('name', 'updateProfileInformation')` to match, or
errors render nowhere.

### Invitation roles

An invitation's `role` is `Enums\Tenancy\MembershipRole`, the vocabulary of the
`memberships.role` database enum. `MembershipRole::assignable()` omits `Owner`,
and both `Http\Requests\Invitations\StoreInvitationRequest` and any caller
using `Data\Invitations\InvitationData::validateAndCreate()` enforce that
through one shared `rules()`. Ownership is granted by
`Actions\Tenancy\AddTenantOwner` during provisioning.

### Provisioning steps and the contribution seam

`numerosis.tenancy.provisioning.steps` is a flat, ordered list — every entry
implements `Contracts\Tenancy\ProvisioningStep::handle(TenantProvision $provision): void`
and runs as its own queued chain link, in list order. There is no privileged
first or last entry; insert a step anywhere, including before the tenant
database exists or after `FinalizeTenantProvisioning`.

| To | Implement |
|---|---|
| add a provisioning step | `Contracts\Tenancy\ProvisioningStep`, add the class to `numerosis.tenancy.provisioning.steps` |
| skip a step when data it needs was never collected | also implement `Contracts\Tenancy\RequiresContributions::requires(): array` — the runner skips-and-records rather than crashing or needing its own guard clause |
| read a contribution without becoming skippable | also implement `Contracts\Tenancy\ReadsContributions::reads(): array` — unlike `RequiresContributions`, absence never skips the step |
| give a step its own retry profile | also implement `Contracts\Tenancy\ControlsItsOwnRetries` (`tries()`/`backoff()`); every step defaults to 5 attempts, 5 seconds apart |
| collect data during the registration wizard for provisioning to consume | `Contracts\Tenancy\ContributesProvisionData::contribute(array $state): ?ProvisionContribution` on the wizard step; `Livewire/Tenant/Registration.php` collects from every configured step, core's `Plan`/`TechnicalSetup` steps included |
| make a contribution's fields queryable | implement `Contracts\Tenancy\PersistsToProvisionColumns` as well as `ProvisionContribution`, add the columns, and declare it on the step that uses it, through `RequiresContributions::requires()` or `ReadsContributions::reads()` — a column-backed contribution no step declares will not be rebuilt off the row |
| create a tenant outside the checkout flow | `php artisan tenancy:provision <slug> --owner=<global_id> --name="..."` builds a `TenantProvisionData` and calls `ProvisionsTenant::queue()` — the same entry point checkout uses |

A contribution is a `spatie/laravel-data` `Data` subclass carrying anything
beyond a tenant's identity, which is its slug and its name and nothing else.
Core's own owner, billing and custom-domain data travel through the same seam
a host's would. Ownership is a contribution because it is a fact about a
relationship rather than part of what a tenant is: provision without an
`OwnerContribution` and `AddTenantOwner` and `PromoteFirstUserToAdmin` record
themselves skipped, which is how a system, demo or imported tenant is built. Most
contributions round-trip through the provision row's `contributions` JSON
column; one that something has to query (a `where()`, a uniqueness check)
declares `PersistsToProvisionColumns` and gets real columns instead. Core is
not privileged here: a host needing a queryable field adds a column and a
`fromProvision()` exactly as `BillingContribution`/`CustomDomainContribution`
do.

`numerosis.tenancy.provisioning.contributions` no longer exists. A host that
published `config/numerosis.php` before it was removed keeps a key nothing
reads: contributions are declared on the steps that consume them
(`RequiresContributions::requires()` / `ReadsContributions::reads()`), and a
column-backed contribution no step declares is never rebuilt off the row.
Delete the key.

Every step is recorded on the provision row (`step_records`) as it runs, so
a retry resumes from the first unrecorded step rather than restarting the
whole chain — a step does not have to be idempotent for the retry's sake,
only safe to run against whatever the step before it left behind.

### Building a tenant outside a queue

`Tenant::create()` makes a tenant row and nothing else — no database, no
migrations, no owner. That is not a gap; it is what a tenant *is* between the
first provisioning step and the second, and there is no listener or flag that
changes it. Anything that wants a usable tenant goes through
`Contracts\Tenancy\ProvisionsTenant`, which offers the same steps two ways:

| Call | Runs | For |
|---|---|---|
| `queue($data)` | `Bus::chain` on the `provisioning` queue | web requests, checkout — resumable, and the progress UI reads `step_records` as it goes |
| `now($data)` | each step inline, in order | console commands, seeders, tinker — a failure throws at the call site instead of landing in `failed_jobs`, and a slug another chain already claimed throws `Exceptions\Tenancy\ProvisioningAlreadyClaimed` rather than reporting a provision it never ran |

Both write the same provision row, take the same claim, run the same
configured list and record the same outcomes. `now()` is what
`php artisan tenancy:provision <slug> --owner=<global_id> --sync` uses, which
is the quickest way to get a working tenant while developing:

```
php artisan tenancy:provision acme --owner=<global_id> --name="Acme Co" --sync
```

In a test suite, call `now()` from your own helper rather than expecting a
tenant row to build itself. Swapping a slow step for a fast one is done
through the same config list — this package's own suite replaces the migrate
and seed steps with one that clones a template database, and thereby still
exercises the real pipeline.

### Adding a social login provider

Provider metadata (label, icon, whether it is configured) is code, not
config — there is no `numerosis.social.providers` array to edit any more.
Add a provider by adding a case to `Enums\Auth\SocialProvider` (label, icon,
and — if the case matches a Socialite driver name Socialite does not ship
itself, the way `discord` doesn't — a community driver registered the same
way `SocialLoginFeature::bootstrap()` registers Discord's) and setting its
`client_id`/`client_secret`/`redirect` under `config('services.<provider>')`.
`isConfigured()` reads `client_id` directly, so a provider with credentials
set is usable immediately; `configured()`/`configuredValues()` are what the
button list and the OAuth routes' `->where('provider', …)` constraint read.

### Replacing rather than adding

| Call | Replaces |
|---|---|
| `Numerosis::registerRoutesUsing(?Closure)` | all of `Numerosis::routes()`, including the host route files |
| `Numerosis::registerMiddlewareUsing(?Closure)` | all alias and group registration |
| `Numerosis::registerExceptionsUsing(?Closure)` | the context callback, duplicate suppression and throttle `Numerosis::exceptions()` registers. Receives the `Exceptions` instance |

Each takes `null` to clear the callback and put the package's own
registration back. That is what a test that sets one of these has to do
afterwards: the callback is static, so it outlives the application it was set
on.

### The `Numerosis` facade

`Nvade\Numerosis\Facades\Numerosis` resolves `Numerosis` out of the
container, so a test can `swap()` or `spy()` it. Every method on the support
class stays `static`; the facade forwards through the instance.

**It is unusable from `bootstrap/app.php`.** `routes()`, `middleware()`,
`exceptions()` and `configure()` run while `ApplicationBuilder` is being
built, before `RegisterFacades` — the facade root is null there and every call
throws `RuntimeException: A facade root has not been set`. Import
`Nvade\Numerosis\Numerosis` directly in that file, as the examples
above do.
| `Numerosis::routes(withAuth: false)` | narrower: still registers `routes/web.php` and `routes/tenant.php`, but skips loading Fortify's own route file into either group. Use this if you keep your own auth system — `login`, `register`, `logout` and `verification.verify` are otherwise Fortify's, registered behind no feature flag, so a host running its own auth gets a silent route-name collision resolved by provider order. Passing `false` hands you those four names: everything core generates from them, including the guest redirect and the email verification link, then resolves against your routes |

## Events

Events are public API the moment a host listens to one. Core dispatches
these with `event()`, and registers its own listeners with an explicit
`Event::listen()` map in `NumerosisServiceProvider::registerEventListeners()`
(plus a small one in `BillingServiceProvider`) — **Laravel's listener
auto-discovery never scans a package's `src/`**, only a host's own
`app/Listeners`, so a host adding its own listener for one of these gets
auto-discovery for free while core cannot rely on it for its own. And since
dispatch goes through plain `event()`, a host listener that throws takes down
the request that dispatched it, unless that listener is queued.

Any event below that carries a model carries its id as a scalar too. Prefer
the scalar in a queued listener: `SerializesModels` re-queries on unserialize,
which throws for a row that has since been deleted.

`Providers\TenancyServiceProvider::events()` is the separate map for stancl's
own tenancy lifecycle (`CreatingTenant`, `TenantCreated`, `DomainCreated`,
the `Database*` events, `TenancyInitialized`, `TenancyEnded`, …) — most wired
to an empty listener array today, so a host can hook any of them without
touching core.

| Event | When it fires | Payload | Typical use |
|---|---|---|---|
| `Auth\SocialAccountLinked` | A `SocialAccount` is created or an OAuth login resolves to an existing one (`Actions\Auth\Social\LoginWithSocialAccount`/`LinkSocialAccount`) | `globalUserId`, `provider` (scalars), `socialAccount` | Audit, welcome email for a new provider |
| `Auth\SocialAccountUnlinked` | `Http\Controllers\Auth\Social\DestroySocialAccountController` deletes a `SocialAccount` | `globalUserId`, `provider` (scalars only — the row is gone by dispatch time) | Audit |
| `Auth\UserAccountDeleting` | Before `Actions\Auth\DeleteUserAccount` deletes the row | `user`, `globalId`. Listen synchronously — a queued listener unserializes `user` after the delete committed and gets a `ModelNotFoundException` | A host purging or exporting its own rows before the account is gone |
| `Auth\UserAccountDeleted` | After the row is deleted | `globalId`, `email` (scalars only, the model no longer exists) | Cleanup that only needs the identifiers |
| `Auth\PasswordChanged` | `Actions\Auth\UpdateUserPassword` and `Actions\Auth\ResetUserPassword`, after the write | `guard`, `userId` (scalars — a tenant primary key means nothing against the central `users` table, so the guard travels with it) | `Listeners\Auth\RevokeSessionsAfterPasswordChange` deletes the user's other stored sessions |
| `Auth\UserAnonymized` | `Actions\Auth\AnonymizeUser`, after the central identity is stripped | `globalId`, `tenantIds` (scalars only — there is no name or address left to carry) | Purging a host's own rows about that person |
| `Auth\SuspiciousLoginDetected` | The `login` limiter is exhausted, once per lockout window rather than once per blocked attempt | `email`, `tenantKey` (`null` on the central domain), `ip` (scalars) | Alerting on credential stuffing; no listener ships with the package |
| `Auth\AdminGranted` | `Actions\Auth\PromoteFirstCentralUserToAdmin` or `Actions\Tenancy\PromoteFirstUserToAdmin` grants the admin role | `globalId`, `grantedBy` (always `null` today — both dispatch sites are automatic first-user promotion), `tenantId` (`null` for the central role) | Privilege-escalation audit |
| `Billing\PaymentSettled` | A payment settles after having previously failed or the tenant was suspended | `tenant`, `ownerId` | Clearing a payment-status banner |
| `Billing\PaymentFailed` | `invoice.payment_failed` webhook | `tenant` | The dunning notice |
| `Billing\TenantSuspended` | `Actions\Tenancy\SuspendTenant` | `tenant` | Access-revoked notification |
| `Billing\SubscriptionPlanChanged` | The `customer.subscription.updated` webhook, when the price actually changed. The only site — a host calling `SwapSubscriptionPlan` and an edit made in the Stripe dashboard both surface here, so neither fires twice | `tenant`, `tenantId`, `fromPriceId`, `toPriceId`, `direction` (`PlanChangeDirection::Upgrade`/`Downgrade`, falling back to `Upgrade` when a price matches no configured plan) | Entitlement recomputation, upgrade/downgrade emails |
| `Billing\SubscriptionCancelled` | `customer.subscription.deleted` webhook | `tenant`, `gracePeriodEndsAt`, `tenantId` | A retention flow — distinct from `TenantSuspended`, which is enforcement and can land days later |
| `Billing\CheckoutStarted` | `Actions\Billing\Checkout\StartSubscriptionCheckout`, once the domain is reserved | `domain`, `planId` | Funnel analytics |
| `Billing\CheckoutCompleted` | `Actions\Billing\Checkout\SettleCheckout` — the one point the card/Link path, the redirect return route and the `payment_method.attached` webhook all funnel through | `domain`, `planId`, `stripeSubscriptionId`. Fires whether or not the subscription settled immediately (a trial collects nothing upfront) | Funnel analytics; do not infer settlement from this alone |
| `Invitations\InvitationCreated` | `Actions\Invitations\SendInvitation`, which reuses the row for `(tenant_id, email)` | `invitation`, `invitationId` | The invitation email (`Listeners\Invitations\SendInvitationNotification`) |
| `Invitations\InvitationAccepted` | `Actions\Invitations\AcceptInvitation`, after the membership is attached | `invitation`, `invitationId`, `invitedByUserId`, `secondsUnaccepted`. Carries only what `Tenancy\MemberJoined` does not. That event fires automatically from `MembershipObserver::created()`, since acceptance attaches through the `tenants()` relation instead of dispatching it itself | Analytics on invite-to-accept latency; anything that needs the inviter, not just the joiner |
| `Tenancy\TenantProvisioned` | `Actions\Tenancy\MarkTenantProvisioned` | `tenant`, `ownerId` | ⚡mine's `wire:poll` picks this up on its next tick |
| `Tenancy\TenantProvisioningStarted` | `Actions\Tenancy\MarkProvisionInProgress` | `domain`, `globalId` | Progress UI, timing metrics |
| `Tenancy\TenantProvisioningFailed` | A provisioning step exhausts its retries | `domain`, `globalId` | Surfacing the failure to the user waiting on it |
| `Tenancy\TenantProvisioningCancelled` | A pending provision is cancelled | `globalId` | Same |
| `Tenancy\TenantRestored` | `Actions\Tenancy\RestoreTenant` clears a suspension | `tenant`, `ownerId`, `tenantId` | The "access restored" notification |
| `Tenancy\MemberJoined` | `Observers\MembershipObserver::created()` | `tenantId`, `globalUserId`, `role` (an `Enums\Tenancy\MembershipRole`), `invitedBy` | Seat-based billing, audit. Match `MembershipRole::Owner` instead of the string `'owner'` |
| `Tenancy\MemberRemoved` | `Observers\MembershipObserver::deleted()` | `tenantId`, `globalUserId`, `role` (an `Enums\Tenancy\MembershipRole`) | Seat-based billing, offboarding |
| `Tenancy\TenantDomainReserved` | `Actions\Tenancy\CreateTenantDomain` creates a `domains` row (only under `IdentificationMode::Subdomain`/`CustomDomain` — `Path` mode creates no row) | `tenantId`, `domain`, `mode` | DNS automation and certificate issuance under `CustomDomain` mode |

## Optional dependencies core still leans on

Three, all `suggest`: `sentry/sentry-laravel` (whose scope tagging
`Concerns\Tenancy\TagsSentryScopeWithTenant` skips unless
`app()->bound('sentry')`), `laravel/telescope` (a scheduled `telescope:prune`
and the `telescope/*` CSRF exemption, both `class_exists()`-guarded) and
`socialiteproviders/zoho` (a driver, reached only when the provider is
configured). `ryangjchandler/laravel-cloudflare-turnstile` was promoted to
`require` alongside `spatie/laravel-one-time-passwords` and
`spatie/laravel-activitylog` in 2026-09-05, so for those three
`numerosis.features`/`Tenant\User`'s trait use are the only switches.

**One seam per optional package, not one per call site** — give the
optional dependency exactly one predicate and have every consumer ask it. One
unguarded call site is a fatal, not a disabled feature. `extends`,
`implements` and `use <Trait>` resolve at class-declaration time, so never
name one of these symbols eagerly in a class
head; a method type hint or a guarded `new` is fine. See
`.ai/rules/optional-dependencies.md`.

## Where a package's own pieces live

Every migration, seeder and permission context lives in core: the tables and
rows have to exist wherever core does. `nvade/numerosis-ui` ships views and
Blade components only — no migrations, no routes, no features.

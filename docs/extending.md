# Extending Numerosis

Every seam is additive and lives on `Nvade\Numerosis\Support\{Numerosis,Features}`.
A **host** application calls these — as of the scope-reduction plan
(`.claude/plans/archive/humming-nibbling-flame.md`), `nvade/numerosis` is one package
(tenancy, Fortify-backed auth, billing, onboarding, views); the only other
split, `nvade/numerosis-ui`, is a reusable Flux component library with no
features or routes of its own, so these seams have exactly one caller: your
app. Reach for an `add*()` seam first — the `registerXUsing()` methods at the
bottom replace a whole mechanism and exist for a host that genuinely wants
none of the defaults.

`tests/Feature/PackageBoundariesTest.php` enforces the one boundary left:
`nvade/numerosis-ui` may not reference core, `Filament\`, `tenancy()` or a
named `route()` — it has to stay installable on its own.

## The seams

| To contribute | Call | Notes |
|---|---|---|
| central-domain routes | `Numerosis::addCentralRoutes(Closure)` | The callback runs **once per configured central domain**, inside that domain's own `Route::middleware('web')->domain($domain)` group. A plain `Route::get()` instead would answer on every tenant subdomain, and nothing would fail |
| tenant routes | `Numerosis::addTenantRoutes(Closure)` | Same, inside the single `Route::middleware('tenant')` group |
| a feature | `Features::register(class-string<Feature>)` | Merges with `config('numerosis.features')`. `Features::registered()` tells a contributed feature from a host-configured one |
| tenant migrations | `Numerosis::addTenantMigrationPath(string)` | `tenancy.migration_parameters['--path']` is an array; this is the supported way in |
| seed data | `Numerosis::addTenantSeeder()` / `addCentralSeeder()` | Run by the package's own `TenantDatabaseSeeder` / `DatabaseSeeder` |
| permissions | `Numerosis::addPermissionContext(string)` | **A missing permission row is a 500, not a 403** — Spatie throws `PermissionDoesNotExist` rather than returning false, so any navigation that gates its own visibility on a check breaks every page carrying it, not just its own screen |
| non-CRUD permission verbs | override `Permission::additionalActions()` on a subclass | Empty in core, read through `Permission::actionsFor()`. See the two caveats below |
| views | `->hasViews('numerosis')` from your own provider | `FileViewFinder::addNamespace()` *appends*, so several packages can serve one namespace. Paths are searched in registration order, so a view must be **moved, never copied** |
| single-file Livewire pages | your own key in `livewire.component_namespaces` | Unlike views, a prefix maps to exactly **one** directory — two packages cannot join the same key. Set the key from `register()`, and set *one key*, never the whole array: replacing it drops every other package's |
| the `home` page | `numerosis.routes.home_view` | Core always registers the `home` route and declares it first, so a second route on `/` never matches. Point this at your own view instead |
| tenant model columns | `Numerosis::addTenantColumns(array)` | Adding a column to the `tenants` table is only half the job. Name it here too, from a provider's `register()` and before any tenant is loaded or saved, or the value is folded into the `data` JSON column and the real column stays NULL. The model still reads it back correctly, so the failure only shows up in SQL — a `where` on that column matching nothing, or a join finding no rows |
| a model | publish `--tag numerosis-models`, or set `numerosis.models.<FQCN>` | Convention (`App\Models\<suffix>`) is found automatically; the config key is for a non-conventional location |

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
  `YourPermission::actionsFor()` — from a seeder registered through
  `Numerosis::addTenantSeeder()`.

### Swapping an implementation

`numerosis.{billing,tenancy}.implementations` is a `contract => concrete` map;
`BillingServiceProvider` and `TenancyServiceProvider` each loop theirs and
`bind()` every pair. Name your own class against the contract to replace one —
no provider edit, no subclassing.

That map is why all 32 interfaces in `src/Contracts/` stay, even though each
ships exactly one implementation (**decided 2026-09-01**; deleting the
"redundant" ones was an open question from `.claude/plans/archive/confusion-cleanup.md`
step 4). They are not speculative abstraction:

- **22 are the swap points themselves**, named in one of those two maps.
  Deleting one removes a documented host capability and leaves nothing to
  `bind()` against.
- **10 are role interfaces, not service bindings**, and are load-bearing as
  types: `CentralUserModel`/`TenantUserModel` are what `HostConfig` and
  `UserModelResolver` `is_a()`-check a host's own model against,
  `Feature`/`NamedFeature` are the feature registry's contract, and
  `Subscribable`/`Plan`/`HasTenants`/
  `ProvidesTenantIdentity` are the shapes core's own services accept so a host
  subclass satisfies them without extending a package class.

"One implementation" is the expected state for a framework whose whole point is
that the *host* supplies the second one.

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

`fortify.features` is defaulted by `HostConfig::fortifyFeatures()`, and only
while the key still holds Fortify's own shipped list — the signal that you have
not chosen. Two entries differ from that list: two-factor authentication and
passkeys are dropped, since neither has a view under `numerosis::auth.` nor the
columns its controllers write, so leaving them on registers screens that fail
only once somebody reaches them; and password reset follows
`PasswordResetFeature` so the two configs cannot disagree about whether it
exists. Publish `config/fortify.php` and edit the list and you own it outright
from then on, including turning 2FA back on — adding the missing views and
columns with it.

`numerosis.features` and `fortify.features` stay separate on purpose:
numerosis's gates tenancy/billing surfaces (invitations, the registration
wizard, OTP login), Fortify's gates auth screens (registration, password
reset, email verification). `registerFortify()` derives `fortify.features`
from `numerosis.features` for the two that overlap
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
| `Numerosis::registerRoutesUsing(Closure)` | all of `Numerosis::routes()`, including both `add*Routes()` sets |
| `Numerosis::registerMiddlewareUsing(Closure)` | all alias and group registration |
| `Numerosis::registerBroadcastingUsing(Closure)` | channel registration |
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
| `Auth\AdminGranted` | `Actions\Auth\PromoteFirstCentralUserToAdmin` or `Actions\Tenancy\PromoteFirstUserToAdmin` grants the admin role | `globalId`, `grantedBy` (always `null` today — both dispatch sites are automatic first-user promotion), `tenantId` (`null` for the central role) | Privilege-escalation audit |
| `Billing\PaymentSettled` | A payment settles after having previously failed or the tenant was suspended | `tenant`, `ownerId`. Broadcasts on `user.{ownerId}` | Clearing a payment-status banner |
| `Billing\PaymentFailed` | `invoice.payment_failed` webhook | `tenant` | The dunning notice |
| `Billing\TenantSuspended` | `Actions\Tenancy\SuspendTenant` | `tenant` | Access-revoked notification |
| `Billing\SubscriptionPlanChanged` | The `customer.subscription.updated` webhook, when the price actually changed. The only site — a host calling `SwapSubscriptionPlan` and an edit made in the Stripe dashboard both surface here, so neither fires twice | `tenant`, `tenantId`, `fromPriceId`, `toPriceId`, `direction` (`PlanChangeDirection::Upgrade`/`Downgrade`, falling back to `Upgrade` when a price matches no configured plan) | Entitlement recomputation, upgrade/downgrade emails |
| `Billing\SubscriptionCancelled` | `customer.subscription.deleted` webhook | `tenant`, `gracePeriodEndsAt`, `tenantId` | A retention flow — distinct from `TenantSuspended`, which is enforcement and can land days later |
| `Billing\CheckoutStarted` | `Actions\Billing\Checkout\StartSubscriptionCheckout`, once the domain is reserved | `domain`, `planId` | Funnel analytics |
| `Billing\CheckoutCompleted` | `Actions\Billing\Checkout\SettleCheckout` — the one point the card/Link path, the redirect return route and the `payment_method.attached` webhook all funnel through | `domain`, `planId`, `stripeSubscriptionId`. Fires whether or not the subscription settled immediately (a trial collects nothing upfront) | Funnel analytics; do not infer settlement from this alone |
| `Invitations\InvitationCreated` | `Actions\Invitations\SendInvitation`, which reuses the row for `(tenant_id, email)` | `invitation`, `invitationId` | The invitation email (`Listeners\Invitations\SendInvitationNotification`) |
| `Invitations\InvitationAccepted` | `Actions\Invitations\AcceptInvitation`, after the membership is attached | `invitation`, `invitationId`, `invitedByUserId`, `secondsUnaccepted`. Carries only what `Tenancy\MemberJoined` does not. That event fires automatically from `MembershipObserver::created()`, since acceptance attaches through the `tenants()` relation instead of dispatching it itself | Analytics on invite-to-accept latency; anything that needs the inviter, not just the joiner |
| `Tenancy\TenantProvisioned` | `Actions\Tenancy\MarkTenantProvisioned` | `tenant`, `ownerId`. Broadcasts on `user.{ownerId}` | The registration wizard's own poll for "ready" |
| `Tenancy\TenantProvisioningStarted` | `Actions\Tenancy\MarkProvisionInProgress` | `domain`, `globalId` | Progress UI, timing metrics. Not broadcast — nothing client-side listens for it |
| `Tenancy\TenantProvisioningFailed` | A provisioning step exhausts its retries | `domain`, `globalId`. Broadcasts on `user.{ownerId}` | Surfacing the failure to the user waiting on it |
| `Tenancy\TenantProvisioningCancelled` | A pending provision is cancelled | `globalId`. Broadcasts on `user.{ownerId}` | Same |
| `Tenancy\TenantRestored` | `Actions\Tenancy\RestoreTenant` clears a suspension | `tenant`, `ownerId`, `tenantId` | The "access restored" notification |
| `Tenancy\MemberJoined` | `Observers\MembershipObserver::created()` | `tenantId`, `globalUserId`, `role` (an `Enums\Tenancy\MembershipRole`), `invitedBy` | Seat-based billing, audit. Match `MembershipRole::Owner` instead of the string `'owner'` |
| `Tenancy\MemberRemoved` | `Observers\MembershipObserver::deleted()` | `tenantId`, `globalUserId`, `role` (an `Enums\Tenancy\MembershipRole`) | Seat-based billing, offboarding |
| `Tenancy\TenantDomainReserved` | `Actions\Tenancy\CreateTenantDomain` creates a `domains` row (only under `IdentificationMode::Subdomain`/`CustomDomain` — `Path` mode creates no row) | `tenantId`, `domain`, `mode` | DNS automation and certificate issuance under `CustomDomain` mode |

## Optional dependencies core still leans on

Core keeps `class_exists()`/`trait_exists()` seams for the packages it
`suggest`s rather than `require`s: `ryangjchandler/laravel-cloudflare-turnstile`
(`TurnstileFeature::isEnabled()`), `spatie/laravel-one-time-passwords`
(`OneTimePasswordFeature::available()`), `spatie/laravel-activitylog`
(`Support\Compat\LogsActivityIfInstalled`). **One seam per optional package,
not one per call site** — give the optional dependency exactly one predicate
and have every consumer ask it. One unguarded call site is a fatal, not a
disabled feature. `extends`, `implements` and `use <Trait>` resolve at
class-declaration time, so never name one of these symbols eagerly in a class
head; a method type hint or a guarded `new` is fine. See
`.ai/rules/optional-dependencies.md`.

## Where a package's own pieces live

Every migration, seeder and permission context lives in core: the tables and
rows have to exist wherever core does. `nvade/numerosis-ui` ships views and
Blade components only — no migrations, no routes, no features.

# Enum vocabulary sweep

**Status: not executed.** Written 2026-09-12 on branch
`refactor/provisioning-pipeline`. Nothing below has been built. Eight phases,
each independently landable and independently revertable; phases 1 and 2 carry
most of the value and phases 6–7 are cleanup that can be dropped without
harming the rest.

## Context

The package ships eight enums (`Enums/{Auth,Billing,Tenancy}/`) and they are
used well — `IdentificationMode`, `SocialProvider` and `TenantProvisionStatus`
each own their `match` dispatch and their config read. Everywhere else, the
same kinds of vocabulary live as bare strings duplicated across files, and
three of them have already drifted into disagreement with themselves.

Fourteen enums, grouped by what they buy:

- **drift already happened** — `SubscriptionStatus`, `Severity`, `FlashKey`,
  `PermissionAction`, `PermissionContext`, `SessionKey`, `SystemRole`
- **information currently discarded** — `SetupIntentStatus`, `FetchState`
- **cleanup** — `CardBrand`, `PaymentMethodType`, `TaxIdType`,
  `MiddlewareAlias`, `WizardStep`

## Constraints that shape every phase

Five facts that are cheap to verify now and expensive to discover mid-phase.

**Casting `subscriptions.stripe_status` to an enum breaks Cashier silently.**
`vendor/laravel/cashier/src/Subscription.php:189,210` compare
`$this->stripe_status === StripeSubscription::STATUS_INCOMPLETE` with `===`
against a string. An Eloquent cast makes both comparisons permanently false,
so `Subscription::incomplete()` and `::pastDue()` return `false` for every
row, with nothing red. The column stays a string; reads go through an accessor
returning the enum.

**`packages/ui` may not name a core symbol.**
`tests/Feature/PackageBoundariesTest.php:50` asserts nothing under
`packages/ui/{src,resources}` matches `/Nvade\\Numerosis(?!Ui)/`. The severity
palette lives in `packages/ui/resources/views/components/ui/`, so `Severity`
must be `Nvade\NumerosisUi\Enums\Severity` and core must reference *it*, not
the reverse. Core already `require`s `nvade/numerosis-ui` (`composer.json:42`),
so the direction works — but `packages/ui/src/` currently holds exactly one
file (`NumerosisUiServiceProvider.php`), so `src/Enums/` is a new base folder
in that package. **Decision needed before phase 2 starts** (see phase 2).

**Blade class references are covered, and already understand enums.**
`tests/Feature/BladeClassReferencesTest.php:28` calls `enum_exists()` alongside
`class_exists()`, and scans both `resources/views` and
`packages/ui/resources/views`. A view reaching an enum through `@use(...)` is
therefore checked; an inline FQCN is checked too but `.ai/rules/index.md`'s
preamble says use `@use`.

**`MiddlewareRegistrar::aliases()` is source-scanned, not behaviour-tested.**
`tests/Feature/ArchTest.php:140-158` reads the file text between
`public static function aliases()` and `public static function
csrfExceptions()` and fails on `Config::`, `config(`, `Facade`, `app(`,
`resolve(`. An enum case reference contains none of those, and enum constants
resolve without the container, so phase 7 is safe — but re-run `--filter=Arch`
rather than assuming.

**`Services/` has exactly two named exceptions and `TaxIdType` is one.**
`tests/Feature/ArchTest.php:167-170`. Phase 6 moves it out, so that list drops
to one entry in the same commit; leaving it there makes the test vacuous rather
than red.

## Working rules for every phase

- `vendor/bin/pint --dirty --format agent` **before** the tests, never after —
  Pint rewrites files and a run taken before its edits is not evidence for what
  is on disk.
- MySQL must be up: `docker start numerosis-mysql-1`.
- Iterate with `vendor/bin/pest --compact --filter=X`; run `composer test`
  (parallel, ~62s) at each phase boundary. Redirect to a file, never pipe to
  `tail`.
- PHPStan deltas are only meaningful cold-vs-cold, through a writable `tmpDir`
  (`.ai/rules/static-analysis.md`). Do not regenerate the baseline.
- Config files stay scalar. `config/numerosis.php` is published to hosts; an
  enum may *validate* a config value and must not *be* one. `IdentificationMode`
  shows the shape — the config holds `IdentificationMode::Subdomain->value`.
- Enums carry behaviour. If a `match` on the vocabulary exists anywhere, it
  moves onto the enum as a method; an enum that is only a list of cases has not
  paid for itself.

---

## Phase 1 — `SubscriptionStatus`

**New:** `src/Enums/Billing/SubscriptionStatus.php`, backed, 8 cases mirroring
`Stripe\Subscription::STATUS_*` (`vendor/stripe/stripe-php/lib/Subscription.php:70-77`):
`Active, Canceled, Incomplete, IncompleteExpired, PastDue, Paused, Trialing, Unpaid`.

Methods, each absorbing an existing call site:

- `isSettled(): bool` — `Active|Trialing`. Absorbs
  `Subscription::SETTLED_STATUSES` and `isSettledStatus()`.
- `isDelinquent(): bool` — `PastDue|Unpaid|IncompleteExpired`. Absorbs the
  inline array at `WebhookController:219`.
- `isPaid(): bool` — `Active` only. Absorbs `DefaultUnpaidTenantQuota:35`.
  This one is *deliberately* narrower than `isSettled()` — a trial has paid
  nothing and the quota exists to stop free tenant databases
  (`DefaultUnpaidTenantQuota:29-32` says so). Keeping both as named methods is
  the point of the phase: today that distinction survives only as a comment.

**Edits:**

| File | Change |
|---|---|
| `src/Models/Central/Subscription.php:65-78` | Delete `SETTLED_STATUSES`, `isSettledStatus()`. Add `status(): ?SubscriptionStatus` reading `tryFrom($this->stripe_status)`. Keep `isSettled()` delegating to it. **No `casts()` entry** |
| `src/Http/Controllers/Billing/WebhookController.php:214-223` | `$status = SubscriptionStatus::tryFrom(...)`; `match(true)` arms become `$status?->isDelinquent()` / `$status?->isSettled()` |
| `src/Services/Billing/DefaultUnpaidTenantQuota.php:35` | `!$tenant->subscriptions->first()?->status()?->isPaid()` |
| `src/Actions/Billing/Checkout/AssertPendingReservationIsFresh.php:27` | `->status() !== SubscriptionStatus::Canceled` |
| `src/Data/Billing/SubscriptionData.php:19` | `public SubscriptionStatus $stripe_status` — spatie/laravel-data casts backed enums natively |
| `src/Data/Billing/StripeSubscriptionData.php:48` | same, plus `fromStripe()` at `:55` |
| `src/Actions/Billing/Subscriptions/LinkSubscriptionToTenant.php:52` | feed the enum |
| `database/factories/Central/SubscriptionFactory.php:65` | `SubscriptionStatus::Active->value` — factories write the column, so `->value` |

**Tests:** `tests/Feature/Models/Central/SettledSubscriptionTest.php` already
datasets all six relevant statuses — convert it to enum cases and add `Paused`.
Add a test asserting Cashier's own `incomplete()`/`pastDue()` still return true
for a row with that `stripe_status`; that is the regression the no-cast rule
exists for, and nothing currently covers it.

~15 test files write `'stripe_status' => 'active'`. Those are DB writes and may
stay string literals — converting them is optional churn. Convert only where a
test asserts on the *read* side.

**Verify:** `--filter='Subscription|Webhook|UnpaidTenant|Checkout'`, then
`composer test`.

---

## Phase 2 — `Severity` + `FlashKey`

The messiest area in the repo, and the one phase with an open decision.

### Decision: where `Severity` lives

`packages/ui/src/` holds one file today. Two options:

1. **`Nvade\NumerosisUi\Enums\Severity`** in a new `packages/ui/src/Enums/`.
   Correct dependency direction, ui stays installable alone, core references it
   through the `require` it already declares. Costs a new base folder in that
   package.
2. Leave the palette as Blade arrays and give core only `FlashKey`. Cheaper,
   fixes the two flash-key conventions, leaves the three disagreeing palettes.

Option 1 is the recommendation and the rest of this phase assumes it. **Do not
start phase 2 without confirming it** — CLAUDE.md requires approval for a new
base folder.

### `Severity`

`Nvade\NumerosisUi\Enums\Severity`: `Success, Error, Warning, Info`.

- `classes(): string` — the `bg-*-bg text-*-text` token pair. One definition,
  replacing `alert.blade.php:23-44` and `badge.blade.php:15-20`.
- `icon(): string` — absorbs `toast.blade.php:45-47`'s per-type Flux icon.
- `static fromAlias(?string $value): ?self` — the one place `danger`→`Error`
  and `message`→`Info` are resolved, replacing `alert.blade.php:55,67` and
  `toasts.blade.php:26`.

Blade components keep their string `@props` (a Blade attribute is a string and
that is the host-facing API), and resolve through `Severity::fromAlias()`
inside the `@php` block. `badge.blade.php` keeps its non-severity `default`
variant as a separate branch — it is not a severity and folding it in would be
the fourth disagreeing map.

`info-box.blade.php` already forwards to `alert`; it needs no change beyond its
comment, which currently documents a vocabulary the enum will own.

### `FlashKey`

`Nvade\Numerosis\Enums\FlashKey`, core-side: `Status`.

One key, deliberately. Today there are two conventions — `'status'` in
Invitations/Social/Profile (`ShowInvitationController:29,30`,
`AcceptInvitationController:31`, `StoreInvitationController:29`,
`DestroyInvitationController:23`, `HandleProviderCallbackController:40,44,53`,
`DestroySocialAccountController:27`) and `'success'|'info'|'error'` in Billing
(`CompleteRedirectCheckout:39,57,67,71,76`, `StartLocalCheckout:40`). Views pay
for both: `auth-session-status` reads `session('status')` in 8 blades while
`toasts.blade.php:19` and `alert.blade.php:64` each scan a hand-synced list of
the other four.

Converge on one key carrying a `[Severity, message]` pair. Billing's six sites
become `->with(FlashKey::Status->value, [Severity::Error, $message])`; the two
blade scan-loops collapse to a single read. **This changes the flashed shape**,
so the legacy string-keyed reads in `alert.blade.php:64-67` and
`toasts.blade.php:19-26` stay for one cycle as a compatibility arm, with the
`x-numerosis::ui.alert :session="..."` API unchanged for hosts.

Separately: `'verification-link-sent'` (`Livewire\Settings\Profile:59`) is a
magic flash *value*, compared in `settings/profile.blade.php:21` with `===` and
`auth/verify-email.blade.php:7` with `==`. Give it a case on a small
`Enums\Auth\VerificationNotice` or fold it into `FlashKey` — either way, fix the
`==`/`===` inconsistency in the same edit.

**Tests:** new unit tests for `Severity::fromAlias()` covering both aliases and
an unknown value; a render test per blade component asserting `danger` and
`error` produce identical markup (that equivalence is currently asserted
nowhere and is the thing most likely to regress). Existing invitation and
social feature tests assert on flashed strings — update them to the new shape.

**Verify:** `--filter='Invitation|Social|Profile|Checkout|Alert|Badge|Toast'`,
then `composer test`. Blade changes are not covered by PHPStan, so a browser or
render test is the only evidence here.

---

## Phase 3 — `PermissionAction`, `PermissionContext`, `SystemRole`

Three small enums, one phase, because they are all read by the same seeders.

**`Enums\Auth\PermissionAction`** — `ViewAny, View, Create, UpdateAny, Update,
DeleteAny, Delete, Restore, ForceDelete`.

- `src/Models/Permission.php:48` — `defaultActions()` returns
  `PermissionAction::cases()`, typed `list<PermissionAction>`.
- `actionsFor(string $context): list<string>` **keeps returning strings**. The
  `additionalActions()` seam lets a host add arbitrary action names
  (`Permission.php:63-76`) and that seam must not close. Map core's cases to
  `->value` and spread the host's strings after.
- `src/Policies/Concerns/ChecksContextPermissions.php:23-75` — the nine literal
  action names become cases; `permission()` takes `PermissionAction`.

**`Enums\Auth\PermissionContext`** — `Features, Permissions, Roles, Tenants,
Subscriptions, PaymentPlans, Users`.

- `database/seeders/RoleAndPermissionSeeder.php:59-67` — `contexts()` returns
  `array_map(fn (PermissionContext $c) => $c->value, PermissionContext::cases())`.
  It stays `list<string>` so the documented "subclass and bind" extension at
  `:57` still works.
- The 8 policies (`TenantPolicy:15`, `RolePolicy:15`, `PermissionPolicy:15`,
  `UserPolicy:16`, `SubscriptionPolicy:15`, `PaymentPlanPolicy:15`,
  `PlanFeaturePolicy:15`, `InvitationPolicy:22`) return
  `PermissionContext::X->value`. `permissionContext(): string` keeps its return
  type — a host policy may name a context core does not ship.

`.ai/rules/package-boundaries.md` records that a *missing* context throws
`PermissionDoesNotExist`. An arch-style test asserting every core policy's
`permissionContext()` resolves to a `PermissionContext` case closes that gap;
add it.

**`Enums\Auth\SystemRole`** — `Admin`. The spatie `roles.name` value, distinct
from `MembershipRole` (which is the `memberships.role` column and already an
enum). Four sites: `PromoteFirstUserToAdmin:30`, `PromoteFirstCentralUserToAdmin:29`,
`Models/Central/Tenant.php:226`, `database/seeders/Concerns/SeedsAdminRole.php:55`.
A one-case enum is worth it here only because the four sites are in four
unrelated files and a rename today is a grep; if that reads as overkill, a
`const` on `SeedsAdminRole` is an acceptable smaller answer.

**Tests:** existing policy tests cover the behaviour; they should pass
unchanged, which is the point. Add the policy↔context arch test.

**Verify:** `--filter='Policy|Permission|Role|Seeder'`, then `composer test`.

---

## Phase 4 — `SessionKey`

`Enums\SessionKey` (no domain subfolder — it spans auth, tenancy and billing):
`LoginEmail = 'login.email'`, `LoginRemember = 'login.remember'`,
`PendingInvitation = 'pending_invitation'`,
`TenancySessionTenant = 'tenancy.session_tenant'`,
`RegistrationWizardState = 'registration.wizard_state'`,
`UrlIntended = 'url.intended'`.

**Edits:**

| File | Key |
|---|---|
| `src/Actions/Auth/RedirectIfOneTimePasswordAuthenticatable.php:35` | writes `login.email` + `login.remember` |
| `src/Http/Controllers/Auth/OneTimePasswordChallengeController.php:31,40,61,62` | reads/pulls/forgets both |
| `src/NumerosisServiceProvider.php:621` | reads `login.email` for the rate-limiter key |
| `src/Http/Controllers/Invitations/ShowInvitationController.php:36` | writes `pending_invitation` |
| `src/Http/Controllers/Invitations/AcceptInvitationController.php:34` | forgets it |
| `resources/views/auth/register.blade.php:14` | reads `session('pending_invitation.email')` — **a nested key no PHP file names.** Give `SessionKey` a `nested(string $field): string` helper and reach it through `@use` |
| `src/Http/Middleware/EnsureSessionMatchesTenant.php:25` | `const SESSION_KEY` deleted, callers move to the enum |
| `src/Features/Tenancy/RegistrationWizardFeature.php:33` | same; callers at `Registration:106`, `Checkout:397`, `CompleteRedirectCheckout:74` |
| `src/Http/Responses/Auth/NumerosisLoginResponse.php:41` | `url.intended` is Laravel's key, not ours — include it as a case for findability, or leave it. Either is defensible; leaving it is fewer moving parts |

Deleting the two `const SESSION_KEY` is the part with host-visible blast
radius: both are `public`. Breaking changes are free here (no installs), but
mention them in the commit body.

**Tests:** OTP challenge and invitation feature tests already exercise every
key. Add one asserting the Blade nested read resolves — currently the only
thing linking `pending_invitation.email` to the writer is convention.

**Verify:** `--filter='OneTimePassword|Invitation|Registration|Session'`, then
`composer test`.

---

## Phase 5 — `SetupIntentStatus` and `FetchState`

**`Enums\Billing\SetupIntentStatus`** — `RequiresPaymentMethod,
RequiresConfirmation, RequiresAction, Processing, Canceled, Succeeded`
(Stripe's own set).

- `src/Actions/Billing/Checkout/ResolveSetupIntent.php:60`,
  `ResolveAttachedPaymentMethod.php:58` — `!== 'succeeded'` becomes
  `SetupIntentStatus::tryFrom(...) !== SetupIntentStatus::Succeeded`.
- `src/Data/Billing/Checkout/ResumedCheckout.php:20` — replace
  `bool $alreadySucceeded` with `SetupIntentStatus $status`, built at
  `ResumeCheckout.php:48`. Callers asking the old question use
  `$status === SetupIntentStatus::Succeeded`; callers that want to distinguish
  `RequiresAction` from `RequiresPaymentMethod` now can. That distinction is
  the phase's whole justification — check `billing-checkout.md` and the resume
  flow for a UI branch that wants it before assuming there is no consumer.

**`Enums\FetchState`** — `NotAttempted, Loaded, Failed`.

- `src/Livewire/Billing/Checkout.php:70,79` — the two `bool $*FetchFailed`
  props become `FetchState`. Livewire 4 supports backed enums as public
  properties, and both are `#[Locked]`, so the client cannot set them.
- Set sites: `:120,121` (both failed), `:131` (billing failed), `:150` (from
  `$result->fetchFailed`).
- `resources/views/livewire/billing/checkout.blade.php:17,23` — the two `@if`
  branches. Today "Stripe call failed" and "customer has no saved cards" render
  identically for the empty case; after this they can differ. Decide whether
  they *should* — if the answer is no, the enum still removes the ambiguity from
  the type without changing the markup.
- `src/Data/Billing/SavedBillingDetails.php:20` and
  `ReusablePaymentMethods.php:19` — `bool $fetchFailed` becomes `FetchState`.

**Tests:** `tests/Feature/Livewire/Billing/CheckoutTest.php` covers the failure
paths; assert on the enum. Add the missing case: fetch succeeded, zero saved
methods — currently indistinguishable from failure and therefore untested.

**Verify:** `--filter='Checkout|SetupIntent|PaymentMethod'`, then `composer test`.

---

## Phase 6 — `CardBrand`, `PaymentMethodType`, `TaxIdType`

**`Enums\Billing\CardBrand`** — `Visa, Mastercard, Amex, Discover, Diners, Jcb,
UnionPay`, with `label(): string` and `chipClasses(): string`. Moves the 7-case
`match` out of `resources/views/components/billing/saved-payment-method-option.blade.php:7-16`
and under PHPStan. The view reaches it through `@use` and falls back to the
unbranded chip on `tryFrom() === null`.

Fix `src/Actions/Billing/FetchReusablePaymentMethods.php:53` in the same edit:
`brand: $pm->card->brand ?? 'card'` defaults to a value that is not a brand and
lands in the view's `default` arm by accident. Make
`SavedPaymentMethodOption::$brand` nullable and drop the fallback.

**`Enums\Billing\PaymentMethodType`** — `Card, Link, Ideal, Bancontact,
SepaDebit, Giropay, Eps, Blik`.

- `src/Actions/Billing/Checkout/ResolveSavedPaymentMethod.php:21` —
  `REUSABLE_TYPES` becomes `PaymentMethodType::Card->isReusable()` or a static
  `reusable(): list<self>`.
- `src/Actions/Billing/FetchReusablePaymentMethods.php:41` — the Stripe filter.
- `src/Livewire/Billing/Checkout.php:311-321` — `resolvePaymentMethodOrder()`
  currently ends in `array_filter($order, is_string(...))`, which accepts
  `['banana']`. Validate through `PaymentMethodType::tryFrom()` and drop
  unknown entries. `config/numerosis.php:300-314` stays scalar.

**`Enums\Billing\TaxIdType`** — convert `src/Services/Billing/TaxIdType.php`
in place: `GbVat = 'gb_vat'`, `EuVat = 'eu_vat'`, with the 28-country map as a
private const and `static forCountry(?string $country): ?self`. Callers
(`SyncBillingAddress`, the tenant Billing page's add/replace action) take
`->value` where they hand it to Stripe.

Two bookkeeping edits that must land in the same commit or a test goes vacuous:

- `tests/Feature/ArchTest.php:167-170` — remove `Services\Billing\TaxIdType`
  from the exceptions list, leaving `BillingService` alone.
- `.ai/rules/architecture-conventions.md` names both exceptions in prose —
  update it.

**Verify:** `--filter='Arch|TaxId|PaymentMethod|BillingAddress'`, then
`composer test`.

---

## Phase 7 — `MiddlewareAlias`, `WizardStep`

**`Enums\MiddlewareAlias`** — `TenancyAuth = 'tenancy.auth'`,
`PasswordConfirmIfSet = 'password.confirm.if-set'`,
`TenancySubscription = 'tenancy.subscription'`,
`TenancyIdentification = 'tenancy.identification'`,
`TenancyRoute = 'tenancy.route'`, `TenancySession = 'tenancy.session'`.

- `src/Boot/MiddlewareRegistrar.php:45-56` — keys become `->value`.
- `:70-72` — the `tenant` group re-types three of them; use the cases.
- `routes/tenant.php:47,69` — two more.

The literals-only constraint at `MiddlewareRegistrar:31-35` holds: enum cases
are compile-time constants, nothing resolves through the container, and the
ArchTest source-scan looks for `Config::`/`config(`/`Facade`/`app(`/`resolve(`,
none of which an enum reference contains. Re-run `--filter=Arch` anyway.

Leave the group names (`tenant`, `universal`) as strings — `web` in that same
list is Laravel's, so an enum would cover two of three and read worse than
three literals.

**`Enums\Tenancy\WizardStep`** — `CompanyInfo, TechnicalSetup, Plan, Payment`,
with `componentClass(): class-string` and `alias(): ?string`.

`src/Features/Tenancy/RegistrationWizardFeature.php` lists the same four steps
twice — `SHIPPED_STEP_ALIASES` (`:44-48`, three entries) and the default config
(`:54-59`, four). `Payment` is absent from the first because its natural alias
collides with Cashier's published `payment.blade.php`, which the enum expresses
as `alias(): ?string` returning null instead of as a comment explaining an
absence. The registration loop at `:82-95` keys off the enum and skips null
aliases.

The default config value stays `list<class-string>` — a host replaces it
wholesale with its own step classes, so it cannot become a list of core enum
cases. Build it as `array_map(fn (WizardStep $s) => $s->componentClass(),
WizardStep::cases())`.

`.ai/rules/tenant-registration-wizard.md` describes the alias mechanism; check
whether it needs updating in phase 8.

**Verify:** `--filter='Arch|Middleware|Registration|Wizard'`, then
`composer test`. Route-level, not unit-level — `.ai/rules/middleware-registration.md`
records that a middleware unit test stays green when nothing applies the
middleware to a route.

---

## Phase 8 — rules and docs

- **New rule file** `.ai/rules/enums.md` with `paths: ['src/Enums/**']`,
  recording the three facts a future change will otherwise rediscover: the
  Cashier no-cast trap (phase 1), the `packages/ui` dependency direction for
  `Severity` (phase 2), and the "core enum, host string" pattern that
  `PermissionContext`/`PermissionAction`/`WizardStep` all follow so the
  extension seams stay open. Add its row to `.ai/rules/index.md` **by hand in
  the same edit** — `record-rule` regenerates the table from `paths:`
  frontmatter and discards the preamble, so diff `index.md` afterwards either
  way.
- `.ai/rules/architecture-conventions.md` — the `Services/` exception list
  (phase 6).
- `.ai/rules/billing-checkout.md` — the stale-`stripe_status` note now has an
  enum behind it.
- `docs/extending.md` — `PermissionContext`, `PermissionAction` and
  `WizardStep` are host-facing seams and their "core gives you cases, you may
  still pass strings" contract belongs there, not in a comment.
- `docs/features.md` — only if phase 2 changes the flash contract a host reads.
- Move this plan to `.claude/plans/archive/` and update
  `.claude/plans/README.md`'s Live table **in the same pass** as the last phase
  landing. Leaving it loose with an updated status line is the exact mechanism
  that let the README drift before.

---

## Sequencing and independence

Phases 1, 3, 4, 6, 7 touch disjoint files and can land in any order. Phase 2
is the only one needing a decision first. Phase 5 overlaps phase 1 in
`Livewire\Billing\Checkout` and `src/Data/Billing/` — run it after phase 1 to
avoid resolving the same file twice.

Nothing here depends on the outstanding phases 7–9 of
`glittery-growing-dewdrop.md`, but both branches touch `src/Livewire/Billing/`
and `src/Actions/Billing/Checkout/`. If that plan resumes first, rebase this
one onto it rather than the reverse — its changes are structural and these are
substitutions.

## Out of scope

Named so they are not rediscovered as omissions:

- `Enums\Tenancy\Context` already covers central/tenant.
- Tenant lifecycle is timestamp-based (`suspended_at`, `provisioned_at`), not a
  status column. Leave it.
- Feature names (`NamedFeature::NAME`) are a host-extensible registry; an enum
  would close it.
- `CacheKeys` and `RouteNames` are static-method registries whose members take
  varying arguments. An enum fits neither.
- `packages/ui`'s `size`/`variant` Blade props other than severity — Blade
  attributes are strings by nature and the host-facing API is the string.
- ISO country codes (`TaxIdType`'s 28, the 10 payment-method regions). A
  `Country` enum is a much larger commitment than these two call sites justify.

# Plan: rebuild invitations + social login from scratch

**Status:** executed 2026-09-04. See `invitations-social-redesign-HANDOFF.md`
for what shipped, the bugs fixed along the way, and what test coverage is
still thin.
**Depends on:** `.claude/plans/domain-events-expansion.md`, which **executes
first**. It creates `Actions\Tenancy\EnsureTenantUserExists`,
`MembershipObserver::created()`, the `TenantProvisioned` backfill listener, and
`Events\Tenancy\MemberJoined` — all of which this plan consumes rather than
builds. It also sets the event conventions this plan's own events follow.
**Executor:** a fresh session. Read this file top to bottom before touching code.

## What this is

The invitation feature and the social-login feature are being **replaced**, not
refactored. The existing implementations of both are to be deleted. Do **not**
read the old classes for guidance and do not port their structure — the design
below was written deliberately without reference to them. The only things
carried over are the two feature-name constants (`invitations`, and whatever
`ConfiguredProviders::FEATURE` holds today), so that a host's
`config/numerosis.php` keeps working.

Breaking changes are free here: nothing is deployed, there are no installs to
migrate. Do not add compatibility shims, deprecation paths, or "old table
still readable" code.

## Read before you start

- `CLAUDE.md` (toolchain: no Sail, Testbench, `composer test|analyse|format`).
- `.ai/rules/index.md`, then `auth-guards.md`, `auth-login.md`,
  `architecture-conventions.md`, `optional-dependencies.md`, `testing.md`,
  `views.md`, `tenant-caching.md`.
- `docs/architecture.md` (boot phases, central vs tenant data split),
  `docs/extending.md` (the seams; the Fortify customization table).
- Skills: `laravel-best-practices`, `laravel-attributes`, `laravel-data`,
  `pest-testing`, `socialite-development`.

House conventions that this plan assumes and does not restate per file:
`declare(strict_types=1)` everywhere; business logic in `AsAction` classes
under `src/Actions/**` invoked via `handle()`; cross-boundary data as
`spatie/laravel-data` objects under `src/Data/**`; swappable capabilities as
`Contracts\<Domain>` interfaces with `Services\<Domain>` implementations bound
in `NumerosisServiceProvider`.

---

## The two design decisions everything else follows from

### 1. Both features move to the **central** database and the central domain

An invitation is still an invitation **to one specific tenant** — the row keeps
a `tenant_id` foreign key and every authorization check about it still runs in
tenant context. What moves is only where the row is *stored*, and where the
invitee *lands*.

Reasons, in priority order:

| | Why central |
|---|---|
| The invitee has no tenant row | A tenant-DB invitation forces tenant identification and a tenant DB write before the invitee has any relationship to that tenant at all. The tenant-side `users` row should be created **by** acceptance, not exist before it |
| Acceptance writes a central table | Joining a tenant means a `memberships` row, which lives on the `central` connection. Accepting from tenant context means writing central data from inside `tenancy()`-initialized state for no gain |
| Registration is central | A brand-new invitee must register or log in first, and Fortify's `register`/`login` are reachable on the central domain |
| Cross-tenant reads become possible | "Your pending invitations" across every workspace is one query instead of N tenant databases |

Split of responsibility:

- **Issuing** stays on the **tenant** side (`routes/tenant.php`). The actor has
  a tenant session and the `invitations` permission context is a tenant-DB
  `spatie/laravel-permission` row — `hasPermissionTo()` only resolves inside
  tenancy.
- **Landing + accepting** are on the **central** side (`routes/web.php`).

### 2. Signed URLs replace the token column

The invitation link is `URL::temporarySignedRoute(...)`. There is no `token`
column and no hash comparison. The signature carries the expiry, the row
carries `expires_at` as the authoritative business fact, and the route key is
a ULID so ids are not enumerable.

Accepted trade-off, recorded on purpose: **a link cannot be "re-sent"
unchanged after expiry** — resending mints a new signature. That is fine.

---

## Facts about this codebase you will get wrong if you assume

1. **The tenant-side `users` row is not your problem — the events plan already
   solved it.** Attaching a `Membership` creates that row through three nets:
   `MembershipObserver::created()` synchronously, `BackfillTenantUsers` on
   `TenantProvisioned` if the tenant database did not exist yet, and stancl's
   queued `UpdateSyncedResource` as a last resort. All three call the same
   `Actions\Tenancy\EnsureTenantUserExists`. Read Phase 2 of
   `.claude/plans/domain-events-expansion.md` for the mechanism. **Accepting an
   invitation must not write a tenant-side user row itself.**
2. `CentralUser::tenants()` is a `belongsToMany` keyed on `global_id` →
   `memberships.global_user_id`, `->using(Membership::class)`, with pivot
   `role, invited_by, invited_at, joined_at`. Attach through the relation, not
   by `Membership::create()`, so the pivot class and its observer fire
   (`MembershipObserver` busts the `ForgetUserTenants` cache).
3. Tenant permissions are tenant-DB rows. **A missing permission row is a 500,
   not a 403** (Spatie throws `PermissionDoesNotExist`). The `invitations`
   context already exists in `database/seeders/Tenant/PermissionAndRoleSeeder.php`
   — keep the name.
4. Livewire cannot be trusted to gate a public method on prior state, and
   `throttle:` middleware never covers `/livewire/update`. Both the OAuth
   callback and the invitation accept endpoint are therefore **plain
   controllers on real routes**, never Livewire components
   (`.ai/rules/auth-login.md`, "Two lessons").
5. Route names that a feature flag can remove are read through
   `Support\Routes\RouteNames` / `numerosis.routes.names.*`, never as literals.
6. Views: `x-turnstile` compiles `@this`, so it only works inside a Livewire
   component (`.ai/rules/views.md`). Do not put it on the invitation accept
   form.
7. `nvade/numerosis-ui` may not reference core, `tenancy()`, or a named
   `route()` — `tests/Feature/PackageBoundariesTest.php` enforces it. Social
   buttons that know a route name stay in core's `resources/views`.

---

## Phase 0 — deletions

Delete outright. Run `composer analyse` after this phase and expect it to be
red until Phase 3 lands; that is the checklist of remaining references.

**Social:**
- `src/Http/Controllers/Socialite/` (whole directory)
- `src/Models/SocialiteLogin.php`
- `src/Contracts/Auth/SocialAccountRepository.php`
- `src/Services/Auth/EloquentSocialAccountRepository.php`
- `src/Actions/Auth/ConnectSocialAccount.php`, `DisconnectSocialAccount.php`
- `src/Support/Social/ConfiguredProviders.php` — **but first** note the literal
  value of its `FEATURE` constant; `SocialLoginFeature::NAME` becomes that
  literal string.
- `database/migrations/central/2025_06_04_102223_create_socialite_logins_table.php`
- `database/migrations/central/2025_06_04_095728_add_social_data_to_users.php`
- `resources/views/components/auth/buttons/grid.blade.php`, `social.blade.php`
- `src/Events/Auth/SocialAccountConnected.php`, `SocialAccountDisconnected.php`,
  `src/Listeners/Auth/LogSocialAccountConnected.php`,
  `LogSocialAccountDisconnected.php` (they are re-created in Phase 2 with new
  payloads — delete rather than edit)
- `tests/Feature/Features/SocialLoginDisabledTest.php`
- the `socialiteLogins()` relation + its two `@property-read` lines on
  `src/Models/Central/CentralUser.php`

**Invitations:**
- `src/Models/Tenant/Invitation.php`, `stubs/Models/Tenant/Invitation.stub`
- `database/migrations/tenant/2025_11_29_000000_create_invitations_table.php`
- `database/factories/Tenant/InvitationFactory.php`
- `src/Livewire/Invitations/` and `resources/views/livewire/invitations/`
  (`accept`, `already-accepted`, `expired`)
- `src/Http/Middleware/CheckInvitationStatus.php` **and** its two alias
  registrations: `NumerosisServiceProvider` (~line 455) and
  `Support/Numerosis.php` (~line 426, the `'invitation.status'` entry in the
  alias map)
- `src/Contracts/Invitations/`, `src/Services/Invitations/`
- `src/Actions/Invitations/AcceptInvitation.php`, `CreateInvitedUser.php`
- `src/Exceptions/Invitations/` (re-created in Phase 4)
- `src/Policies/InvitationPolicy.php` (re-created in Phase 4)
- `src/Notifications/InvitationSent.php`, `src/Events/Invitations/InvitationIssued.php`,
  `src/Listeners/Invitations/SendInvitationNotification.php`
- `tests/Feature/Invitations/` (whole directory)
- the `Invitation::class => null` entry in `config/numerosis/models.php`
- the `Invitation.stub` publish line in `NumerosisServiceProvider` (~line 306)
- the `InvitationPolicy` entry in the `$policies` map (~line 350)
- the `InvitationRepository` / `SocialAccountRepository` binds (~lines 178-179)
  and the `InvitationIssued`/`SocialAccount*` entries in the listener map
  (~lines 421-426)

Keep: `InvitationsFeature`, `SocialLoginFeature` (edited, not deleted); the
`invitations` permission context in the tenant seeder; `RouteNames`.

---

## Phase 1 — shared foundations

### `src/Enums/Auth/SocialProvider.php`

Backed string enum, the single source of truth for which providers core knows.
Replaces `numerosis.social.providers` for identity purposes.

```php
enum SocialProvider: string
{
    case Google = 'google';
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Discord = 'discord';
    case Facebook = 'facebook';

    public function label(): string;          // 'GitHub'
    public function icon(): string;           // heroicon name
    public function isConfigured(): bool;     // filled(config("services.{$this->value}.client_id"))

    /** @return list<string> */
    public static function configuredValues(): array;   // for the route constraint + button list

    /** @return list<self> */
    public static function configured(): array;
}
```

`isConfigured()` must test `client_id` specifically. Testing membership in
`array_keys(config('services'))` is wrong twice over: `config/services.php`
declares `'client_id' => env('GOOGLE_CLIENT_ID', '')` unconditionally, and
`config('services')` also holds `postmark`, `ses`, `resend`, `slack`,
`turnstile`.

### `config/numerosis/social.php`

Shrink to route names only — provider metadata is code now:

```php
'social' => [
    'routes' => [
        'redirect' => ['name' => 'social.redirect'],
        'callback' => ['name' => 'social.callback'],
    ],
],
```

Bump `config/numerosis/schema-version.php`; the shape changed. Update the key
table in `docs/architecture.md` if the description no longer fits.

### Data objects

`src/Data/Auth/SocialUserData.php` — the only shape anything downstream sees;
Socialite's own contract must not leak past `ResolveSocialUser`.

```
provider: SocialProvider
providerId: string
name: ?string
email: ?string
emailVerified: bool
avatarUrl: ?string
token: ?string
refreshToken: ?string
expiresAt: ?CarbonImmutable
```

`emailVerified` is per-provider: GitHub/Google expose it, others do not. Where
it is not knowable, it is `false`. Getting this wrong is the account-takeover
vector in Phase 2 — see the security rules.

`src/Data/Invitations/InvitationData.php` — `email`, `role`; validated via
`::validateAndCreate()` (see the `laravel-data` skill).

---

## Phase 2 — social login

### Migration — `database/migrations/central/<ts>_create_social_accounts_table.php`

```php
Schema::create('social_accounts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->string('provider');
    $table->string('provider_id');
    $table->string('name')->nullable();
    $table->string('email')->nullable();
    $table->string('avatar_url')->nullable();
    $table->text('token')->nullable();
    $table->text('refresh_token')->nullable();
    $table->timestamp('token_expires_at')->nullable();
    $table->timestamps();

    $table->unique(['provider', 'provider_id']);
    $table->unique(['user_id', 'provider']);
});
```

Both uniques are load-bearing: they make "one identity, one account" a database
guarantee rather than a check-then-insert race between two concurrent
callbacks.

### `src/Models/Central/SocialAccount.php`

```php
#[Table('social_accounts')]
#[Fillable([...])]
#[Hidden(['token', 'refresh_token'])]
#[UsePolicy(SocialAccountPolicy::class)]
#[UseFactory(SocialAccountFactory::class)]
final class SocialAccount extends Model
{
    use CentralConnection;
    use HasFactory;
}
```

Casts (`protected function casts()`, `#[Override]`): `provider` →
`SocialProvider::class`, `token`/`refresh_token` → `'encrypted'`,
`token_expires_at` → `'datetime'`. Add `belongsTo` `user()` resolved through
`Numerosis::model(CentralUser::class)`, plus a `hasMany socialAccounts()` on
`CentralUser` with the matching `@property-read` docblock lines.

Add `SocialAccount::class => null` to `config/numerosis/models.php` and a
factory at `database/factories/Central/SocialAccountFactory.php`.

### Routes — `routes/web.php`, inside the `SocialLoginFeature` block

| Method | URI | Controller | Name | Middleware |
|---|---|---|---|---|
| GET | `/auth/{provider}/redirect` | `RedirectToProviderController` | `social.redirect` | `throttle:social` |
| GET | `/auth/{provider}/callback` | `HandleProviderCallbackController` | `social.callback` | `throttle:social` |
| DELETE | `/settings/social/{socialAccount}` | `DestroySocialAccountController` | `social.destroy` | `auth:web`, `password.confirm` |

- `->whereIn('provider', SocialProvider::configuredValues())` on both OAuth
  routes. An unconfigured provider then 404s at routing rather than exploding
  inside a Socialite driver.
- Read the names through `config('numerosis.social.routes.*.name')`, as the old
  routes did.
- All three are central-domain routes: they go in `routes/web.php`, which is
  already registered once per configured central domain. Do **not** add a
  `->domain()` call — the surrounding group already has one, and a second one
  was a bug in the old file.
- Register a `social` rate limiter (`Limit::perMinute(10)->by($request->ip())`)
  in `NumerosisServiceProvider::registerAuthRateLimiters()`, next to `login`
  and the OTP limiter. Keep the docblock convention there.

Single-action `__invoke` controllers under `src/Http/Controllers/Auth/Social/`.
Middleware is declared in the route file, never in a constructor.

### Actions — `src/Actions/Auth/Social/`

| Action | Responsibility |
|---|---|
| `ResolveSocialUser` | `Socialite::driver($provider->value)->user()` → `SocialUserData`. The **only** Socialite touchpoint in the codebase. Faked wholesale in tests |
| `LoginWithSocialAccount` | Guest path: resolve-or-create the `CentralUser`, create the `SocialAccount`, then delegate to the existing `Actions\Auth\LoginUser` |
| `LinkSocialAccount` | Authed path: attach the identity to `Auth::user()` |

`HandleProviderCallbackController` branches on auth state — authed → link,
guest → login. Do not stash an "intent" in the session; it is forgeable and
unnecessary.

**The callback must finish through Fortify's `LoginResponse`**, exactly as a
password login does. Do not build a second post-login redirect mechanism, and
do not re-implement the central→tenant redirect the old `Socialite\Login`
controller carried.

### Security rules — each one gets a named test in Phase 5

1. **Identity match is `(provider, provider_id)` only.** Never email alone.
2. **Email fallback is conditional.** Link an OAuth identity to an existing
   account found by email *only if* `SocialUserData::$emailVerified` is true
   **and** the local account has a non-null `email_verified_at`. Otherwise
   redirect to `login` with "sign in first, then connect this account". An
   unconditional email link is account takeover on any provider that will
   issue an unverified address.
3. **Never `->stateless()`.** These are web routes; the `state` parameter is
   the CSRF defence.
4. **Tokens are `encrypted` casts and `#[Hidden]`.**
5. **No lockout on unlink.** `SocialAccountPolicy::delete()` returns false when
   the owner has no password *and* this is their last `SocialAccount`. The
   route also carries `password.confirm`.
6. **A user created from OAuth gets `email_verified_at` set only when the
   provider verified the address.**
7. `DestroySocialAccountController` authorizes via the policy (route-model
   binding + `$this->authorize()`), so one user cannot unlink another's.

### Events

`Events\Auth\SocialAccountLinked` / `SocialAccountUnlinked`, following the
events plan's conventions: past tense, carrying `globalUserId` and the provider
value as scalars alongside the `SocialAccount`, no broadcasting.
`SocialAccountUnlinked` must carry scalars only — the row is gone by the time a
queued listener runs.

Queued listeners write the activity log through the existing
`Support\Compat\LogsActivityIfInstalled` shim. Register them in the explicit
`Event::listen()` map in `packageBooted()` — **listener auto-discovery never
scans a package's `src/`**, so a convention-registered listener silently never
fires.

Add both to the events table `docs/extending.md` gains in the events plan's
Phase 6, rather than leaving them undocumented.

### `SocialLoginFeature`

Rewrite: `NAME` becomes the literal string that `ConfiguredProviders::FEATURE`
held. Keep the Discord `class_exists()` seam exactly as it is — it is a
`suggest`, and `.ai/rules/optional-dependencies.md` requires the string-literal
class reference rather than a `use` import.

### Views

- `resources/views/components/auth/social-buttons.blade.php` — iterates
  `SocialProvider::configured()`, renders one link per provider to
  `social.redirect`. Core, not `packages/ui` (it names a route).
- Include it from `resources/views/auth/login.blade.php` and the register view,
  gated on `Features::enabled(SocialLoginFeature::NAME)`.
- `resources/views/livewire/settings/connected-accounts.blade.php` + a
  `Livewire\Settings\ConnectedAccounts` component listing the user's
  `socialAccounts` with an unlink button posting the **DELETE route** (a real
  form, not a Livewire method — see fact 4). Register it with
  `Livewire::addComponent()` in `packageBooted()` alongside the others, and add
  a `settings/connected-accounts` route inside the existing `auth:web` group.

---

## Phase 3 — invitations, data layer

### Migration — `database/migrations/central/<ts>_create_tenant_invitations_table.php`

```php
Schema::create('tenant_invitations', function (Blueprint $table) {
    $table->id();
    $table->ulid('ulid')->unique();
    $table->string('tenant_id');
    $table->string('email');
    $table->string('role');
    $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('expires_at');
    $table->timestamp('accepted_at')->nullable();
    $table->timestamps();

    $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
    $table->unique(['tenant_id', 'email']);
});
```

Check the `tenants` table's actual primary key type in
`database/migrations/central/` before writing the FK — match it exactly.
`unique(tenant_id, email)` means re-inviting an address updates the existing
row (`updateOrCreate`) rather than growing duplicates.

### `src/Models/Central/Invitation.php`

```php
#[Table('tenant_invitations')]
#[Fillable(['tenant_id','email','role','invited_by_user_id','expires_at'])]
#[UsePolicy(InvitationPolicy::class)]
#[UseFactory(InvitationFactory::class)]
#[RouteKey('ulid')]
final class Invitation extends Model
{
    use CentralConnection;
    use HasFactory;
    use MassPrunable;

    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }
}
```

- Generate the ULID in a `creating` hook (`#[Boot]` or an observer — prefer
  `#[Boot]`; there is no observer worth its own file).
- `prunable()` returns rows accepted or expired more than 30 days ago. Wire the
  prune into the existing `config/numerosis/schedule.php` toggles; do not
  invent a second scheduling mechanism.
- `isExpired()` / `isAccepted()` helpers, used by the action and the view.
- Add `Invitation::class => null` to `config/numerosis/models.php`, a factory
  at `database/factories/Central/InvitationFactory.php` with `expired()` and
  `accepted()` states, and a `stubs/Models/Central/Invitation.stub` + its
  publish line in `NumerosisServiceProvider`.

### `src/Policies/InvitationPolicy.php`

Typed against the **tenant** `User` (issuing happens in tenant context, where
`hasPermissionTo()` resolves). Keep the `invitations` permission context and
the existing `delete` / `deleteAny` distinction: without `deleteAny
invitations`, a user may only revoke an invitation they sent themselves.
Register it in the `$policies` map in `NumerosisServiceProvider`.

**Acceptance is not a policy check** — the invitee has no tenant user yet.
Identity matching happens inside `AcceptInvitation`.

### Exceptions — `src/Exceptions/Invitations/`

`InvitationExpired`, `InvitationAlreadyAccepted`, `InvitationEmailMismatch`.
All extend the package's `DomainException` and implement `ShowsMessageToUser`,
so the package handler renders them; there are no per-state views any more
(`.ai/rules/exception-handling.md`).

---

## Phase 4 — invitations, flow

### Issuing (tenant side, `routes/tenant.php`)

Inside the existing `tenancy.auth:tenant` group, gated on
`Features::enabled(InvitationsFeature::NAME)`:

```
GET    /team/invitations              → index    (Livewire page listing pending invitations)
POST   /team/invitations              → StoreInvitationController   name team.invitations.store
DELETE /team/invitations/{invitation} → DestroyInvitationController name team.invitations.destroy
```

`src/Http/Requests/Invitations/StoreInvitationRequest.php`:

```php
#[RedirectToRoute('team.invitations.index')]
#[ErrorBag('inviteMember')]
final class StoreInvitationRequest extends FormRequest
```

`authorize()` → `Gate::allows('create', Invitation::class)`. Rules: `email`
required/email/lowercase; `role` required and `Rule::in(...)` against the roles
the tenant actually has. Reject inviting an address that is already a member of
this tenant.

`Actions\Invitations\SendInvitation::handle(Tenant, InvitationData, User $inviter)`:
`updateOrCreate` on `(tenant_id, email)`, set `expires_at = now()->addDays(7)`
and `invited_by_user_id` = the inviter's **central** user id (resolve via
`global_id`), then `event(new InvitationCreated($invitation))`.

### Notifying

`Listeners\Invitations\SendInvitationNotification implements ShouldQueue`:

```php
#[Queue('mail')]
#[Tries(3)]
#[Backoff([10, 60, 300])]
#[DeleteWhenMissingModels]
```

`Notifications\InvitationNotification` is routed `->route('mail', $invitation->email)`
(an on-demand notification — the invitee is not a `User`). The link is:

```php
URL::temporarySignedRoute(
    RouteNames::invitationShow(),
    $invitation->expires_at,
    $invitation,
);
```

That URL must resolve against the **central** domain. Verify it does under
each identification mode before trusting it (`.ai/rules/identification-modes.md`).

Update `config/numerosis/routes.php`: `invitation_show` becomes
`'invitations.show'` and a second name `invitation_accept` =>
`'invitations.accept'` is added, with a matching `RouteNames::invitationAccept()`.

### Landing + accepting (central side, `routes/web.php`)

```
GET  /invitations/{invitation}  name invitations.show    middleware: signed, throttle:6,1
POST /invitations/{invitation}  name invitations.accept  middleware: signed, auth:web, throttle:6,1
```

`ShowInvitationController`: if guest → `redirect()->guest(route('login'))` with
the invitation ULID in the session, so login/register bounces back here. If the
invitation is expired or accepted, throw the matching domain exception. Renders
`resources/views/invitations/show.blade.php` — a plain Blade page naming the
tenant and role, with a POST form to `invitations.accept` carrying the
signature. A plain form, not Livewire (fact 4).

Registration reached this way must **prefill and lock** the email field to the
invitation's address. Do this by reading the session key in the register view;
do not weaken `CreateRegisteredUser`'s validation to accommodate it.

`AcceptInvitationController` → `Actions\Invitations\AcceptInvitation`, all
inside one `DB::transaction()` on the central connection:

1. `throw_if($invitation->isAccepted(), InvitationAlreadyAccepted::class)`
2. `throw_if($invitation->isExpired(), InvitationExpired::class)`
3. `throw_unless(Str::lower($user->email) === Str::lower($invitation->email), InvitationEmailMismatch::class)`
   — a forwarded link must not join the wrong person.
4. `Actions\Tenancy\AddTenantMember::run($tenant, $centralUser, $invitation->role, $invitation->invited_by_user_id)`
5. stamp `accepted_at` + `accepted_by_user_id`
6. `event(new InvitationAccepted($invitation))`

Then redirect to that tenant's home (`tenant_route($domain, RouteNames::home())`).

### `src/Actions/Tenancy/AddTenantMember.php` — new, and thin

Read `src/Actions/Tenancy/AddTenantOwner.php` first, then fact 1 above.

The action **only attaches**. The tenant-side user row and the `MemberJoined`
event both come from `MembershipObserver::created()`, built by the events plan.

- return early if `$user->tenants()->where('tenants.id', $tenant->id)->exists()`
- `$user->tenants()->attach($tenant, ['role' => $role, 'invited_by' => $invitedBy, 'invited_at' => ..., 'joined_at' => now()])`
  — **through the relation**, so `Membership` saves as a model and its events
  fire. A raw `Membership::create()` or a query-builder insert skips the
  observer, the `MemberJoined` event and stancl's sync, and nothing fails
  visibly

If this action turns out to be nothing more than that attach plus the early
return, inline it into `AcceptInvitation` rather than shipping a one-line
action — but keep it if a second caller appears.

Double-submit safety is the database's: `accepted_at` plus the membership
existence check make a second POST a no-op redirect.

### Events this flow fires

`InvitationCreated` and `InvitationAccepted`, both under
`src/Events/Invitations/`, following the conventions in the events plan: past
tense, scalars alongside models, `ShouldDispatchAfterCommit` (both are
dispatched from inside a transaction), no broadcasting.

**`MemberJoined` is not this plan's to dispatch.** It fires automatically from
the observer when acceptance attaches the membership, which is the point of
routing acceptance through the relation. A host wiring seat-based billing gets
invitation-driven joins and owner-provisioning joins from one listener.

`InvitationAccepted` therefore carries only what `MemberJoined` does not: the
invitation, its inviter, and how long it sat unaccepted.

---

## Phase 5 — tests

`tests/Feature/Auth/Social/` and `tests/Feature/Invitations/`. Anything
touching tenancy composes `Nvade\Numerosis\Testing\CleansUpTenancyDatabases`
— `RefreshDatabase` transacts the default connection only, so central rows
survive rollback (`.ai/rules/testing.md`).

Social:
- new user via OAuth → `CentralUser` + `SocialAccount` created, logged in
- returning identity `(provider, provider_id)` → logs in, creates no duplicate
- verified provider email + verified local account → links
- **unverified provider email → refuses to link, redirects to login** (the
  takeover regression; verify it fails against a stubbed-out guard)
- unconfigured provider → 404
- authed user connects a second provider → linked
- unlink last credential with no password → 403
- unlink someone else's account → 403
- `email_verified_at` set only when the provider verified it
- feature disabled → all three routes 404

Invitations:
- issuing authorizes against the `invitations` permission (and 403s without it)
- unsigned or tampered URL → 403
- expired invitation → the expired message, no membership
- accepting while logged in as a **different** email → refused, no membership
- happy path → `memberships` row **and** a tenant-DB `users` row with the right
  `global_id`, both present **without running the queue** (assert with the
  queue faked). The observer's own regression test lives in the events plan;
  this one asserts the invitation flow actually reaches it, which a
  `Membership::create()` regression would break
- happy path dispatches `MemberJoined` (proves acceptance attaches through the
  relation) **and** `InvitationAccepted`
- double accept → idempotent, exactly one membership, `MemberJoined` dispatched
  once
- notification queued with the right recipient and a signed URL
- guest landing → redirected to login, returns to the invitation after auth
- feature disabled → routes 404

Two limiters to prove: the `social` limiter, and — per `.ai/rules/auth-login.md`
— **any** rate-limit regression test must use two tenants, since a
single-tenant test passes whether or not the key is tenant-scoped.

---

## Phase 6 — docs and rules

- `docs/features.md`: refresh both feature descriptions.
- `docs/architecture.md`: `Models/Central/` now lists `Invitation` and
  `SocialAccount`; `Models/Tenant/` lists `User` only; the central/tenant
  migration counts changed; the `social` config key description changed.
- `docs/extending.md`: note that adding a provider means a `SocialProvider`
  enum case plus `config/services.php` credentials — no longer a config array.
- `.ai/rules/auth-login.md`: add the OAuth callback's identity-matching rule
  (match on provider id; conditional email link) as a security invariant.
- `.ai/rules/index.md`: update the `auth-login.md` row note if it no longer
  covers social, and remove any line describing invitations as tenant-DB.
- The membership/pivot-sync rule is recorded by the events plan's Phase 6 — do
  not record a second copy. Add to it only if this flow taught you something it
  does not already say.
- `docs/extending.md`: add `InvitationCreated`, `InvitationAccepted`,
  `SocialAccountLinked` and `SocialAccountUnlinked` to the events table.

---

## Definition of done

```
vendor/bin/pint --dirty --format agent     # first — Pint edits files
composer analyse                            # PHPStan level 9, no new baseline entries
composer test
```

Pint before Pest, always, so tests are not re-run over reformatted files. Do
not add anything to `phpstan-baseline.neon` — new code goes in clean.

Also verify by hand, since no test covers it: `php artisan route:list --path=auth`
and `--path=invitations` on the workbench app show the routes on the central
domain only, and disappear when the feature is commented out of
`config/numerosis/features.php`.

# Phase 7 — Feature gating and the API surface

**Status: not executed.** Anchors verified against `e411bed`. Read
`README.md` in this directory first. Closes P21, P22, S39, S38, S41, S35.

Rules to read: `.ai/rules/package-boundaries.md`,
`.ai/rules/middleware-registration.md`, `.ai/rules/architecture-conventions.md`,
`.ai/rules/optional-dependencies.md`.

**This is the highest-risk phase in the plan.** A feature class named in config
but absent from disk is a container failure at boot, and the config and the
class must move in the same commit. Nothing here is mechanical.

## 7.1 — Three feature classes (P21)

Ungated today:

| Surface | Where |
|---|---|
| The read API | `routes/api.php`, loaded unconditionally |
| The API token screen | `routes/tenant.php:115` |
| The notification centre | `Livewire\Notifications\Center` |
| The preferences screen | `routes/web.php:153` |

**D3 settled: three feature classes.** Defaults, already decided:

- **Read API + token screen — one class, off by default.** They are one surface;
  a token with nothing to call is not a feature.
- **Notification centre — on by default.**
- **Preferences screen — on by default.**

Follow the thirteen existing classes in `src/Features/` exactly: a `NAME`
constant, the same interface, the same `bootstrap()` shape. The config block is
`config/numerosis.php:108-160`; on-by-default features are listed there, off-by-
default ones are present but commented out (see `impersonation` at `:150`,
`staff_panel` at `:146`, `usage_metering` at `:159`).

Gate the route files through `FeatureRegistry::enabled(...)` the way
`routes/tenant.php` already gates `ImpersonationFeature`, `ActivityLogFeature`,
`UsageMeteringFeature` and `InvitationsFeature`.

**Packaging moves in this commit.** `composer.json`'s autoload and any published
config must know about the new classes before the config naming them ships.

## 7.2 — The domains resource (P22)

`public-api-and-webhooks.md`'s foundations table says the verified-domain query
is exposed read-only. Nothing under `/api/v1` does — the file registers
`tenant`, `members`, `invitations` and `subscription` only.

Add the resource over the same `GetServableDomains` query, scoped by token
ability. Every existing route in that file carries an `ApiAbilities` gate;
this one does too. `GetApiAbilities` declares four contexts (`Tenants`, `Users`,
`Invitations`, `Subscriptions`) — a domains ability means extending that
enum/constant set, and `GetApiAbilities::forUser()` has to answer for it.

## 7.3 — Fold the second entrypoints back in (S39)

Statics sitting beside `handle()`, against the actions convention:

| File | Members |
|---|---|
| `src/Actions/Queries/GetServableDomains.php` | `includes()`, `servableQuery()` (`:76`) |
| `src/Actions/Queries/GetApiAbilities.php` | `ability()`, `forUser()` |
| `src/Actions/Queries/GetTenantMeters.php:38` | `forPlan()` |

Fold each into `handle()` or onto the model.

**S40's other half is here.** `GetServableDomains::servableQuery()` re-spells the
`Domain::servable()` scope (`src/Models/Central/Domain.php:81`). The action
calls the scope.

`GetTenantMembersPage::run()` (`:17`) is a legitimate paginating query, not a
second entrypoint. Leave it.

## 7.4 — Naming (S38, S41)

`Enums\MiddlewareAlias` mixes three conventions: bare `entitlement`, prefixed
`numerosis.api-token` and `numerosis.api-abilities`, dotted `tenancy.*`,
plus `password.confirm.if-set` and `impersonation`.

**Decision, already made: prefix the package's own aliases, keep the dotted
`tenancy.*` family as it is.** The argument that justified `numerosis.api-token`
— that the bare name is one a host or the framework might own — applies to
`entitlement` and `impersonation` equally, and not to `tenancy.*`, which is
already namespaced by its own prefix.

Changing an alias value touches: the enum, every `routes/*.php` use, every
`->middleware()` string, `MiddlewareRegistrar::aliases()` and
`tests/Feature/Boot/NumerosisSeamTest.php`, which asserts the alias map and the
`tenant` group contents literally. `.ai/rules/middleware-registration.md`:
`middlewareAliases()` is the single registry — do not create a second list.

`src/Data/Api/` holds `InvitationResource`, `MemberResource`,
`SubscriptionResource`, `TenantResource`, `UsageResource`. These are
`spatie/laravel-data` objects, not Laravel API Resources, and the tree's suffix
is `*Data`. Rename all five.

## 7.5 — The route file header (S35)

`routes/api.php:15-23` is a nine-line block carrying two facts: what the file is
and why it is read-only. `.ai/rules/general.md` caps a docblock at five prose
lines and one fact. Keep the fact a reader of that file needs; the rest belongs
in `docs/`, with no pointer back.

## Tests

- `tests/Feature/Api/ReadApiTest.php` (6 tests) and `ApiTokenTest.php` (10 tests)
  must stay green through 7.3, 7.4 and 7.5 unmodified.
- 7.1 needs a disabled-state test per feature, following
  `tests/Feature/Features/*DisabledTest.php` — there are nine of them to copy
  from. The read API being **off by default** means the existing API tests have
  to enable it; use `FeatureRegistry::forceForTesting()` the way
  `ImpersonationTest::setUp()` does.
- 7.2 needs: the resource returns the servable domains, a token without the
  ability is refused, and another tenant's domains never appear.
- `tests/Feature/Boot/NumerosisSeamTest.php` asserts alias strings literally and
  will move with 7.4. That is expected; it is an alias rename, not a behaviour
  change.

## Stop conditions

- If adding a feature class makes the container fail at boot, the class and the
  config disagree. Fix it in the same commit; never ship a half-registered
  feature.
- If the alias rename cascades past the files listed in 7.4, stop and report the
  extra call sites rather than editing them blind.

## Commit

```
feat(api): gate the read API, and expose the domains it promised

Three surfaces shipped unconditionally where the roadmap's standing
constraint is that new user-facing capability sits behind a Feature class:
the read API with its token screen, the notification centre, and the
preferences screen. The API is off by default -- a token with nothing to
call is not a feature -- and the other two are on.

/api/v1 gained the read-only domains resource its own foundations table
promised, over the same GetServableDomains query and behind a token
ability.

Four actions carried static entrypoints beside handle(), one of which
re-spelled the Domain::servable() scope rather than calling it. The
middleware aliases picked one naming convention, and src/Data/Api's five
*Resource classes took the tree's *Data suffix: they are laravel-data
objects, not Laravel API Resources, and read as the wrong thing.

Closes P21, P22, S39, S38, S41, S35 and S40's second half.
```

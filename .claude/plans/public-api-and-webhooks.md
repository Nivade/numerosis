# Public API, API tokens and outbound webhooks

**Status: partially executed 2026-09-17 on `feat/public-api-and-webhooks`.**
Wave 5 of `saas-readiness-roadmap.md`. Pieces 1 and 2 shipped — Sanctum tokens
bound to the tenant side, the token screen, the `/api/v1` read endpoints, docs.
**Piece 3, outbound webhooks (phases 4–6), is not built** and is what keeps this
file live; the plan's own risk section is the reason, quoted below: half a
webhook system is worse than none. See "What shipped" at the bottom.

## Where it stands

`RouteLoader::load()` takes an `apiPrefix`, builds a route group with the
`api` middleware and the prefix, and loads the **host's** `routes/api.php`
into it (`src/Routing/RouteLoader.php:98`). The package contributes no API
routes of its own, ships no token mechanism, and has no Sanctum dependency.

Outbound, the only webhook traffic is inbound from Stripe
(`src/Http/Controllers/Billing/WebhookController.php`). Customers cannot
subscribe to anything that happens in their tenant.

The result: the package is not integrable. Every customer integration is a
bespoke host-side build.

## Three separable pieces

Deliberately listed so they can ship in order rather than together.

1. **Authentication** — tokens, scopes, rotation, revocation.
2. **Read API** — a small, stable surface over tenant-visible resources.
3. **Outbound webhooks** — endpoints, signing, delivery, retries.

## Piece 1 — tokens

`laravel/sanctum`, personal access tokens, on the tenant side. A token belongs
to a membership, not to a user: the same person in two tenants holds two
tokens with different scopes, and revoking their membership must revoke the
token.

Scopes map onto the permission vocabulary that already exists — the seven
central and four tenant contexts crossed with nine actions
(`src/Enums/Auth/PermissionContext.php`, `PermissionAction.php`). Do not
invent a second vocabulary.

Build: token creation screen (shown once, never retrievable), last-used
display, revocation, expiry, and an optional IP allowlist. Tokens are
activity-logged on creation and revocation.

**Dependency decision: approved 2026-09-16.** `laravel/sanctum` becomes a
`require`. It is first-party, carries no tenancy opinions, and the
alternative — a hand-rolled token table — is worse in every dimension. Add it
in phase 1 of this plan, not before: an unused dependency in `composer.json`
is noise in every host's lock file until something imports it.

Two things travel with the `require`: a row in `DEPENDENCIES.md`, and a
decision about Sanctum's own migration. Its `personal_access_tokens` table
must land in `database/migrations/tenant/`, not `central/`, because tokens
belong to memberships and a central table would be shared across every
tenant.

## Piece 2 — read API

Start read-only. Versioned under `/api/v1`, tenant-identified by the same
middleware as the web routes, authorized by token scope, JSON:API-shaped or
plain — pick one and hold it.

Resources: the tenant itself, its members, its invitations, its subscription
and plan, its usage counters. Nothing central-only, nothing about other
tenants.

Responses come from `spatie/laravel-data` objects, which the package already
uses for DTOs, so the shape has one definition rather than two.

Rate limiting per token, distinct from the web limiters.

Writes come later and deliberately: every write endpoint needs the same
authorization reasoning as its screen, and the screens are still being built
in waves 1 and 2.

## Piece 3 — outbound webhooks

A tenant registers an endpoint and subscribes to event types. The package
already fires twenty-odd domain events carrying scalars with
`ShouldDispatchAfterCommit`, which is exactly the right shape to forward.

| Concern | Decision |
|---|---|
| Signing | HMAC over the raw body with a per-endpoint secret, timestamp in the signed payload to stop replay. Mirror Stripe's scheme — customers already know it |
| Delivery | Queued, never in the request path |
| Retries | Exponential backoff over roughly a day, then the endpoint is disabled and the tenant is notified |
| Visibility | Delivery log per endpoint: event, response code, attempts, next retry, with manual replay |
| Ordering | Not guaranteed. Say so in the docs rather than pretending |
| Payload | The event's scalars plus a stable resource reference. Never the whole model, which would leak fields as the schema grows |

## Phases

1. Sanctum, token model bound to membership, scopes from the permission
   contexts, revocation on `MemberRemoved`.
2. Token management screen.
3. `/api/v1` read endpoints with per-token rate limiting.
4. Webhook endpoint registration, secret generation, signing.
5. Dispatcher job, retry schedule, auto-disable, delivery log.
6. Delivery log screen and manual replay.
7. Documentation: `docs/` gains an API reference. Without it the API does not
   exist as far as a customer is concerned.

## Tests

- A token scoped to read cannot write; a token from tenant A cannot read
  tenant B, asserted directly rather than relying on middleware.
- Revoking a membership revokes its tokens.
- Expired tokens are rejected and the rejection is distinguishable from
  unauthorized.
- Rate limits are per token.
- Webhook signature verifies against a known-good fixture, and a replayed
  payload outside the timestamp window is rejected.
- Delivery retries follow the schedule and the endpoint auto-disables after
  the final failure, notifying the tenant once.
- A payload contains only the event's declared scalars — the test that stops a
  future model field from leaking silently.
- The `api` group is not tenant-identified by accident on central-only routes.

## Risks

- **This is three features.** If it must be cut, ship tokens and the read API,
  and leave outbound webhooks for its own session. Half a webhook system is
  worse than none, because customers build against it.
- **Tenant identification on API routes.** The `api` group sits outside both
  the central-domain loop and the `tenant` group today. Whichever identification
  the API adopts — header, subdomain, path — must be the same one the web side
  uses for that deployment, or a tenant's API and UI disagree about who they
  are.
- **Sanctum's own token table** lives wherever the connection points at
  creation time. Tenant-side tokens belong in the tenant database; get this
  wrong and every tenant sees a shared token table.

## What shipped (2026-09-17)

Phases 1, 2, 3 and 7. Phases 4, 5 and 6 — endpoint registration, HMAC signing,
the dispatcher with its retry schedule and auto-disable, the delivery log and
manual replay — remain open, on this plan's own advice.

- **`laravel/sanctum` `^4.2` is a `require`**, with its row in
  `DEPENDENCIES.md`. Its `personal_access_tokens` table is this package's own
  migration in `database/migrations/tenant/`, plus an `ip_allowlist` column.
- **A token belongs to a tenant user rather than to a `Membership` row.** The
  plan asked for the membership; the tenant `users` row is what Sanctum can be
  `tokenable` against, and it is already per workspace — so the invariant the
  plan wanted (`revoking a membership revokes the token`) is a listener,
  `RevokeApiTokensForRemovedMember`, and a test.
- **Abilities are `context.action` over the existing permission vocabulary**, and
  only read actions can be granted while the API is read-only: an ability no
  endpoint honours would read as a promise. `GetApiAbilities` filters what a
  caller asks for rather than trusting it.
- **Expiry answers 401 `token_expired` and an allowlist miss answers 403
  `address_not_allowed`.** The plan asked for the distinction; these are the two
  refusals Sanctum does not make.
- **The API stack is a new `tenant-api` middleware group** — identification and
  the route guard, no session — so the token carries the caller and a cookie
  cannot. Rate limiting is per token, asserted directly.
- **Sanctum's `abilities` alias is registered under
  `numerosis.api-abilities`**, because `abilities` is a name the framework's
  default stack owns and a host may mean something else by it.

Four changes from the readiness remediation, 2026-09-18 (`44b767c`):

- **The API is behind `Features\Api\ReadApiFeature`, off by default** (P21).
  It shipped unconditionally, against the roadmap's standing constraint that new
  user-facing capability sits behind a `Feature` class. Off, because a token with
  nothing to call is not a feature. The token screen is gated with it.
- **`/api/v1/domains` exists** (P22). The foundations table promised the
  verified-domain query read-only and nothing under `/api/v1` exposed it. It
  reads the same `GetServableDomains` query the TLS endpoints do, scoped by
  token ability.
- **The static entrypoints beside `handle()` are gone** (S39), including
  `GetApiAbilities::only()`, and `GetServableDomains` calls the
  `Domain::servable()` scope instead of re-spelling it.
- **`src/Data/Api/*Resource` are `*Data`** (S41). They are `spatie/laravel-data`
  objects, and `*Resource` reads as a Laravel API Resource, which they are not.
  The middleware aliases picked one naming convention in the same pass (S38).

Two traps found on contact, both recorded in `.ai/rules/`: the test harness's
tenant template databases survive between runs, so a new tenant migration needs
them dropped; and a guard memoizes its user for the life of the application, so a
test making two requests either side of a revocation has to call
`auth()->forgetGuards()` or it asserts nothing.

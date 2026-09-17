# Public API

Read-only, versioned under the host's API prefix as `/api/v1`, authenticated by
Sanctum tokens issued per tenant user.

Writes are deliberately absent. Every write endpoint needs the same
authorization reasoning as the screen that already does that job, and an API
that writes before that reasoning exists is the wrong kind of stable.

## Tokens

A token belongs to a **tenant user**, not to a central account: the same person
in two workspaces holds two tokens with their own abilities, and losing a
membership deletes that workspace's token
(`Listeners\Tenancy\RevokeApiTokensForRemovedMember`). Sanctum's table lives in
the tenant database for the same reason.

Issue one on the tenant screen at `api-tokens`, or from
`Actions\Auth\Api\CreateApiToken`. The plaintext value is shown once; only its
hash is stored.

| Property | Behaviour |
|---|---|
| Abilities | `context.action` pairs over `Enums\Auth\PermissionContext` and `PermissionAction` — never a second vocabulary. Only read actions can be granted while the API is read-only, so an ability nothing enforces cannot be handed out |
| Expiry | `numerosis.api.token_expiry_days`, null meaning never. An expired token answers 401 with `token_expired`, distinct from a 403 for a missing ability |
| Address allowlist | Optional. Empty means unrestricted; populated is exhaustive, and a call from elsewhere answers 403 `address_not_allowed` |
| Rate limit | `numerosis.api.rate_limit` per minute **per token**, so two tenants behind one address cannot spend each other's budget |

## Endpoints

Every endpoint reads the tenant from tenancy, never from the request: the token
was issued inside one workspace and the identification middleware has already
decided which.

| Method | Path | Ability | Answers |
|---|---|---|---|
| GET | `/api/v1/tenant` | `tenants.view` | The workspace: id, name, suspended, closed, created_at |
| GET | `/api/v1/members` | `users.viewAny` | One entry per membership: global_id, name, email, role, joined_at |
| GET | `/api/v1/invitations` | `invitations.viewAny` | Pending invitations: email, role, expires_at |
| GET | `/api/v1/subscription` | `subscriptions.view` | Status, plan slug, trial and end dates, plus current-period usage per meter |

Responses are `Data\Api\*` objects, declared field by field. A column added to a
model later does not appear here until somebody adds it on purpose — the payload
shape is the contract, and `tests/Feature/Api/ReadApiTest.php` asserts it.

No response carries a Stripe customer or subscription id: an integration has no
use for one and a leaked one is an account identifier.

## Identification

The API routes sit in the `tenant-api` middleware group: tenancy identification
and the route guard, without the session stack — the token carries the caller, so
a cookie must not. Identification follows
`numerosis.tenancy.identification.mode`, the same one the web side uses, so a
tenant's API and UI never disagree about who they are.

## Not shipped yet

Outbound webhooks — endpoint registration, HMAC signing, queued delivery with
retries, and a delivery log — are planned in
`.claude/plans/public-api-and-webhooks.md` and deliberately not built here. Half
a webhook system is worse than none, because customers build against it.

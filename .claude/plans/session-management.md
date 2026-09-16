# Session management and device revocation

**Status: not executed. Written 2026-09-16.** Wave 3 of
`saas-readiness-roadmap.md`.

## The gap

There is no way to see where an account is logged in, and no way to log it
out anywhere but here. `src/Listeners/Auth/EndOtherGuardSession.php:15`
handles one narrow case — ending the other guard's session on logout — and
that is the whole of session control in the package.

Consequences, in order of how bad they are:

1. A stolen session cannot be revoked. Changing the password does not end it
   unless something invalidates it, and nothing does.
2. A member removed from a team keeps working until their session expires.
   `team-members-management.md` names this as its open risk.
3. A user cannot answer "am I still logged in on that old laptop", which is
   the question that makes the feature legible to customers.

## Prerequisite: a session driver that can be listed

Listing sessions requires the `database` session driver (or Redis with a
per-user index). `HostConfig` normalizes plenty of host config already, so the
package can *prefer* database sessions and warn through `numerosis:install
--verify-only` when the host uses `file` or `cookie`, without forcing it.

The feature degrades honestly: with an unlistable driver, the screen says so
and offers only "log out everywhere", which works through password-hash
invalidation regardless of driver.

## Two mechanisms, both needed

| Mechanism | Covers |
|---|---|
| Session table listing and per-row delete | "Show me my devices, kill that one" |
| `Auth::logoutOtherDevices()` (rehashes the password stamp) | "Kill everything but this", works on any driver, survives a stolen session |

The second is Laravel's and needs `AuthenticateSession` middleware in the
relevant groups. Adding that middleware to the `tenant` group has to be done
carefully: the group is `['web', 'tenancy.identification', 'tenancy.route',
'tenancy.session']` and `EnsureSessionMatchesTenant` must stay after
`StartSession`. `AuthenticateSession` goes after both.

## Phases

### 1. Session store abstraction

`Contracts\Auth\SessionRegistry` with a database implementation, so the screen
does not query the sessions table directly and a host on Redis can bind its
own. Methods: list for a user, forget one, forget all but current.

### 2. Enrich what is stored

The sessions table carries IP and user agent already. Parsing the agent into
"Firefox on macOS" is presentation, not storage — do it in the component.
Last-active comes from `last_activity`. No new columns.

### 3. Screen

`Livewire\Settings\Sessions`: current session marked, others listed with
device, IP, location-free (no GeoIP dependency — `torann/geoip` is gone and is
not coming back), last active. Revoke per row, revoke all others behind
password confirmation.

### 4. Both guards

A central user and their tenant identities are separate sessions. The screen
lists both, labelled by tenant, or it answers the question wrongly. This is
the part that is specific to this package and has no Laravel default.

### 5. Automatic revocation

Three triggers, each a listener on an event that already exists or is added by
its own plan:

| Event | Action |
|---|---|
| Password changed | Log out other devices |
| `MemberRemoved` | End that user's sessions for that tenant only |
| 2FA disabled or factor cleared by staff | Log out other devices |

The `MemberRemoved` listener is what closes the risk recorded in
`team-members-management.md`.

## Tests

- Revoking a session makes that session's next request unauthenticated.
- Revoke-all-others leaves the current session alive and kills the rest,
  asserted with two authenticated clients.
- `MemberRemoved` ends only the sessions for that tenant, leaving the user's
  other tenants and their central session intact. This is the assertion that
  matters — over-broad revocation is a support problem and under-broad is a
  security one.
- Password change invalidates other sessions.
- The screen degrades to revoke-all-only under the `file` driver without
  throwing.
- `AuthenticateSession` in the tenant group does not break
  `EnsureSessionMatchesTenant`'s ordering guarantee.

## Risks

- **Middleware ordering.** Adding `AuthenticateSession` to the tenant group
  touches the one ordering the architecture doc calls out explicitly. Test the
  cross-tenant identity case (user id 2 on tenant A versus tenant B) after the
  change, not just the happy path.
- **Session table growth.** Database sessions on a busy fleet need pruning;
  Laravel's own `session:prune` handles it but nothing schedules it here.

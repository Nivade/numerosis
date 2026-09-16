---
paths:
  - 'src/Policies/**'
  - 'src/Http/Controllers/**'
  - 'src/Actions/Invitations/**'
  - 'routes/tenant.php'
---
# Central rows reached from tenant routes

## Route-model binding on a central table has no tenant scope

`tenant_invitations` is the first central table keyed by `tenant_id` that a
**tenant-domain** route binds directly (`DELETE team/invitations/{invitation}`
in `routes/tenant.php`). `Models\Central\Invitation` uses `CentralConnection`,
so implicit binding queries the central database whatever tenancy is
initialized, and `#[RouteKey('ulid')]` resolves any ULID from any tenant.

Nothing in the request lifecycle narrows that. `tenancy.identification` picks
the tenant for the *connection*, not for a model that has deliberately opted
out of it.

The authorization layer does not close it either, unless it is written to.
`database/seeders/Tenant/PermissionAndRoleSeeder.php` gives the `admin` role
every action in every context, so `deleteAny invitations` is true for an admin
of **every** tenant, and `ChecksContextPermissions::delete()` short-circuits on
exactly that permission. An admin of tenant B could revoke tenant A's
invitation. The ULID is not a secret that stops it: it is in the invitee's
emailed link and rendered on the issuing tenant's own page.

**Compare the row's tenant to the ambient one inside the policy, not the
controller**, so a second call site cannot skip it:

```php
$tenant = tenant();

if (! $tenant instanceof TenancyTenant || $invitation->tenant_id !== (string) $tenant->getTenantKey()) {
    return false;
}
```

Without `deleteAny invitations` a user may only revoke an invitation they sent
themselves, and that comparison is not a direct one either:
`invitations.invited_by_user_id` holds the *central* user's primary key while
the acting `$user` is the tenant twin, so `InvitationPolicy::delete()` joins
the two through `global_id`. Acceptance is not a policy check at all — the
invitee has no tenant user yet, and identity matching happens inside
`AcceptInvitation`.

`tenant()` returns `mixed` to PHPStan, so the `instanceof` is load-bearing at
level 9 as well as at runtime. `getTenantKey()` returns `int|string` while the
column is a `varchar`, hence the cast.

Regression test:
`tests/Feature/Invitations/InvitationIssuingTest::test_an_admin_cannot_revoke_another_tenants_invitation`,
paired with the positive case so the check cannot be "fixed" by refusing
everything.

## The reading path needs the same filter, and had it

`Actions\Queries\GetPendingInvitationsForTenant` scopes with
`->where('tenant_id', $tenantId)`, and `GetTenantMembers` does the same. A
central-table query written from tenant context returns every tenant's rows by
default. Treat the missing `where` as the bug, not the present one as
belt-and-braces.

The scoping used to sit inline in `numerosis-pages::tenant.invitations`, which
went when the invitations screen folded into `/team` (2026-09-16). A rule
naming a view is worth re-checking after any screen move; the query action
outlives it.

## `route()` on a tenant route name throws in path mode

Verified 2026-09-04 by booting `Tests\Browser\PathModeTestCase` and calling it:

```
URI: {tenant}/team/invitations
THREW: Illuminate\Routing\Exceptions\UrlGenerationException
       Missing required parameter for [Route: team.invitations.store]
       [URI: {tenant}/team/invitations] [Missing parameter: tenant]
```

`Numerosis::routes()` prefixes the tenant group with
`{PathTenantResolver::$tenantParameterName}` in path mode, and **nothing in
this package or in `stancl/tenancy` registers a `URL::defaults(['tenant' => …])`**
(grepped both). So any `route('team.invitations.*')`, in a controller redirect,
a `#[RedirectToRoute]`, or a Blade form action, is a 500 in that mode.

Reach for `back()` in controllers and form requests, and `url()->current()` in
views, both of which are correct in all three modes. `resources/views/pages/tenant/⚡team.blade.php`
posts every form off `url()->current()` for this reason — the invite form to
`url()->current().'/invitations'`, the member forms to
`url()->current().'/members/'.$membership->id`.

Two things hide it. `tests/TestCase.php` calls
`URL::forceRootUrl('http://central.numerosistest.test')`, so in the default
(subdomain) mode a named-route redirect silently yields a *central* URL instead
of the tenant one, and a bare `assertRedirect()` passes anyway. Assert the full
expected URL, with `->from(...)` set, or the test proves nothing. The path-mode
throw is invisible to any suite that never boots that mode.

## Signed links outlive neither more nor less than the row

`invitations.show` is signed with `URL::temporarySignedRoute(..., $invitation->expires_at, ...)`,
so a stale link is refused by `ValidateSignature` with a 403 and never reaches
`ShowInvitationController`. The controller's own `isExpired()` check covers
only the cases where the two disagree: an `expires_at` shortened after the link
was minted, or clock skew between the queue worker that signed it and the web
node reading it. A test that expects the controller's message must sign with an
explicit future expiry, or it asserts against the signature middleware instead.

The accept route shares the show route's URI, and `ValidateSignature` ignores
the HTTP method, so one signature covers both. `resources/views/invitations/show.blade.php`
posts to `url()->full()` for that reason. Changing either URI breaks the accept
form silently.

## Policies resolve permissions against the model's guard, not the ambient one

`Policies\Concerns\ChecksContextPermissions` checks each permission against the
guard of the `User` it was handed, never `Auth::getDefaultDriver()`. The two
agree on every ordinary path and diverge exactly where it matters: a central
user evaluated inside tenant context would otherwise be checked against tenant
roles they could never hold, and spatie throws `PermissionDoesNotExist` for a
name that exists under the other guard — a 500, not a denial.

The trait also short-circuits `updateAny`/`deleteAny` ahead of the per-record
check, so an admin holding the blanket permission is never refused a single
record. Both behaviours are in the trait rather than in each policy on purpose;
a policy with real domain logic of its own should not build on it.

## `memberships` is the second central table bound on a tenant route

`team/members/{membership}` (PATCH and DELETE, added 2026-09-16) binds
`Models\Central\Membership`, and everything above about `tenant_invitations`
applies unchanged: no tenant scope on the binder, `deleteAny invitations`
seeded to every tenant's admin. `Policies\Tenancy\MembershipPolicy` compares
`tenant_id` to `tenant()->getTenantKey()` on both methods, and refuses `Owner`
rows outright — ownership moves only through its own transfer flow.

Two rules there are not permission checks and belong nowhere else: removing
the **last admin** is refused, and **self-removal is exempt** from that
refusal, because a member with no way out of a team is the worse failure.
Demotion of the last admin is refused in `ChangeMemberRole` instead, since the
policy never sees the target role.

`EnsureTenantMembership` (`tenancy.membership`) re-checks membership per
request, off `GetTenantsByGlobalId`'s cached list, which `ForgetUserTenants`
invalidates on every membership write. Without it a removed member's tenant
guard session keeps working until it expires: the guard stores a per-database
primary key and re-resolves nothing.

## Creating a tenant-side `User` attaches a membership by itself

`Models\Tenant\User` is `ResourceSyncing`, so writing one inside an
initialized tenancy syncs it back to central — creating the `CentralUser` *and*
attaching the pivot. A fixture that does both by hand dies on
`memberships.unique(tenant_id, global_user_id)`, and only after the first
request in the test, since before that tenancy is not initialized and the sync
does not run. `tenancy()->end()` between fixture steps is the fix;
`tests/Feature/Team/TeamMembersTest::member()` carries it.

## `tenant_ownership_nominations` is the third, and it is the tenant that is billed

`team/ownership` (POST) and `team/ownership/{nomination}` (DELETE), added
2026-09-16, bind `Models\Central\OwnershipNomination`.
`Policies\Tenancy\OwnershipNominationPolicy::delete()` makes the same
ambient-tenant comparison `MembershipPolicy` does, and
`MembershipPolicy::transferOwnership()` guards the nominating side.

Two facts about what ownership controls, both easy to get backwards:

**The Stripe customer is the tenant, not the owner.**
`Actions\Tenancy\LinkTenantSubscription` writes `tenants.stripe_id` and
re-points `subscriptions.subscribable` at the `Tenant`, so a transfer moves no
money and no customer. What it changes is who `Tenant::stripeEmail()` resolves
to, which `Actions\Billing\SyncTenantToStripe` re-sends. Do not write code
that moves a subscription between Stripe customers: `customer` is create-only
on a Stripe subscription and the API refuses it.

**`password.confirm.if-set` cannot go on a tenant route.** `RequirePassword`
redirects to `route('password.confirm')`, and in path identification mode the
tenant group is prefixed `{tenant}` with no `URL::defaults()` registered, so
the middleware throws `UrlGenerationException` instead of asking for a
password. `Http\Requests\Team\NominateOwnerRequest` validates
`current_password` against the tenant guard in the form instead, and skips the
rule when `getAuthPassword()` is blank, which is the OAuth case the middleware
exists for.

## Two invitation-row traps, both silent

**Re-inviting an address reuses its row**, because `tenant_invitations` is
`unique(tenant_id, email)`. `SendInvitation` clears `accepted_at` and
`accepted_by_user_id` through `forceFill()`, since both are deliberately absent
from the model's `#[Fillable]` — they are state, not input. An earlier
`updateOrCreate()` passed them among its values and they were dropped silently,
so re-inviting someone whose first invitation had been accepted minted a link
`AcceptInvitation` then refused with `InvitationAlreadyAccepted`.

**Acceptance is claimed with a conditional `UPDATE … WHERE accepted_at IS
NULL`,** inside `AcceptInvitation`'s transaction and before the membership is
attached, so two concurrent POSTs cannot both reach `AddTenantMember`: the
loser matches zero rows and throws `InvitationAlreadyAccepted`, rolling back.
Reading `isAccepted()` and stamping afterwards left a window where both passed
and the second attach died on `memberships.unique(tenant_id, global_user_id)`
with an uncaught `QueryException`.

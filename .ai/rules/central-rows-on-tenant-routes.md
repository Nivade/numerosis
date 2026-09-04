---
paths:
  - 'src/Policies/**'
  - 'src/Http/Controllers/**'
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

`pages::tenant.invitations` scopes with
`->where('tenant_id', tenant()->getKey())`. A central-table query written from
tenant context returns every tenant's rows by default. Treat the missing
`where` as the bug, not the present one as belt-and-braces.

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
views, both of which are correct in all three modes. `resources/views/pages/tenant/⚡invitations.blade.php`
posts its create form to `url()->current()` and its revoke form to
`url()->current().'/'.$invitation->getRouteKey()` for this reason.

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

# Team members: list, remove, change role

**Status: not executed. Written 2026-09-16.** Wave 1 of
`saas-readiness-roadmap.md`, and the largest single hole in the package.

## What is missing

`grep -rn Membership routes/ src/Http` returns nothing. The model exists
(`src/Models/Central/Membership.php`), the roles exist
(`src/Enums/Tenancy/MembershipRole.php` — Owner, Admin, Member, Viewer), the
observer exists and fires `MemberRemoved`
(`src/Observers/Tenancy/MembershipObserver.php:47`), and no route, controller
or component can reach any of it.

So a tenant can invite people (`/team/invitations`) and can never afterwards
see who is in the team, remove anybody, or change what they can do. The
`MemberRemoved` event has no producer.

## Shape

One tenant-side screen at `/team`, the natural parent of the invitations
screen that already exists. Members and pending invitations belong on the same
page — they are the same question ("who is in this team") in two states.

```
GET    /team                      team.index        members + pending invitations
PATCH  /team/members/{membership} team.members.update   change role
DELETE /team/members/{membership} team.members.destroy  remove member
```

The existing `team.invitations.*` routes keep their names and move under the
same screen.

## The trap this sits on

`Membership` is a central-connection model and these are tenant routes.
Route-model binding a `CentralConnection` model on a tenant route applies no
tenant scope whatsoever — the binder will happily resolve another tenant's
membership id. `MembershipPolicy` must compare `$membership->tenant_id`
against `tenant()->getTenantKey()` on every method, and the test suite must
prove a foreign id 404s rather than authorizing.

`InvitationPolicy` already does this (`src/Policies/Invitations/InvitationPolicy.php:18`)
and is the model to copy.

## Rules the policy encodes

| Action | Allowed |
|---|---|
| View the team | Any authenticated member |
| Remove a member | `invitations`-context permission holder, or an Admin; never the Owner |
| Change a role | Same, and the target role may not be `Owner` — `MembershipRole::Owner` is non-assignable and moves only through `tenant-ownership-transfer.md` |
| Remove yourself | Allowed for anyone who is not the Owner. This is the "leave team" path |
| Remove the last Admin | Refused. A tenant with an Owner and no Admin is recoverable; the refusal is about not stranding day-to-day administration |

## Phases

### 1. Policy and scope

`Policies/Tenancy/MembershipPolicy`, tenant-scoped, registered alongside the
existing nine. Tests first: a membership id from another tenant must not
resolve.

### 2. Read screen

`Livewire\Tenant\Team\Members` listing memberships with role, joined date and
inviter, plus the pending invitations already modelled. Flux table, the same
components the invitations screen uses.

### 3. Remove

`Actions\Tenancy\RemoveMember`, deleting the membership through the model so
`MembershipObserver` fires `MemberRemoved`. What happens to the tenant-side
`Tenant\User` row is deliberately untouched here — the observer's current
behaviour (membership deleted, tenant user left in place) is what
`gdpr-data-export.md` and `tenant-close-and-recovery.md` revisit. State it in
the UI: "removing revokes access; their records stay".

### 4. Change role

`Actions\Tenancy\ChangeMemberRole`, with the Owner guard and a
`MemberRoleChanged` event to match the existing event vocabulary.

### 5. Leave team

Self-removal from the same screen, with a confirmation naming the tenant.
Redirects to `/tenants/mine` on the central domain, since the user has just
lost access to the domain they were on.

## Tests

- Foreign-tenant membership id is not resolvable on any of the three routes.
- Owner cannot be removed or demoted through these routes.
- Removing the last Admin is refused; removing a non-last Admin is not.
- `MemberRemoved` fires exactly once per removal.
- Self-removal works for Admin, Member and Viewer, and redirects centrally.
- Viewer cannot reach the mutation routes.

## Risks

- **Session survival.** A removed member holding a live session keeps it until
  the guard next re-resolves. `EnsureSessionMatchesTenant` checks tenant match,
  not membership. Either the removal invalidates their sessions (needs
  `session-management.md`) or the tenant middleware gains a membership check.
  Recommend the middleware check now and the session invalidation later —
  a stale session outliving a removal is the one failure here that looks like
  a security bug.

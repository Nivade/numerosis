# Transfer tenant ownership

**Status: executed 2026-09-16, on `feat/tenant-ownership-transfer`.** Wave 1
of `saas-readiness-roadmap.md`. Depends on `team-members-management.md` for
the screen it lives on. See "What shipped" at the bottom for the two places
the plan was wrong about the code.

## Why

`src/Livewire/Settings/DeleteUserForm.php:40` refuses to delete an account
that owns a tenant. Nothing anywhere transfers ownership. So the owner of a
tenant can never leave, and if they do leave the company, the tenant is held
by an account nobody controls. Both halves are bad, and the second is the one
that generates support tickets.

`MembershipRole::Owner` is marked non-assignable
(`src/Enums/Tenancy/MembershipRole.php:15`), which is correct — ownership
should move through one action, not through the role dropdown.

## What ownership actually controls today

Worth establishing before moving it, because the answer decides what the
transfer has to touch:

| Concern | Bound to |
|---|---|
| Membership role | `memberships.role = owner` |
| Billing | The Stripe customer sits on the *central user* who checked out, not on the tenant. `subscribable_id` is the owner's primary key |
| Account deletion block | `DeleteUserForm`'s owner check |
| Provisioning | `AddTenantOwner` sets the first owner at provision time |

The billing row is the one that makes this more than a column update. A
transfer that moves the membership role and leaves the subscription on the
departing user has moved the title and left the bill behind.

## Decision: billing moves with ownership

The new owner becomes the billable party. Implemented as a Stripe customer
change on the subscription rather than a new subscription, so no proration
event fires and the period continues. If the new owner has no Stripe customer,
one is created.

The alternative — ownership and billing as separate roles, a "billing
contact" distinct from the owner — is a real product and a much bigger one.
Not now. Record it here so the next reader knows it was considered.

## Phases

### 1. `TransferTenantOwnership` action

Input: tenant, current owner, target membership. In one central transaction:

1. Assert the target is an existing, accepted member of the tenant.
2. Demote the current owner to Admin.
3. Promote the target to Owner.
4. Move the Stripe customer on the active subscription.
5. Fire `TenantOwnershipTransferred` carrying scalars only, with
   `ShouldDispatchAfterCommit`.

Idempotent on re-run: transferring to the existing owner is a no-op, not an
error.

### 2. Confirmation flow

Ownership transfer is irreversible from the old owner's side. Two-step:
current owner nominates, target accepts through a signed link with a 72-hour
life, mirroring the invitation pattern already in
`src/Notifications/Invitations/InvitationNotification.php`. A nomination
expires rather than sitting open.

Password confirmation on the nominating step — `RequirePasswordIfSet`
middleware exists for exactly this.

### 3. Screen

On `/team`, visible to the Owner only. Lists eligible members (accepted, not
the owner) and shows any pending nomination with a revoke button.

### 4. Unblock account deletion

`DeleteUserForm`'s owner check changes from "you cannot delete" to "you own N
tenants — transfer them or close them", linking both paths.
`tenant-close-and-recovery.md` supplies the second link.

### 5. Staff override

Support must be able to reassign ownership when the owner is unreachable.
Surfaced in the staff panel (`staff-admin-panel.md`), skipping the acceptance
step, always written to the activity log. This is the path that resolves
"founder left, nobody can pay the bill".

## Tests

- Transfer moves the role, demotes the old owner to Admin, and leaves exactly
  one Owner.
- Subscription's Stripe customer follows the new owner; the subscription id is
  unchanged and no proration invoice is raised.
- Nomination expires after 72 hours and cannot then be accepted.
- Non-owner cannot nominate. Owner cannot nominate a non-member or a pending
  invitee.
- After transfer, the old owner can delete their account.
- Staff override skips acceptance and writes an activity-log entry naming both
  parties.

## Risks

- **Stripe customer move mid-dunning.** A subscription in `past_due` moving
  customers may retry against a payment method the new owner does not have.
  Refuse transfer while the subscription is `past_due` or `unpaid` and say so.
- **`AddTenantOwner` assumes one owner forever.** Check it does not re-promote
  on a re-run of the provisioning chain against a transferred tenant.

## What shipped

All five phases, with two corrections to the plan's own premises.

**"Billing moves with ownership" was solving a problem that no longer exists.**
The table above says the Stripe customer sits on the central user who checked
out. It does not: `Actions\Tenancy\LinkTenantSubscription` writes
`tenants.stripe_id` and re-points `subscriptions.subscribable` at the
`Tenant`, so the customer *is* the tenant and the subscription never belonged
to the departing user. Nothing has to move between customers, which is
fortunate, because Stripe's API does not allow it — `customer` is create-only
on a subscription, so the plan's "Stripe customer change on the subscription"
was unimplementable as written. What ownership actually changes is who the
customer's name and email describe, and
`Actions\Billing\SyncTenantToStripe` already re-sends both; the transfer
dispatches it, gated on `numerosis.billing.sync.stripe_customer`.

**Password confirmation is a form field, not the middleware.**
`RequirePasswordIfSet` redirects to `route('password.confirm')`, and the
tenant group is prefixed `{tenant}` in path identification mode with no URL
default registered, so the middleware would throw `UrlGenerationException` on
the nominating POST. `NominateOwnerRequest` validates `current_password`
against the tenant guard instead, skipping the rule for an account with no
password, which is the case `RequirePasswordIfSet` exists for.

**Phase 5 is a console command, not a staff panel.** `staff-admin-panel.md`
has not been executed, so `tenancy:transfer-ownership {tenant} {email}`
carries the support path: it confirms, skips the nomination, deletes any open
one, and writes the activity-log entry naming both parties. Surfacing it in
the panel is that plan's job. The log entry carries the tenant id in
`properties` rather than through `performedOn()`, because
`activity_log.subject_id` is an integer column and a tenant key is a string.

**Phase 4 links one path, not two.** `tenant-close-and-recovery.md` has not
been executed either, so `DeleteUserForm` names the workspaces the account
still owns and points at the transfer, with no closing link to offer yet.

New: `tenant_ownership_nominations` (one open row per tenant, `unique(tenant_id)`,
72-hour expiry, ULID re-minted on re-nomination so a superseded link dies),
`Models\Central\OwnershipNomination`, four actions
(`AssertOwnershipTransferable`, `NominateTenantOwner`,
`AcceptOwnershipNomination`, `TransferTenantOwnership`),
`Events\Tenancy\TenantOwnershipTransferred`,
`OwnershipNominationNotification`, `OwnershipNominationPolicy`,
`MembershipPolicy::transferOwnership()`, the signed central
`/ownership-transfers/{nomination}` pair, `POST`/`DELETE team/ownership` on the
tenant domain, and the Ownership card on `/team`. Twelve tests in
`tests/Feature/Team/OwnershipTransferTest.php`. Suite 846 passed / 6 skipped,
`composer analyse` clean.

`AddTenantOwner` needed no change: it is `if (! $user->tenants()->where(...)->exists())`,
so a re-run against a transferred tenant sees the existing membership and
attaches nothing.

# Transfer tenant ownership

**Status: not executed. Written 2026-09-16.** Wave 1 of
`saas-readiness-roadmap.md`. Depends on `team-members-management.md` for the
screen it lives on.

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

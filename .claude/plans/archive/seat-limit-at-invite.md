# Seat limit is not enforced at invite or accept

**Status: executed 2026-09-16, written the same day.** Wave 1 of
`saas-readiness-roadmap.md`. This is a defect, not a feature — a tenant on a
capped plan can exceed its seat count and never be charged for it.

## The defect

`SeatLimitPlanPolicy` reads `options.max_users` from plan metadata
(`src/Services/Billing/SeatLimitPlanPolicy.php:28`) and its own docblock says
it runs "when starting a checkout and when swapping plans". Those are the only
two call sites. Nothing on the membership path consults it:

- `src/Actions/Invitations/SendInvitation.php` validates email, role and
  expiry. No seat check.
- `src/Actions/Invitations/AcceptInvitation.php` claims the row and creates
  the membership. No seat check.

So a plan sold as five seats admits an unbounded number of members, as long as
they arrive by invitation rather than by a plan swap.

## Where the check belongs

**Accept, primarily.** Invites are rows with a 7-day life
(`src/Actions/Invitations/SendInvitation.php`), so a tenant can issue ten
invitations under a ten-seat plan, downgrade to three seats, and have seven
people accept into a plan that does not hold them. Counting at accept time is
the only count that cannot be outrun.

**Invite, secondarily, as UX.** Refusing at accept leaves the invitee facing
an error for something the inviter did. Check at send too, and let the
accept-time check be the authority.

Seats are counted as **memberships plus unexpired unaccepted invitations**.
Counting memberships alone lets a tenant at the cap issue invitations that can
never be honoured.

## Phases

### 1. A seat counter with one definition

`Services\Billing\SeatCounter`, reading the central connection:

```
memberships where tenant_id = ?
+ tenant_invitations where tenant_id = ? and accepted_at is null and expires_at > now()
```

One method, `usedSeats(Tenant $tenant): int`. When
`runtime-entitlements.md` lands, this moves behind the shared counter service
and this class becomes a reader.

### 2. `PlanPolicy` gains a seat question

`Contracts\Billing\PlanPolicy` is the existing seam. Add a method that answers
"does this tenant have room for one more seat", implemented in
`SeatLimitPlanPolicy` against `SeatCounter` and the tenant's current plan.
A plan with no `options.max_users` is uncapped, matching today's behaviour.

### 3. Enforce at accept

`AcceptInvitation` consults the policy before creating the membership and
throws a domain exception implementing `ShowsMessageToUser` when full. The
invitation row is left intact — the tenant can upgrade and the invitee can
retry within the 7 days.

### 4. Enforce at send

`SendInvitation` does the same check and surfaces it on the invitation form.
The resend path (which upserts an existing row) does not consume a new seat,
because the row it reuses is already counted.

### 5. Surface the number

The invitations screen shows "4 of 5 seats used". Without it the refusal
arrives with no warning.

## Tests

- Accept is refused at the cap, with the row still claimable after an upgrade.
- Ten invitations issued under a ten-seat plan, plan swapped to three, accepts
  stop at three. This is the case the current code gets wrong.
- Resending an existing invitation does not consume an extra seat.
- A plan with no `options.max_users` admits members without limit.
- Seat count includes unaccepted, unexpired invitations and excludes expired
  ones.

## What shipped, where it differs

The counter is `Actions\Queries\GetTenantSeatUsage` returning a
`Data\Billing\SeatUsage`, not `Services\Billing\SeatCounter` — `ArchTest`
requires everything in `Services/` to implement one of our own contracts.

The two checks count differently, which phase 1 did not anticipate. An invite
is judged against memberships plus pending invitations; an accept against
memberships alone. Counting pending invitations at accept time would have made
the "ten invitations, downgrade to three" case admit nobody at all, since nine
still-pending invitations already exceed the new cap. `PlanPolicy` therefore
gained two methods, `hasSeatForNewInvitation()` and `hasSeatForNewMember()`.

## Risks

- **Existing over-capacity tenants.** None exist (no installs), but the
  counter must not retroactively lock out members already in a tenant. The
  check gates *adding*, never *being*.
- **Downgrade path.** Swapping to a smaller plan while over the new cap is
  allowed by `SwapSubscriptionPlan` today only if the policy permits it.
  Whether a downgrade below current headcount is refused or merely blocks
  further joins is a decision for `runtime-entitlements.md`; this plan does
  not change swap behaviour.

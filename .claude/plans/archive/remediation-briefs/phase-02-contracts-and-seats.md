# Phase 2 — Contracts and the seat counter

**Status: not executed.** Anchors verified against `e411bed`. Read
`README.md` in this directory first. Closes S34, P3, S8, S9, S14.

Rules to read before editing: `.ai/rules/architecture-conventions.md`,
`.ai/rules/enums.md`, `.ai/rules/billing-checkout.md`.

## 2.1 — Move the capability constants onto the contract (S34)

`src/Services/Billing/SeatLimitPlanPolicy.php:59` and `:70` read
`PlanEntitlements::SEATS` while the class type-hints
`Contracts\Billing\Entitlements`. A host pointing
`numerosis.billing.implementations[Entitlements]` at its own class still
inherits the concrete one.

- `SEATS` is declared at `src/Services/Billing/PlanEntitlements.php:32`.
- `src/Contracts/Billing/Entitlements.php` declares no constants today.

Move `SEATS` and every sibling capability constant onto the `Entitlements`
interface. Leave `PlanEntitlements::SEATS` resolving to the interface constant
rather than deleting the name outright, so the two internal readers at
`PlanEntitlements.php:81` and `:240` keep working.

**Keep them `string`.** Do not convert to an enum. `.ai/rules/enums.md` records
that a core enum closes a host's string-extension seam, and a capability is
exactly such a seam.

## 2.2 — One definition of the seat limit (P3, S8)

`src/Actions/Queries/GetTenantSeatUsage.php:43` re-derives the cap itself:

```php
$subscription?->paymentPlan?->metadata()['options']['max_users']
```

while `SeatLimitPlanPolicy::hasSeatForNewMember()` (`:64`) has already asked
`Entitlements::limit()` for it. `limitFor()` (`:40`) already resolves the
subscription through `GetActiveSubscription` (`:42`); only the metadata read is
duplicated.

Delete the re-derivation. `GetTenantSeatUsage` counts members and asks
`Entitlements` for the cap.

**The trap that reverted this once.** `PlanEntitlements` memoizes per tenant for
the whole request (`$resolved`, `$meters`, `$buckets` at `:143`, `:162`, `:174`).
`tests/Feature/Invitations/SeatLimitTest.php:70`
(`test_a_downgrade_stops_the_accepts_of_invitations_issued_under_the_larger_plan`)
writes a plan change and then requires an accept **in the same request** to see
it. Routing the seat count through a memoized `Entitlements` makes that test
read the stale cap.

**Decision, already made:** give the memo an explicit invalidation hook
(`PlanEntitlements::forget(Subscribable $for)` or equivalent) and call it where
the plan changes. Do not weaken the test, and do not leave the second
derivation in place.

The invite-versus-accept difference in *what is counted* is deliberate and
documented. It stays.

## 2.3 — `PlanPolicy`'s two jobs (S9)

`src/Contracts/Billing/PlanPolicy.php` declares four methods: `assertEligible`
(`:11`), `canSwap` (`:13`), `hasSeatForNewInvitation` (`:20`),
`hasSeatForNewMember` (`:22`).

**Decision, already made: split.** Once 2.2 lands the two seat methods are thin
reads over `Entitlements`, and a one-line docblock cannot carry why eligibility
and seat availability are the same question — because they are not. Extract
`Contracts\Billing\SeatPolicy` with the two `hasSeatFor*` methods. Keep
`SeatLimitPlanPolicy` implementing both interfaces so no binding moves; register
the new contract beside the existing one.

Mind `.ai/rules/architecture-conventions.md`: `Contracts/Billing/*` and
`Services/Billing/*` are both flat and mirror each other. Do not add a
subfolder.

## 2.4 — Model resolution (S14)

Bare `::query()` calls that should go through `Numerosis::model()`:

| File:line | Model |
|---|---|
| `src/Actions/Queries/FindMembershipForUser.php:23` | Membership |
| `src/Actions/Tenancy/AcceptOwnershipNomination.php:48` | Membership |
| `src/Actions/Tenancy/TransferTenantOwnership.php:35` | Membership |
| `src/Http/Requests/Team/NominateOwnerRequest.php:45` | Membership |
| `src/Console/Commands/EndStaleImpersonations.php:28` | ImpersonationSession |
| `src/Actions/Queries/GetCurrentImpersonation.php:39` | ImpersonationSession |
| `src/Actions/Admin/RedeemImpersonation.php:32` | ImpersonationSession |

`Membership` **is** already in the `numerosis.models` map
(`config/numerosis.php:555`). `ImpersonationSession` is **not** — add it, in the
same commit, and route all seven call sites through `Numerosis::model()`.

## Tests

- Extend `tests/Feature/Billing/EntitlementSeamsTest.php`: a host binding its own
  `Entitlements` implementation is what `SeatLimitPlanPolicy` reads, with no
  reference to `PlanEntitlements` left.
- `tests/Feature/Invitations/SeatLimitTest.php` must stay green **unmodified**.
  If it goes red, 2.2 is wrong, not the test.
- Add: a tenant whose plan changes mid-request sees the new cap (the
  invalidation hook 2.2 adds).
- Add: swapping the `numerosis.models` entry for `ImpersonationSession` is what
  the seven call sites resolve.

Prove each: write it, revert the fix, watch it fail, restore.

## Commit

```
refactor(billing): one seat limit, read through the contract

The seat cap had two definitions. SeatLimitPlanPolicy asked Entitlements
for it; GetTenantSeatUsage walked to the plan metadata itself through
three optionals and answered differently when they disagreed. The action
counts members now and asks Entitlements for the cap.

PlanEntitlements memoizes per request, and a downgrade has to be visible
to an accept later in the same one, so the memo gained an explicit
invalidation rather than the seat count keeping its own read.

The capability constants moved onto the Entitlements interface: reading
PlanEntitlements::SEATS through a contract hint handed a host that swapped
the implementation the concrete class anyway. Seat availability split out
as SeatPolicy -- plan eligibility and seat counting are two questions.

ImpersonationSession joined the models map, and the seven bare ::query()
call sites for it and Membership now resolve through Numerosis::model().

Closes S34, P3, S8, S9 and S14.
```

# Phase 5 — Notifications

**Status: not executed.** Anchors verified against `e411bed`. Read
`README.md` in this directory first.

**This phase is almost entirely already done.** Verify the four closures below,
then make the one change in 5.4. If a closure does not hold, stop and report —
do not re-implement it from the plan's prose.

Rules to read: `.ai/rules/events-listeners-observers.md`.

## Verify, do not rebuild

| Plan item | State at `e411bed` | Evidence |
|---|---|---|
| 5.1 / P16 — three notifications hard-returning `['mail']` | **Closed** | All three use `Concerns\Notifications\RespectsPreferences`, whose `via()` (`:24`) resolves `NotificationChannels`. `InvitationNotification.php:20`, `PersonalDataExportReady.php:19`, `ProvisioningFailed.php:18` |
| 5.2 / P17 — toggles with no sender | **Closed** | `Enums\Notifications\NotificationType` has 8 cases and every one has a sender. `MemberJoined` and `SecurityAlert` were deleted rather than wired |
| 5.3 / P23 — the digest | **Closed** | No `digest` column in any migration, no `NotificationType::digestible()`. D2 settled as the drop |
| S42 — stray constructor | **Closed** | `OwnershipNominationNotification::__construct()` is at `:16`, top of the class |

**The operator notification, decided.** The plan's default was to leave
`ProvisioningFailed` mail-only with a one-line reason; the branch routed it
through preferences with the two member-facing ones. **Leave it as the branch
has it.** An operator who has turned off a channel has turned it off. Record the
divergence from the plan's default in phase 12's "What shipped" pass, not here.

## 5.4 — The duplicated unsubscribe block (S40)

The "Stop these emails: {$unsubscribe}" ternary is verbatim in two places:

- `src/Notifications/Billing/PaymentConfirmed.php:46-48` (inside `toMail()`, `:29`)
- `src/Notifications/Tenancy/TenantRestored.php:27-29` (inside `toMail()`, `:17`)

Both classes already extend `src/Notifications/Tenancy/TenantNotification.php`
(`:19`), which already holds `unsubscribeUrl()`. Move the ternary onto
`TenantNotification` as one method returning the line, or nothing when there is
no URL, and call it from both.

Check the other `TenantNotification` subclasses — `PaymentFailed`,
`TenantSuspended`, `OwnershipNominationNotification` — for a third copy before
you finish.

## Tests

`tests/Feature/Notifications/NotificationPreferencesTest.php` (9 tests) and
`NotificationCenterTest.php` (5 tests) already cover the closed items and must
stay green **unmodified**.

For 5.4: the rendered mail for both notifications still carries the unsubscribe
line, and still omits it when there is no URL. If an existing test already
asserts that text, extending it is enough.

5.4 changes no behaviour, so no test expectation may move.

## Commit

```
refactor(notifications): one unsubscribe line, not two

PaymentConfirmed and TenantRestored each carried the same unsubscribe
ternary, in classes that already share TenantNotification and its
unsubscribeUrl(). It lives on the base class now.

The rest of phase 5 was already closed: all three mail-only notifications
route through preferences, the two toggles with no sender were deleted
with their enum cases, and the digest column went with digestible().

Closes S40's second half. P16, P17, P23 and S42 verified closed.
```

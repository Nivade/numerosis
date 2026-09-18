# In-app notifications and per-user preferences

**Status: executed 2026-09-17 on `feat/notification-center`, except the digest.**
Wave 5 of `saas-readiness-roadmap.md`. Phases 1–5 shipped and phase 7 turned out
to already exist; phase 6, the digest, is deliberately not built — see "What
shipped".

## The gap

The central `notifications` table has been migrated since 2026-08-11
(`database/migrations/central/2026_08_11_180000_create_notifications_table.php:19`)
and nothing writes to it. Every notification the package sends goes to mail
and only mail: payment confirmed, payment failed, tenant suspended, invitation
sent.

So there is no in-app notification anywhere, no history a user can scroll, and
no way for anyone to say "stop mailing me about this". The last one matters
more than it sounds: an unsubscribe path is a legal requirement in several
jurisdictions for anything that is not strictly transactional, and it is the
first thing a user looks for when a feature starts mailing them daily.

## Scope

1. Database channel added alongside mail on every existing notification.
2. A notification centre in the UI: unread count, list, mark read, mark all.
3. Per-user, per-notification-type channel preferences.
4. A digest option for anything high-volume.
5. An operator channel, which `provisioning-observability.md` consumes.

## Preferences model

A matrix of notification type against channel, per user, with a package
default per type and an explicit override per user.

| Type | Mail default | In-app default | User may disable mail |
|---|---|---|---|
| Payment failed | on | on | **no** — transactional and consequential |
| Payment confirmed | on | on | yes |
| Tenant suspended | on | on | no |
| Invitation received | on | on | yes |
| Member joined | off | on | yes |
| Webhook endpoint disabled | on | on | yes |
| Security: new device, 2FA changed, password changed | on | on | **no** |

The "may disable" column is the design's spine. A user who has turned off all
mail must still receive the mail that tells them their card failed or their
password changed. Encode it as a property of the notification class, not as a
rule in the preferences screen, or the next notification added will quietly
become optional.

## Phases

### 1. Preference storage and resolution

`notification_preferences` on the central connection, keyed by user and type.
A resolver that takes a notification and a notifiable and returns the channel
list, consulted from each notification's `via()`. Defaults live on the
notification class; the table holds overrides only.

### 2. Database channel everywhere

Add `database` to `via()` across the existing notifications, each with a
rendered title, body and action URL — a notification centre showing raw
payload keys is not a feature.

### 3. Notification centre

`Livewire\Notifications\Center`: bell with unread count in the layout, panel
with recent items, mark-read on open, full history screen, pagination.

Central and tenant both. A user inside a tenant should see tenant-relevant
notifications; the central list is theirs across tenants. Two lists, one
component, scoped explicitly — the central-model-on-a-tenant-route trap
applies here as everywhere.

### 4. Preferences screen

Under `/settings`, the matrix with the non-disableable rows shown and locked,
with a sentence saying why rather than a greyed checkbox with no explanation.

### 5. Unsubscribe links

A signed link in every disableable mail that turns that type off in one click,
no login. Signed, single-purpose, scoped to one type — a one-click link that
disables everything is a griefing vector.

### 6. Digest

For types that can burst — member joined, webhook failures — an hourly or
daily digest option. One scheduled job, batching per user.

### 7. Operator channel

`numerosis.notifications.operator`, a route for platform-level alerts that
belong to nobody's user account. Mail or Slack webhook, off unless configured.

## Tests

- `via()` honours preferences, and a type marked non-disableable keeps mail
  even when the user has disabled everything.
- Database notifications render with title, body and action URL, not raw
  payload.
- Unread count is per user and does not leak across tenants.
- Unsubscribe link disables exactly one type, is signed, and works while
  logged out.
- Digest batches multiple events into one mail and sends nothing when there is
  nothing to report.
- Operator channel sends nothing when unconfigured, rather than throwing —
  the failure mode that would otherwise break provisioning on a fresh host.
- Preferences survive a user being removed from one tenant and remaining in
  another.

## Risks

- **Notifications table growth.** Every user, every event, forever. Prune on a
  schedule alongside the other prune commands, with the window configurable.
- **The tenant-versus-central split.** A notification about a tenant, sent to
  a user who belongs to three tenants, has to be scoped or it appears in all
  three contexts. Decide the scope per notification type at the point it is
  added, and test it, because the wrong answer here shows a user something
  about a tenant they are reading from another tenant's screen.

## What shipped

Phases 1, 2, 3, 4 and 5. Phase 7's operator channel already existed
(`Contracts\Notifications\OperatorRecipient`, `MailsConfiguredOperator`,
`numerosis.notifications.operator`), including the "sends nothing when
unconfigured rather than throwing" behaviour the test list asked for.

- **`NotificationType` carries the defaults and the `mayDisableMail()` rule**,
  exactly where the plan said to put it. `SaveNotificationPreference` writes mail
  as on for a locked type whatever it was asked for, so neither the screen nor
  the unsubscribe link can route around it, and both directions are tested.
- **`notification_preferences` is keyed by `global_id`**, not by a user id or a
  membership: the preference is the person's and survives leaving a workspace.
  Rows are overrides only; a missing row is the default, never "off".
- **The bell is one component on both sides of tenancy**, scoped explicitly: a
  notification carrying a `tenant_id` shows only inside that workspace, and one
  carrying none shows everywhere. Its counts are read in `render()` rather than
  memoized — a cached computed property keeps showing the badge the click was
  meant to clear.
- **`numerosis:prune-notifications` deletes read notifications only**, pinned to
  the central connection, because the command may run while tenancy has swapped
  the default one.

**The digest (phase 6) is not built, and its half-schema is gone.** Only one
type was marked `digestible()` (`MemberJoined`), which is off by mail by
default, so the burst the digest exists to absorb cannot currently happen.
Shipping the seam without a reader is the half-schema the roadmap forbids, so
D2 of the readiness remediation settled it as the drop: `NotificationType::digestible()`
and the `notification_preferences.digest` column were both deleted 2026-09-17.
Phase 6 rebuilds them alongside the batching job when there is something to
batch. Phase 6 is what keeps this plan live.

Three further changes from that remediation:

- **Every notification routes through preferences** (P16). `InvitationNotification`
  and `PersonalDataExportReady` hard-returned `['mail']` where phase 2 asked for
  the database channel on every existing notification. So did `ProvisioningFailed`,
  which is operator-facing; it routes through preferences too, which diverges
  from the remediation plan's stated default of leaving the operator one
  mail-only.
- **The toggles with no sender are gone** (P17). The preferences screen rendered
  a switch for every `NotificationType` case, and `MemberJoined` and
  `SecurityAlert` had no sending class at all. Both enum cases were deleted with
  the vacuous `mailByDefault()`/`databaseByDefault()` entries behind them. A
  toggle wired to nothing is the defect either way; if the roadmap wants those
  two notifications, the cases come back with senders in the same commit.
- **The centre and the preferences screen are behind feature classes** (P21,
  `44b767c`): `NotificationCenterFeature` and `NotificationPreferencesFeature`,
  both on by default.

Two harness traps found here and recorded in `.ai/rules/testing.md`: reaching for
`DatabaseNotification::query()` in a test writes on the default connection while
the notifiable's relation reads the central one, and `$this->artisan()` returns a
`PendingCommand` that executes on destruct — after every assertion below it.

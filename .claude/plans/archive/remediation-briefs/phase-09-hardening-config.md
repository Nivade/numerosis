# Phase 9 — Hardening configuration

**Status: not executed.** Anchors verified against `e411bed`. Read
`README.md` in this directory first. Closes P13, P14, S28, S29, S30.

Rules to read: `.ai/rules/optional-dependencies.md`,
`.ai/rules/host-integration-quickstart.md`, `.ai/rules/auth-guards.md`.

## 9.1 — Drop the password minimum (P13)

**D4 came back "drop to Laravel's default". The plan's own text assumes the
opposite — ignore the plan here and follow this brief.**

`src/Boot/HostConfig.php:467-474` — `passwordDefaults()` sets
`Password::min(8)->uncompromised()` when
`numerosis.auth.check_compromised_passwords` is true, and does nothing
otherwise.

`security-hardening.md` asked only for `uncompromised()`. Drop `min(8)`. Length
is the host's policy, and Laravel's own default is what a host expects when the
package says nothing.

Check `tests/Feature/Auth/CompromisedPasswordTest.php` (3 tests) and
`CredentialUpdateRulesTest` — anything asserting an 8-character minimum as
*package* policy moves with this. Anything asserting Laravel's own default stays.

## 9.2 — Remove the Telescope exclusion (P14)

`config/numerosis.php:402` — `security.headers.except` ships
`['stripe/*', 'billing/webhook', 'telescope/*']` for a package this repo does
not depend on, and whose absence `.ai/rules/optional-dependencies.md` governs.

Remove `telescope/*` from that array. A host running Telescope adds its own.

**Noticed while verifying, and in scope for the same reason:**
`src/Boot/MiddlewareRegistrar.php:147` — `csrfExceptions()` ships the same
`telescope/*`. The plan names only the headers list. Remove it there too and say
so in the commit; leaving one of the two is worse than either state.

## 9.3 — Stop re-concatenating the config prefix (S28)

`src/Http/Middleware/SecurityHeaders.php` re-concatenates
`'numerosis.security.headers.'` at eight reads: `:28` (twice — `enabled` and the
`isExcepted` call), `:33`, `:61`, `:71`, `:80`, `:96`, `:108`. One private
accessor taking the suffix.

The disk fallback
`Config::string('numerosis.privacy.disk', Config::string('numerosis.tenancy.backup.disk', 'local'))`
is verbatim in **two** files, not the three the plan claims:

- `src/Jobs/Concerns/WritesDataExportOutcome.php:19`
- `src/Services/Auth/PersonalDataExporter.php:41`

One shared resolver. `WritesDataExportOutcome` already exists as the shared
concern for the export path; putting it there is the smaller change, but the
exporter is not a job — if that forces an awkward dependency, a small resolver
of its own is fine. Do not leave two copies.

## 9.4 — Read the two-factor route name from config (S29)

Hardcoded `route('settings.two-factor')`:

- `src/Http/Middleware/EnsureStaffTwoFactor.php:35`
- `src/Http/Middleware/EnsureTwoFactorEnrolled.php:45`

`config/numerosis.php:259-267` holds the `routes.names` block, and it has **no**
entry for the two-factor screen. Add one, with the current string as its
default, and read it in both middleware.

This matters because the route is gated behind Fortify's own feature check: when
that feature is off, the name does not resolve and the literal throws.

## 9.5 — The unused hooks (S30)

`src/Http/Middleware/SecurityHeaders.php` exposes five `protected` methods with
no subclass anywhere in the repo: `simpleHeaders()` (`:48`),
`applyContentSecurityPolicy()` (`:59`), `policy()` (`:78`), `isExcepted()`
(`:105`), `isHtml()` (`:115`).

**Decision, already made: make them private.** Speculative generality — there is
no subclass, no documented extension point, and a host that wants different
headers replaces the middleware rather than subclassing it. Do not add a
`docs/extending.md` section for an extension point nobody asked for.

## Tests

- `tests/Feature/Http/Middleware/SecurityHeadersTest.php` (7 tests) must stay
  green through 9.2, 9.3 and 9.5 — those change no behaviour beyond the
  Telescope path no longer being excepted. If a test asserts `telescope/*` is
  excepted, it moves with 9.2.
- 9.1: a password shorter than 8 is accepted unless the host set its own rule;
  `uncompromised()` still applies.
- 9.4: with the Fortify two-factor feature off, neither middleware throws when it
  needs the redirect target.

## Commit

```
fix(security): length is the host's policy, telescope is not our path

HostConfig forced Password::min(8) on every host. The hardening plan asked
only for uncompromised(); a minimum nobody specified is the package
deciding something it does not own. Dropped, and uncompromised() stays.

telescope/* shipped in both the security-header exclusions and the CSRF
exceptions, for a package this repo does not depend on. A host running
Telescope adds its own paths.

Two middleware hardcoded route('settings.two-factor') while
numerosis.routes.names exists so that feature-gated names are not
literals, and that one is gated behind Fortify's own check -- so the
literal throws exactly when the feature is off. It reads from config now.

SecurityHeaders re-concatenated its config prefix eight times and exposed
five protected hooks with no subclass in the tree; the prefix has one
accessor and the hooks are private. The privacy-disk fallback had two
verbatim copies and now has one.

Closes P13, P14, S28, S29 and S30. D4 settled as the drop.
```

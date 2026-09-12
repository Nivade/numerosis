---
paths: ['src/Enums/**']
---

# Enums

Three facts the enum-vocabulary-sweep (`.claude/plans/archive/enum-vocabulary-sweep.md`) had to establish before touching any of the fourteen enums it added or converted.

- **`subscriptions.stripe_status` still has no Eloquent cast.** Cashier's
  `Subscription::incomplete()`/`pastDue()` compare it with `===` against a
  plain string (`vendor/laravel/cashier/src/Subscription.php`). A cast makes
  both permanently `false`, with nothing red — `SettledSubscriptionTest`
  covers the regression. `SubscriptionStatus::tryFrom($this->stripe_status)`
  reads through an accessor (`Subscription::status()`) instead; the column
  stays a string in migrations, factories and raw `assertDatabaseHas` calls.

- **`Nvade\NumerosisUi\Enums\Severity` lives in `packages/ui`, not core.**
  `tests/Feature/PackageBoundariesTest.php` forbids anything under
  `packages/ui/{src,resources}` from naming a `Nvade\Numerosis` (non-Ui)
  symbol, so a Blade file in that package cannot `@use` core's `FlashKey`
  enum even though both back the same session convention. The two meet on a
  literal string only: `FlashKey::Status->value === 'status'`, which
  `packages/ui`'s Blade files hardcode as `'status'` rather than importing
  the enum that names it.

- **A core enum does not close a host extension seam that used to take a
  bare string.** `PermissionContext`, `PermissionAction` and `WizardStep`
  each cover core's own values, but the seam next to them
  (`Permission::additionalActions()`, `PermissionPolicy::permissionContext()`,
  the `numerosis.tenancy.registration.steps` config array) still accepts or
  returns a plain string/class-string, because a host may register a context,
  action or step core does not ship a case for. Converting the *seam's*
  return type to the enum, instead of just what core feeds into it, would
  make that impossible. See `docs/extending.md` for the host-facing side of
  this contract.

- **A Tailwind class string built by string interpolation never reaches
  generated CSS.** `Nvade\NumerosisUi\Enums\Severity` and
  `Nvade\Numerosis\Enums\Billing\CardBrand` both spell every class string out
  literally inside a `match()`, never as `"bg-{$prefix}-bg"` — Tailwind v4's
  `@source` scanner extracts complete class candidates from raw file text, and
  cannot evaluate PHP concatenation. `resources/theme-src/app.css` and
  `resources/css/app.css` both carry an explicit `@source` line naming each
  of these two files directly, since neither is a `.blade.php`/`.js` file the
  default `@source` globs already cover.

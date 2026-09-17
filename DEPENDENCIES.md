# Dependencies, per package

> **Rewritten 2026-09-03** for the two-package shape left by
> `.claude/plans/archive/humming-nibbling-flame.md`. The previous version described
> six units (`nvade/numerosis-{filament,auth-ui,onboarding,account}` plus
> core and `-ui`) and `internachi/modular`; all four satellites and the
> module system are gone. History worth keeping from that version is
> preserved at the bottom.

Two units, one repo (`packages/ui`), published as read-only splits. What a
*host* gets by installing each is in `docs/host-requirements.md` §0; this file
is about what each unit itself depends on, and why.

Three verdicts, unchanged in meaning:

- **require** — the package cannot function without it. No guard, install is
  always run.
- **suggest** — feature-gated, and the entry is a *promise that the package
  degrades cleanly without it*. That promise needs a real runtime guard:
  `class_exists()` / `interface_exists()` / `trait_exists()` before touching a
  class, `app()->bound()` before touching a binding.
- **require-dev** — the package's own suite exercises it, but a consumer need
  not. Every `suggest` that this repo's tests cover also appears here.

---

## `nvade/numerosis` (core)

Tenancy, Fortify-backed auth, billing, provisioning, the onboarding wizard —
what used to be `-auth-ui`, `-onboarding` and `-account` folded in here in
Phase 3 — every migration and seeder.

### require

| Package | Why it can't be optional |
|---|---|
| `illuminate/*` (15 components) + `laravel/framework` | Named per-component rather than as the meta-package, but `laravel/framework` is required too — Testbench, the console commands and the scheduler reach past the component list. Every class in `src/` depends on some `illuminate/*` symbol. |
| `stancl/tenancy` `^3.10` | The tenancy system itself: `TenancyServiceProvider`, every bootstrapper, the `tenant`/`central` connection split. **v3 only, deliberately** — the dual-version layer was built, measured and deleted (`.ai/rules/stancl-tenancy-v4.md`). |
| `laravel/cashier` | `BillingServiceProvider` binds Cashier's customer/subscription models directly; `Billable`, checkout and the webhook controller all assume its classes. |
| `laravel/fortify` | Auth itself: route registration (`Fortify::ignoreRoutes()` then `NumerosisServiceProvider::registerFortify()` reloads its route file per central domain and inside the tenant group), session handling, password hashing. Core's own actions are bound against Fortify's contracts, not a replacement for them. |
| `laravel/sanctum` | The public API's tokens. First-party, carries no tenancy opinions, and its `personal_access_tokens` table lives in **`database/migrations/tenant/`**: a token authorizes one person inside one workspace, so a central table would be a token list shared across every tenant. Added 2026-09-17 with the read API. |
| `laravel/socialite` | The OAuth surface, folded in from `-auth-ui` in Phase 3. `SocialLoginFeature` gates the *routes*, not this dependency — the package is always present. |
| `spatie/laravel-permission` | `AuthGuardBootstrapper`, every policy, `guardName()` on both user models. |
| `spatie/laravel-data` | `TenantProvisionData`, `SubscriptionData` and the rest of `Data\*` are typed on it; the provisioning and checkout pipelines pass these objects between actions, and Fortify's `array $input` actions convert to a Data object on entry (`docs/extending.md`). |
| `spatie/laravel-livewire-wizard` | The registration wizard's step classes `extends StepComponent`/`WizardComponent` — an `extends` clause is not guardable, so this cannot be `suggest`. Folded in from `-onboarding` in Phase 3. |
| `lorisleiva/laravel-actions` | Every `Actions\*` class is an `AsAction`. The provisioning chain's calling conventions are built on it (`.ai/rules/tenant-provisioning.md`'s `JobPipeline`-vs-`AsAction` bullet). |
| `livewire/livewire` | Every interactive surface core still owns — checkout, invitations, settings — is a Livewire component. |
| `spatie/laravel-package-tools` | `NumerosisServiceProvider extends PackageServiceProvider`. |
| `nvade/numerosis-ui` | Core's own views render `<x-numerosis::ui.*>`. A Blade tag for an unregistered component renders as **literal text** and passes tests — that is not clean degradation, so it cannot be `suggest`. Same reason `livewire/flux` was never optional; the requirement moved into `-ui` with the views. |
| `spatie/laravel-one-time-passwords` | Moved `suggest` → `require` 2026-09-05: `Nvade\Numerosis\Models\User` `use HasOneTimePasswords` directly, no compat shim. `OneTimePasswordFeature::available()` is now a plain `Features::enabled()` check — the package is always present, only the feature toggle varies. |
| `spatie/laravel-activitylog` | Moved `suggest` → `require` 2026-09-05: `Tenant\User` `use LogsActivity` directly, no compat shim. |

### suggest

| Package | Guard | Absence costs |
|---|---|---|
| `ryangjchandler/laravel-cloudflare-turnstile` | `TurnstileFeature::isEnabled()` — `class_exists(TurnstileRule::class)` | `<x-numerosis::turnstile-field />` renders nothing, `rules()` returns `[]`. |
| `sentry/sentry-laravel` | `app()->bound('sentry')` in `TagsSentryScopeWithTenant` | No tenant tag on job-failure reports. |
| `laravel/telescope` | `class_exists()` on the scheduled `telescope:prune` entry | No prune schedule; `telescope/*` stays CSRF-exempt harmlessly. |
| `socialiteproviders/discord` / `socialiteproviders/zoho` | `Enums\Auth\SocialProvider::configured()` — gated by `numerosis.social.providers` | Those two OAuth drivers unavailable; Socialite's own built-in providers are unaffected. |

`torann/geoip` was dropped outright in Phase 6, not moved to `suggest`: the
bound `Contracts\Billing\CheckoutRegionResolver` is
`NullCheckoutRegionResolver`, so checkout always falls back to
`numerosis.billing.payment_methods.default_order` unless a host binds its own.
There is nothing left to guard.

`laravel/reverb` / `pusher/pusher-php-server` and `illuminate/broadcasting`
itself were dropped outright (chat's real-time presence channel was their
only caller; the tenant-provisioning status page it also fed already had a
`wire:poll` fallback and needed no push transport of its own). There is
nothing left to guard.

### require-dev

Toolchain (`pest` + arch/laravel/**browser** plugins, `pint`, `larastan`,
`rector`, `collision`, `orchestra/testbench`, `laravel/boost`) plus every
remaining `suggest` this repo's own suite exercises:
`ryangjchandler/laravel-cloudflare-turnstile`, `sentry/sentry-laravel`.

One of those is load-bearing in a way that is easy to undo by accident:
**`pestphp/pest-plugin-browser` makes Playwright a prerequisite for the whole
suite**, not just the browser tests — `Plugin::terminate()` starts the server
on every Pest run, so without `npm install && npx playwright install
chromium` even a single-file `--filter` aborts with no output.

---

## `nvade/numerosis-ui`

The shared Blade layer. **The only other unit**: it requires no other package
here, and `tests/Feature/PackageBoundariesTest.php` enforces that it
references no `Nvade\Numerosis`, no `Filament\`, no `tenancy()` and no
`route()` — it has to stay installable on its own.

**require**: `illuminate/{contracts,support,view}`, `livewire/livewire`,
`livewire/flux`, `spatie/laravel-package-tools`.

`livewire/flux` lives here because the views that render it do — see the
literal-text argument above.

---

## Deciding where the next dependency goes

Two rules. The first is about *what a guard can do*; the second about *what
`suggest` actually buys*.

### Eager vs lazy resolution

`extends` / `implements` / `use <Trait>` resolve their target **at
class-declaration time** — the target must be loadable the moment the
declaring class autoloads. A method's own parameter or return type does not;
it only has to exist when the method is *called*. So:

- A model can name an optional package's class in a signature and stay
  loadable without it. It cannot `implements` one of that package's
  interfaces.
- `class_exists()` **cannot** protect an `implements` clause. PHP never
  consults it; the engine just tries to load the class.
- **A class-constant fetch (`Foo::SOME_CONST`) autoloads too** — it looks like
  a string and is not one.

The fix, where an eager clause is genuinely wanted, is to make the *target*
conditional rather than remove the clause: `Support\Compat\*` declares one
symbol per interface/trait, `if (interface_exists(Real::class))` extending the
real one and otherwise empty. Composer's PSR-4 autoloader maps name→file and
never parses contents, so this needs no autoloader configuration. None exist
today — the two Filament shims were deleted with `packages/filament` in
Phase 1, and `HasOneTimePasswordsIfInstalled`/`LogsActivityIfInstalled` were
deleted 2026-09-05 when both packages moved back to `require`.

Two things no model-level shim protects, to check before moving any `require`
to `suggest`:

- an **unconditional call site** that instantiates one of the package's
  classes — fatals identically;
- a **Blade component tag**, which renders as literal text and *passes* tests
  (`.ai/rules/testing.md`).

### Install-time vs feature-time

- **`config('numerosis.features')` is a code-level switch, not an
  install-level one.** Removing a feature stops routes and listeners from
  registering; it cannot un-install a Composer package. A `suggest` entry
  therefore buys a lean install only for a consumer who *also* edits that
  array.
- **So: `suggest` only for a package whose absence a guard can detect at
  runtime, and whose feature a host would plausibly turn off.** Everything
  else goes in `require`, where the constraint lives once here instead of
  being copied into every host's `composer.json`.
- **Prove the degradation, don't assert it.** `class_exists()` answers `true`
  for an already-declared class regardless of the autoloader, so absence is
  only testable in a **fresh subprocess with Composer's loader wrapped**. It
  needs a positive control and a verified failure, or it passes by resolving
  nothing.

## History worth keeping

- **2026-09-05** — `spatie/laravel-one-time-passwords` and
  `spatie/laravel-activitylog` moved `suggest` → `require` again, this time
  for keeps: the package is always present, `numerosis.features` is the only
  on/off switch. `Support\Compat\{HasOneTimePasswordsIfInstalled,LogsActivityIfInstalled}`
  deleted; `Nvade\Numerosis\Models\User` and `Tenant\User` `use` the real
  traits directly.
- **2026-09-01 to 2026-09-03** — `.claude/plans/archive/humming-nibbling-flame.md`:
  Phase 1 deleted `packages/filament`, `filament/filament` and
  `alizharb/filament-activity-log` outright. Phase 2 deleted the module
  system and `internachi/modular`. Phase 3 folded `-auth-ui`, `-onboarding`
  and `-account` into core; only `-ui` remains a separate split. Phase 4
  moved auth onto `laravel/fortify`. Phase 6 dropped `torann/geoip` and moved
  `ryangjchandler/laravel-cloudflare-turnstile` from `require` to `suggest`.
- **2026-08-10** — eight packages moved `suggest` → `require` on the reasoning
  that they back features shipping *on*, so every host installed them anyway
  while having to list them in its own `composer.json`. `dompdf/dompdf`,
  `mallardduck/blade-lucide-icons` and `openplain/filament-shadcn-theme` were
  removed outright: zero references in either repo.
- **2026-08-28** — `filament/filament`, `spatie/laravel-one-time-passwords`,
  `spatie/laravel-activitylog` and `alizharb/filament-activity-log` moved back
  to `suggest`, once `Support\Compat\*` existed.
- **2026-08-30/31** — the extractions that Phase 3 above later re-folded:
  `livewire/flux` → `-ui`, `laravel/socialite` + both providers → `-auth-ui`,
  `alizharb/filament-activity-log` → `-filament`,
  `spatie/laravel-livewire-wizard` → `-onboarding`.
- **2026-08-31** — `internachi/modular` back to `suggest`, later deleted
  outright in Phase 2 above.

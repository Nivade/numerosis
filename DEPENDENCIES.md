# Dependency triage

Every package in saas-m's `composer.json` `require` block (as of
saas-m commit `ddd7c35`), with a verdict for the package split. Produced by
`.claude/plans/package-extraction.md` Phase 1.8 — no code changes here, this
is the checklist Phase 4's copy and Phase 5's wiring follow.

> **Revision 2026-08-10 — eight packages moved suggest → require, three
> dropped entirely.** The `suggest` bucket was carrying packages that
> `config('numerosis.features')` ships **enabled by default**, so every host
> installed them anyway — but had to list all eight in its *own*
> `composer.json` and keep those constraints in sync by hand. thin-app's
> `require` block went from 24 entries to 14 as a result; nothing about the
> feature toggles changed, since those are code-level switches, not
> install-level ones. `dompdf/dompdf`, `mallardduck/blade-lucide-icons` and
> `openplain/filament-shadcn-theme` had zero references in either repo and
> were removed from both. Details in "Install-time vs feature-time" below.

Three verdicts:

- **require** — numerosis's own `composer.json` `require` block. The package
  cannot function without it; no guard needed, install is always run.
- **suggest + `class_exists()` guard** — feature-gated. Goes in
  `composer.json`'s `suggest` block only. The corresponding `App\Features\*`
  class (or the code path it gates) must check `class_exists()` /
  `interface_exists()` before touching the package's classes, so that
  installing numerosis without the suggested package doesn't fatal on
  autoload of a class that isn't there. **This guard must exist before that
  feature's code is copied in Phase 4** — copying first and guarding later
  means an intermediate commit that fatals for any consumer who hasn't also
  installed the suggested package.
- **thin-app only** — never appears in numerosis's `composer.json` at all.
  Either it belongs to the deployable app by definition (dev tooling, the six
  concrete modules) or numerosis deliberately doesn't know it exists.

## require

| Package | Why it can't be optional |
|---|---|
| `stancl/tenancy` | The tenancy system itself — `TenancyServiceProvider`, every bootstrapper, the `tenant`/`central` connection split. Nothing in the package works without it. |
| `laravel/cashier` | `BillingServiceProvider` binds Cashier's customer/subscription models directly; `Billable`, checkout, webhooks all assume Cashier's classes exist. |
| `spatie/laravel-permission` | `AuthGuardBootstrapper`, every `UserPolicy`/`RolePolicy`, `guardName()` on both user models — permissions are load-bearing for every panel, not a feature. |
| `spatie/laravel-data` | `TenantProvisionData`, `SubscriptionData`, and the rest of `App\Data\*` are typed on it; the provisioning and billing pipelines pass these objects between every action. |
| `lorisleiva/laravel-actions` | Every `App\Actions\*` class (69 of them) is an `AsAction`. The provisioning chain, checkout, module purchase — all of it — is built on this package's calling conventions (see `.claude/rules/tenant-provisioning.md`'s `JobPipeline`-vs-`AsAction` bullet). |
| `spatie/laravel-package-tools` | Already in numerosis's skeleton `composer.json` (not sourced from saas-m — saas-m is the app, not the package). `NumerosisServiceProvider` extends its `PackageServiceProvider`. Listed here only for completeness. |
| `laravel/framework` (as `illuminate/*`) | Not itself installable by a package — numerosis already requires `illuminate/contracts` in the skeleton. As more of `app/` copies in (Phase 4), add the specific `illuminate/*` components actually used (`illuminate/database`, `illuminate/support`, etc.) rather than the `laravel/framework` meta-package. Not in the plan's original three-bucket list; added here because it has no meaningful "optional" state — every class in `src/` depends on some `illuminate/*` symbol. |
| `livewire/livewire` | Also not in the plan's original list, also not optional: the registration wizard, the checkout component, the tenant panel — Livewire is how every one of the package's interactive surfaces is built, not a plugin bolted onto them. |
| `filament/filament` | **Reclassified from suggest during Phase 6 (2026-08-05) — the guard this row originally described does not exist.** `AdminPanelFeature`/`TenantPanelFeature` gate the *panel*, but `src/Models/User.php` (`implements FilamentUser`) and `src/Models/Central/CentralUser.php` compose Filament unconditionally — both are abstract base models every consumer's concrete `User`/`CentralUser` stub extends, regardless of whether either panel feature is enabled. `class_exists()` cannot guard an `implements` clause; the class fails to autoload at all without this package installed. Confirmed by the package's own test suite: `Trait/interface not found` on every model test until this moved to `require`. Making this genuinely optional needs the interface pulled off the base model (a real refactor, not a guard) — not attempted here; see the "Known gap" note below. |
| `spatie/laravel-one-time-passwords` | **Reclassified alongside `filament/filament`, same root cause.** `src/Models/User.php` composes `HasOneTimePasswords` unconditionally (`use` inside the class body, not behind a feature check) — same "interface/trait on an always-loaded abstract model" trap, same fix. |
| `spatie/laravel-activitylog` | **Not in saas-m's own `require` block at all — found only via this package's abstract models, not via the plan's Phase 1.8 audit of saas-m's composer.json.** `src/Models/Tenant/User.php` and `src/Models/Tenant/Invitation.php` compose `LogsActivity` unconditionally; saas-m never listed it directly because `alizharb/filament-activity-log` pulled it in transitively as *that* package's own dependency, so the audit this file records never saw a top-level requirement to classify. Central migration `database/migrations/central/2026_01_19_151109_create_activity_log_table.php` also runs unconditionally (`loadMigrationsFrom` has no feature gate), reading `config('activitylog.table_name')` — a config key nothing in the package publishes, so a host without this installed gets a migration failure (`Incorrect table name ''`), not a clean autoload error. |

| `livewire/flux` | **Moved from suggest 2026-08-10.** 60 shipped views use `<flux:*>` components. Blade leaves an unregistered component as *literal text* rather than erroring (see `.claude/rules/testing.md`), so "optional" here means "silently renders markup as prose", not a guardable degradation — and no `class_exists()` can protect a Blade tag. |
| `internachi/modular` | **Moved from suggest 2026-08-10.** `ModuleSystemFeature` ships enabled, and the whole `src/Actions/Modules/*` + `src/Console/Commands/*TenantModule*` surface talks to it. The `ModuleRegistry` contract abstracts *which* registry, not whether one exists. |
| `laravel/socialite` + `socialiteproviders/discord` + `socialiteproviders/zoho` | **Moved from suggest 2026-08-10.** `SocialLoginFeature` ships enabled; `ConfiguredProviders` already gates the login *buttons* on credentials being present, which is the toggle that matters at runtime. The two providers register themselves against `SocialiteWasCalled` and are meaningless without the base package, so all three move together. |
| `ryangjchandler/laravel-cloudflare-turnstile` | **Moved from suggest 2026-08-10.** `TurnstileFeature` imports `RyanChandler\LaravelCloudflareTurnstile\Rules\Turnstile` at file scope — that resolves whenever the feature class loads to answer `isEnabled()`, i.e. before the switch can be read. |
| `spatie/laravel-livewire-wizard` | **Moved from suggest 2026-08-10.** The five registration components `extends StepComponent`/`WizardComponent`; an `extends` clause is not guardable, same shape as `filament/filament` above. `RegistrationWizardFeature` gates the *route*, not the autoload. |
| `alizharb/filament-activity-log` | **Moved from suggest 2026-08-10.** `ActivityLogFeature` ships enabled and `ActivityResource` extends the package's classes. The `class_exists(ActivityLogPlugin::class)` guard in `NumerosisTenantPlugin` stays — cheap and still correct — but is no longer load-bearing. |

**Known gap, not fixed this phase:** the `filament/filament`,
`spatie/laravel-one-time-passwords` and `spatie/laravel-activitylog` rows above
genuinely contradict `config('numerosis.features')`'s "everything else opt-in" premise for exactly the two abstract user models and the two `Tenant\*` models that carry `LogsActivity`. A consumer who does not want Filament, OTP login, or activity logging still pays for all three at `composer install` time. The structural fix — pull `implements FilamentUser`, `HasOneTimePasswords`, and `LogsActivity` off the base models and onto something feature-conditional (a trait composed only by the thin-app stub when the matching feature is on, mirroring how `#[UsePolicy]` already has to be re-declared per stub because attributes don't inherit) — is real work belonging to whichever phase revisits the 9 abstract models, not a `composer.json` fix. Recorded here so it is not mistaken for settled architecture.

## suggest + `class_exists()` guard

Four left, and all four are **deployment/observability choices a host owns**,
not features the package turns on — which is what makes them genuinely
optional in a way the eight moved rows were not.

| Package | Gated by / guard location |
|---|---|
| `sentry/sentry-laravel` | `TagsSentryScopeWithTenant` already checks `app()->bound('sentry')` before touching it (`.claude/rules/exception-handling.md`) — same pattern, extend to every Sentry touchpoint. Stays in thin-app's own `require` because the DSN, sampling and release wiring are the host's. |
| `laravel/telescope` | `TelescopeServiceProvider` is thin-app-only per Phase 4.2's provider split, so this is really "thin-app's optional dependency". Guarded here by `class_exists('Laravel\Telescope\Telescope')` in `NumerosisServiceProvider` (the scheduled `telescope:prune` entry) — the package's own code must not reference Telescope classes unguarded anywhere else. |
| `laravel/reverb` | Broadcasting/presence-channel code (`broadcasting/auth`, chat presence). Zero PHP references in `src/` — the package talks to whatever `config('broadcasting')` resolves; Reverb is a server the host chooses to run. |
| `pusher/pusher-php-server` | Alternative broadcast driver to Reverb — same broadcasting code paths, gated together. Installs transitively with `laravel/reverb`, so no host needs to name it. |

### Removed outright, 2026-08-10 — not suggest, not require

Audited across `src/`, `config/`, `routes/`, `resources/`, `database/` in this
package *and* `app/`, `config/`, `routes/`, `resources/` in thin-app: zero
references each. They were carried in thin-app's `require` on the strength of
this file's Phase 1.8 guesses ("wherever invoice/PDF export lives", "confirm
during Phase 4 whether any view hardcodes an icon") — call sites that were
never found because they do not exist.

| Package | Finding |
|---|---|
| `dompdf/dompdf` | No PDF/invoice-export code was ever copied into the package, and thin-app has none either. |
| `mallardduck/blade-lucide-icons` | The four `resources/views/flux/icon/*.blade.php` files credit Lucide in a comment but **inline the SVG** and call `Flux::classes()`; nothing renders `<x-lucide-*>`. |
| `openplain/filament-shadcn-theme` | No PHP reference, and no CSS `@import` from it — the panel theme is `resources/css/filament-theme.css`, built from filament/filament's own CSS plus the package's token bridge. thin-app's `shadcn` entry is an npm devDependency, unrelated to this composer package. |

## thin-app only

| Package | Why it never belongs to the package |
|---|---|
| `laravel/tinker` | Dev tooling for the deployable app, not something a package should impose on every consumer. |
| `nvade/alerts` | Concrete app-module — Decision 1.2.6 keeps all six modules app-side; the package ships the module *system*, never a module. |
| `nvade/announcements` | Same. |
| `nvade/branding` | Same. |
| `nvade/chat` | Same. |
| `nvade/notes` | Same. |
| `nvade/tasks` | Same. |

## Coverage check

Every entry in saas-m's `require` block (excluding `php` itself) appears in
exactly one table above: **7** in **require** — `laravel/cashier`,
`lorisleiva/laravel-actions`, `spatie/laravel-data`,
`spatie/laravel-permission`, `stancl/tenancy` (the plan's original five) plus
`laravel/framework` and `livewire/livewire` (not in the plan's original list,
added here as load-bearing) — **17** in **suggest**, **7** in **thin-app
only**. 7 + 17 + 7 = 31, matching saas-m's 31-entry `require` block.
`spatie/laravel-package-tools` is listed in the **require** table too but is
not one of the 31 — it's already in numerosis's skeleton `composer.json` and
was never in saas-m's, so it is not part of this count.

**After the 2026-08-10 revision** the same 31 split **17 / 4 / 7 / 3**
(require / suggest / thin-app-only / removed). The counts above are kept as
written because they describe the Phase 1.8 audit, which is what the rest of
`.claude/plans/package-extraction.md` refers back to.

## Install-time vs feature-time

The distinction this file originally blurred, and the rule to apply when the
next dependency is added:

- **`config('numerosis.features')` is a code-level switch, not an install-level
  one.** Commenting a feature out stops routes, panels and listeners from
  registering; it does not, and cannot, un-install a composer package. Putting
  a package in `suggest` therefore buys a lean install only for a consumer who
  *also* edits the features array — and every one of the eight moved packages
  backs a feature that ships **on**.
- **A `suggest` entry is a promise that the package degrades cleanly without
  it.** That promise needs a real guard: `class_exists()` before touching a
  class, `app()->bound()` before touching a binding. It cannot be kept for an
  `extends`/`implements`/trait `use` clause (the class fails to autoload) or
  for a Blade component tag (it renders as literal text and *passes* tests —
  `.claude/rules/testing.md`).
- **So: `suggest` only for packages whose absence a guard can detect at
  runtime, and whose feature a host would plausibly turn off.** Everything else
  goes in `require`, where the constraint lives once, in this package, instead
  of being copied into every host's `composer.json`.

# Dependency triage

Every package in saas-m's `composer.json` `require` block (as of
saas-m commit `ddd7c35`), with a verdict for the package split. Produced by
`.claude/plans/package-extraction.md` Phase 1.8 — no code changes here, this
is the checklist Phase 4's copy and Phase 5's wiring follow.

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

**Known gap, not fixed this phase:** the three rows above genuinely contradict `config('numerosis.features')`'s "everything else opt-in" premise for exactly the two abstract user models and the two `Tenant\*` models that carry `LogsActivity`. A consumer who does not want Filament, OTP login, or activity logging still pays for all three at `composer install` time. The structural fix — pull `implements FilamentUser`, `HasOneTimePasswords`, and `LogsActivity` off the base models and onto something feature-conditional (a trait composed only by the thin-app stub when the matching feature is on, mirroring how `#[UsePolicy]` already has to be re-declared per stub because attributes don't inherit) — is real work belonging to whichever phase revisits the 9 abstract models, not a `composer.json` fix. Recorded here so it is not mistaken for settled architecture.

## suggest + `class_exists()` guard

| Package | Gated by / guard location |
|---|---|
| `livewire/flux` | Any `flux:` component in a shipped view. 58 views use it today; each is reachable only through a panel or feature that's already gated. |
| `laravel/socialite` | The social-login feature — `App\Http\Controllers\Socialite\Login` and friends. |
| `ryangjchandler/laravel-cloudflare-turnstile` | Login-form Turnstile widget — see `.claude/rules/auth-login.md`'s note on Turnstile drifting between login surfaces before they were unified. |
| `sentry/sentry-laravel` | `TagsSentryScopeWithTenant` already checks `app()->bound('sentry')` before touching it (`.claude/rules/exception-handling.md`) — same pattern, extend to every Sentry touchpoint. |
| `laravel/telescope` | `TelescopeServiceProvider` is thin-app-only per Phase 4.2's provider split, so this is really "thin-app's optional dependency," but the package's own code must not reference Telescope classes unguarded anywhere they might leak in. |
| `laravel/reverb` | Broadcasting/presence-channel code (`broadcasting/auth`, chat presence). |
| `pusher/pusher-php-server` | Alternative broadcast driver to Reverb — same broadcasting code paths, gated together. |
| `alizharb/filament-activity-log` | Activity-log Filament resource/timeline — panel-only, follows `filament/filament`'s guard. Note: this is a *different* dependency from `spatie/laravel-activitylog` above, which moved to `require`; this row is only the optional Filament UI on top of it. |
| `openplain/filament-shadcn-theme` | Panel theme — follows `filament/filament`'s guard; document as a thin-app asset-build prerequisite too (Phase 9). |
| `dompdf/dompdf` | Wherever invoice/PDF export lives — confirm the exact call site during Phase 4 copy and guard it there. |
| `spatie/laravel-livewire-wizard` | The registration wizard specifically (`RegistrationWizardFeature`, already a feature class). |
| `socialiteproviders/discord` | Socialite provider — gated same as `laravel/socialite`, one guard covers both since a provider without the base package is meaningless. |
| `socialiteproviders/zoho` | Same as above. |
| `internachi/modular` | The module *system* (not the six concrete modules, which are thin-app-only below). `ModuleRegistry` contract (Phase 5.3) is what the rest of the package talks to instead of this directly. |
| `mallardduck/blade-lucide-icons` | Icon set used across shipped views — confirm during Phase 4 whether any view hardcodes an icon from this package outside of a feature-gated surface; if so the guard has to live above the view include, not inside it. |

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

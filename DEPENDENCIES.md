# Dependencies, per package

> **Rewritten 2026-08-31** (section F of `.claude/plans/numerosis-consolidation.md`).
> The previous version of this file was the Phase-1.8 triage of **saas-m**'s
> `require` block — the archived host app this package was extracted from —
> patched fifteen times as verdicts changed. saas-m is gone and thin-app is one
> host among several, so the audit's arithmetic ("31 entries split 17/4/7/3")
> described nothing that still exists. What follows is the current map, one
> section per package, plus the two rules that decide where the *next*
> dependency goes. Every finding the old file carried that is still true is
> preserved below; nothing was dropped for being inconvenient.

Five units, one repo (`packages/*`), published as read-only splits. What a
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

Tenancy, billing, provisioning, auth mechanics, the module system, all 85
migrations and every seeder.

### require

| Package | Why it can't be optional |
|---|---|
| `illuminate/*` (15 components) + `laravel/framework` | Named per-component rather than as the meta-package, but `laravel/framework` is required too — Testbench, the console commands and the scheduler reach past the component list. Every class in `src/` depends on some `illuminate/*` symbol. |
| `stancl/tenancy` `^3.10` | The tenancy system itself: `TenancyServiceProvider`, every bootstrapper, the `tenant`/`central` connection split. **v3 only, deliberately** — the dual-version layer was built, measured and deleted (`.claude/rules/stancl-tenancy-v4.md`). |
| `laravel/cashier` | `BillingServiceProvider` binds Cashier's customer/subscription models directly; `Billable`, checkout and the webhook controller all assume its classes. |
| `spatie/laravel-permission` | `AuthGuardBootstrapper`, every policy, `guardName()` on both user models. Load-bearing for every panel, not a feature. |
| `spatie/laravel-data` | `TenantProvisionData`, `SubscriptionData` and the rest of `Data\*` are typed on it; the provisioning and checkout pipelines pass these objects between actions. |
| `lorisleiva/laravel-actions` | Every `Actions\*` class is an `AsAction`. The provisioning chain's calling conventions are built on it (`.claude/rules/tenant-provisioning.md`'s `JobPipeline`-vs-`AsAction` bullet). |
| `livewire/livewire` | Every interactive surface core still owns — checkout, invitations, the account pages — is a Livewire component. |
| `spatie/laravel-package-tools` | `NumerosisServiceProvider extends PackageServiceProvider`. |
| `ryangjchandler/laravel-cloudflare-turnstile` | `TurnstileFeature` imports the `Turnstile` rule at **file scope**, which resolves whenever the feature class loads to answer `isEnabled()` — i.e. before the switch can be read. Not guardable. |
| `torann/geoip` | `ResolveCheckoutRegion` orders checkout's payment methods by region. `HostConfig` defaults `geoip.service`, because torann ships `null` there and `GeoIP::getService()` throws on that. The MaxMind `.mmdb` is a host obligation; a missing one costs the ordering, not the checkout. |
| `nvade/numerosis-ui` | Core's own views render `<x-numerosis::ui.*>`. A Blade tag for an unregistered component renders as **literal text** and passes tests — that is not clean degradation, so it cannot be `suggest`. Same reason `livewire/flux` was never optional; the requirement moved into `-ui` with the views. |

### suggest

| Package | Guard | Absence costs |
|---|---|---|
| `nvade/numerosis-filament` | — (nothing in core names it) | No panel registers, and `filament/filament` stops mattering. |
| `nvade/numerosis-auth-ui` | — | No `/login`, `/register`, `/forgot-password`; the tenant panel falls back to Filament's own login page; no social login. Core keeps guards, `LogoutUser`, verification, `TurnstileFeature` and both `one_time_passwords` migrations. |
| `nvade/numerosis-onboarding` | `Support\Tenancy\SelfServeRegistration::FEATURE` | No `/get-started`; core's five references to the wizard are hidden. |
| `filament/filament` | `Support\Compat\Filament{UserContract,HasTenantsContract}` + `class_exists()` on the provider/asset registration | `Models\User` loses `FilamentUser`/`HasTenants` and still autoloads. |
| `internachi/modular` | `ModuleSystemFeature::available()` — one seam, asked by all 10 consumers across core and `packages/filament` | Marketplace/detail/resource refuse access, purchasing throws `ModulesDisabled`, `SynchronizeModules` no-ops, the three `tenants:*-module` commands are not registered. |
| `spatie/laravel-one-time-passwords` | `Support\Compat\HasOneTimePasswordsIfInstalled` | OTP login unreachable; the trait no-ops. |
| `spatie/laravel-activitylog` | `Support\Compat\LogsActivityIfInstalled` | `Tenant\User`/`Invitation` stop logging. The 9 `activity_log` migrations still run and are fine — `HostConfig::activityLogTable()` defaults `activitylog.table_name` unconditionally, and the migrations contain zero `Spatie\*` class references. |
| `sentry/sentry-laravel` | `app()->bound('sentry')` in `TagsSentryScopeWithTenant` | No tenant tag on job-failure reports. |
| `laravel/telescope` | `class_exists()` on the scheduled `telescope:prune` entry | No prune schedule; `telescope/*` stays CSRF-exempt harmlessly. |
| `laravel/reverb` / `pusher/pusher-php-server` | none needed — zero PHP references; core talks to whatever `config('broadcasting')` resolves | No broadcast server. Chosen and run by the host. |

### require-dev

Toolchain (`pest` + arch/laravel/**browser** plugins, `pint`, `larastan`,
`rector`, `collision`, `orchestra/testbench`, `laravel/boost`) plus every
optional package whose real coverage lives in core's suite:
`filament/filament`, `internachi/modular`, `alizharb/filament-activity-log`,
`spatie/laravel-activitylog`, `spatie/laravel-one-time-passwords`,
`sentry/sentry-laravel`, and all three satellite packages.

Two of those are load-bearing in a way that is easy to undo by accident:

- **`pestphp/pest-plugin-browser` makes Playwright a prerequisite for the
  whole suite**, not just the browser tests — `Plugin::terminate()` starts the
  server on every Pest run, so without `npm install && npx playwright install
  chromium` even a single-file `--filter` aborts with no output.
- **Core `require-dev`s the satellites it `suggest`s.** That is what lets the
  moved code's real coverage stay in core's tenancy/DB harness instead of
  being duplicated four ways. `require-dev`, never `require` — a `require`
  would be a genuine cycle in the graph a host resolves.

---

## `nvade/numerosis-ui`

The shared Blade layer. **The only leaf**: it requires no other unit here, and
`tests/Feature/PackageBoundariesTest.php` enforces that it references no
`Nvade\Numerosis`, no `tenancy()` and no `route()`.

**require**: `illuminate/{contracts,support,view}`, `livewire/livewire`,
`livewire/flux`, `spatie/laravel-package-tools`.

`livewire/flux` lives here because the views that render it do. 60 shipped
views use `<flux:*>`; see the literal-text argument above.

## `nvade/numerosis-filament`

Both panels, every resource and page, the module marketplace UI, and
`Concerns\Modules\PurchasesModules` — which returns `Filament\Actions\Action`
objects and sat in core's `Concerns/` until it was recognised as UI glue.

**require**: `filament/filament`, `nvade/numerosis`, `nvade/numerosis-ui`,
`illuminate/{contracts,support}`, `livewire/livewire`,
`spatie/laravel-package-tools`.
**suggest**: `alizharb/filament-activity-log` — the audit-log *UI*; core keeps
the recording. `ActivityResource extends ActivityLogResource`, an eager
clause, so it uses the same conditional-class-definition pattern as the model
shims.

## `nvade/numerosis-auth-ui`

The auth **screens** only.

**require**: `laravel/socialite` + `socialiteproviders/{discord,zoho}`,
`nvade/numerosis`, `nvade/numerosis-ui`, `illuminate/{contracts,support}`,
`livewire/livewire`, `spatie/laravel-package-tools`.
**suggest**: `spatie/laravel-one-time-passwords` (also `require-dev`, since
`PasswordlessLogin` is what its suite renders).

The two Socialite providers register themselves against `SocialiteWasCalled`
and are meaningless without the base package, so all three moved together.

## `nvade/numerosis-onboarding`

The registration wizard.

**require**: `spatie/laravel-livewire-wizard`, `nvade/numerosis`,
`nvade/numerosis-ui`, `illuminate/{contracts,support}`, `livewire/livewire`,
`spatie/laravel-package-tools`.

`spatie/laravel-livewire-wizard` is a hard `require` here and cannot be
anything else: the step classes `extends StepComponent` / `WizardComponent`,
and an `extends` clause is not guardable. Core does not `require-dev` it
either — the wizard's coverage runs in core's suite only because the whole
satellite is require-dev'd.

---

## Deciding where the next dependency goes

Two rules. The first is about *what a guard can do*; the second about *what
`suggest` actually buys*.

### Eager vs lazy resolution

`extends` / `implements` / `use <Trait>` resolve their target **at
class-declaration time** — the target must be loadable the moment the
declaring class autoloads. A method's own parameter or return type does not;
it only has to exist when the method is *called*. So:

- A model can name `Filament\Panel` in a signature and stay loadable without
  Filament. It cannot `implements FilamentUser`.
- `class_exists()` **cannot** protect an `implements` clause. PHP never
  consults it; the engine just tries to load the class.
- **A class-constant fetch (`Foo::SOME_CONST`) autoloads too** — it looks like
  a string and is not one. This is the trap that cost three core views when
  `SocialLoginFeature` moved, and it is why `SelfServeRegistration::FEATURE`
  lives in core with the satellite's `NAME` defined *as* that constant.

The fix, where an eager clause is genuinely wanted, is to make the *target*
conditional rather than remove the clause: `Support\Compat\*` declares one
symbol per interface/trait, `if (interface_exists(Real::class))` extending the
real one and otherwise empty. Composer's PSR-4 autoloader maps name→file and
never parses contents, so this needs no autoloader configuration. Four exist;
if a fifth is ever needed, consider generating them
(`.claude/rules/optional-dependencies.md`).

Two things no model-level shim protects, to check before moving any `require`
to `suggest`:

- an **unconditional call site** that instantiates one of the package's
  classes (`new Theme(...)`, `Filament::registerPanel(...)`) — fatals
  identically, and both of these really existed;
- a **Blade component tag**, which renders as literal text and *passes* tests
  (`.claude/rules/testing.md`).

### Install-time vs feature-time

- **`config('numerosis.features')` is a code-level switch, not an
  install-level one.** Removing a feature stops routes, panels and listeners
  from registering; it cannot un-install a Composer package. A `suggest` entry
  therefore buys a lean install only for a consumer who *also* edits that
  array.
- **So: `suggest` only for a package whose absence a guard can detect at
  runtime, and whose feature a host would plausibly turn off.** Everything
  else goes in `require`, where the constraint lives once here instead of
  being copied into every host's `composer.json`.
- **Prove the degradation, don't assert it.** `class_exists()` answers `true`
  for an already-declared class regardless of the autoloader, so absence is
  only testable in a **fresh subprocess with Composer's loader wrapped** —
  `tests/Support/module-registry-absence-probe.php` and
  `tests/Feature/Features/ModuleRegistryAbsenceTest`. It needs a positive
  control and a verified failure, or it passes by resolving nothing.

## History worth keeping

- **2026-08-10** — eight packages moved `suggest` → `require` on the reasoning
  that they back features shipping *on*, so every host installed them anyway
  while having to list them in its own `composer.json`. `dompdf/dompdf`,
  `mallardduck/blade-lucide-icons` and `openplain/filament-shadcn-theme` were
  removed outright: zero references in either repo. The four Lucide-crediting
  view files **inline** their SVG; the panel theme is built from Filament's own
  CSS plus this package's token bridge.
- **2026-08-28** — `filament/filament`, `spatie/laravel-one-time-passwords`,
  `spatie/laravel-activitylog` and `alizharb/filament-activity-log` moved back
  to `suggest`, once `Support\Compat\*` existed. Making them optional needed
  two further fixes found only by attempting it:
  `registerFilamentPanels()`/`registerFilamentTheme()` were instantiating
  Filament classes unconditionally, and `ActivityResource extends
  ActivityLogResource` fatals for a host with Filament but not that package.
- **2026-08-30/31** — the extractions. `livewire/flux` → `-ui`,
  `laravel/socialite` + both providers → `-auth-ui`,
  `alizharb/filament-activity-log` → `-filament`,
  `spatie/laravel-livewire-wizard` → `-onboarding`. A dependency moving into a
  satellite does not have to be re-declared in core; the requirement moves
  *with* the code that renders it.
- **2026-08-31** — `internachi/modular` back to `suggest` (decision D-C), with
  the subprocess probe above as proof rather than assertion.

# Reorganize `src/` for discoverability (type-first, cleaned up)

## Context

`src/` is type-first at the top level, domain-second underneath, and that part
is already consistent: `Auth`/`Billing`/`Tenancy`/`Invitations` recur under
`Actions`, `Contracts`, `Data`, `Enums`, `Events`, `Exceptions`, `Http`,
`Listeners`, `Notifications` and `Services`. The confusion is not the shape —
it is a set of specific contradictions that have accumulated on top of it:

- **Two homes for console commands.** `src/Commands/` (1 file) and
  `src/Console/Commands/` (4 files). `src/NumerosisServiceProvider.php:46`
  imports from the first and `:48–51` from the second, five lines apart.
- **The package's front door is filed under "misc".**
  `src/Support/Numerosis.php` is the host-facing boot API (`middleware()`,
  `routes()`, `assetTags()`) — 65 references, and the class `README.md:74`
  tells a host to import. It sits in the same folder as tax-ID helpers.
- **Two things named Features.** `src/Features/` (10 feature classes) and
  `src/Support/Features.php` (the "is it enabled?" query API, 16 call sites).
- **`Support/` is a grab bag.** Package-boot machinery (`HostConfig`,
  `Domains`, `Assets`, `ModelResolver`, `Numerosis`) mixed with domain helpers
  (`Billing/TaxIdType`, `Tenancy/RegistrationState`, `ConfiguredSteps`).
- **Flat folders next to domain-subfoldered siblings.** `Policies/` (9),
  `Observers/` (7), `Notifications/` (2 at root), `Concerns/` (5 at root).
- **A single-file folder that duplicates an existing home.**
  `src/Resolvers/PreservingPathTenantResolver.php` alongside
  `src/Services/Tenancy/`.

Outcome: a developer can predict a file's path from its name and domain, and
`NumerosisServiceProvider` stops importing two spellings of the same concept.
Scope is deliberately limited to the contradictions above — the type-first
shape itself is kept (option A of three considered; domain-first was rejected
as ~120 files and every `paths:` glob in `.ai/rules` for a payoff this
package's seams don't yet demand).

## Constraints

- **Never call `record-rule` during this work.** Per `.ai/rules/index.md`, one
  call rewrote that file from 82 lines to 9, discarding the preamble and every
  row note. Edit rule files and `index.md` by hand.
- `.ai/rules` files whose `paths:` globs name a moved path must be updated in
  the same commit as the move, or the next agent reads the wrong rule.
- PHPStan is level 9 with a warm result cache that hides errors
  (`.ai/rules/static-analysis.md`): compare cold-vs-cold, so run
  `vendor/bin/phpstan clear-result-cache` before the final check.
- Pint before Pest — Pint edits files, so testing first means testing twice.

## The moves

### 1. One home for console commands

`src/Commands/InstallNumerosisCommand.php` →
`src/Console/Commands/InstallNumerosisCommand.php`. Delete `src/Commands/`.
Update the import in `src/NumerosisServiceProvider.php:46`.

`.ai/rules/host-integration-quickstart.md` globs
`src/Commands/InstallNumerosisCommand.php` — update to `src/Console/Commands/`.

### 2. Hoist the front door

`src/Support/Numerosis.php` → `src/Numerosis.php`, namespace
`Nvade\Numerosis`, sitting beside `NumerosisServiceProvider.php`. 65
references across `src/` and `tests/`, plus:

- `README.md:74`
- `docs/extending.md:182,190`
- `packages/ui/src/NumerosisUiServiceProvider.php:21` (a comment naming
  `Support\{Numerosis,Features,Routes\RouteNames}`)

`src/Facades/Numerosis.php` stays where it is — a `Facades/` folder next to
the class it proxies is the Laravel convention, and `docs/extending.md:182`
already explains the pair.

### 3. Kill the Features name collision

`src/Support/Features.php` → `src/Support/FeatureRegistry.php`, class renamed
to `FeatureRegistry`. 16 call sites, mechanical. `src/Features/*` (the feature
classes named in `config/numerosis.php:28–35`) does not move — those FQCNs are
host-facing config values.

### 4. Empty the grab bag of domain helpers

| From | To | Why |
|---|---|---|
| `src/Support/Billing/TaxIdType.php` | `src/Support/Billing/` stays | already domain-scoped; leave |
| `src/Support/Tenancy/RegistrationState.php` | `src/Support/Tenancy/` stays | already domain-scoped; leave |
| `src/Support/ConfiguredSteps.php` | `src/Services/Tenancy/ConfiguredSteps.php` | its own docblock scopes it to `numerosis.tenancy`'s two step lists; it is tenancy logic, not shared utility |
| `src/Resolvers/PreservingPathTenantResolver.php` | `src/Services/Tenancy/PreservingPathTenantResolver.php` | delete `src/Resolvers/` |

What stays in `src/Support/`: `HostConfig`, `Domains`, `Assets`,
`ModelResolver`, `FeatureRegistry`, `Cache/`, `Routes/`, plus the two
domain-scoped subfolders. That set is coherent — package-boot and
host-integration machinery — and `.ai/rules/package-boundaries.md` and
`package-host-bootstrap.md` already describe it as such.

`.ai/rules/identification-modes.md` globs `src/Resolvers/**` — update to
`src/Services/Tenancy/**`.

### 5. Domain-subfolder the flat folders

Same pattern as the nine folders that already do this. Group by the domain the
class's subject belongs to:

- `src/Policies/` → `Auth/` (`UserPolicy`, `RolePolicy`, `PermissionPolicy`,
  `SocialAccountPolicy`), `Billing/` (`PaymentPlanPolicy`, `PlanFeaturePolicy`,
  `SubscriptionPolicy`), `Tenancy/` (`TenantPolicy`), `Invitations/`
  (`InvitationPolicy`). `Concerns/` stays.
- `src/Observers/` → `Auth/` (`CentralUserObserver`), `Billing/`
  (`PaymentPlanObserver`, `PaymentPlanFeatureObserver`), `Tenancy/`
  (`TenantObserver`, `TenantUserObserver`, `DomainObserver`,
  `MembershipObserver`). `Concerns/` stays.
- `src/Notifications/` → `InvitationNotification` to `Invitations/`,
  `TenantNotification` to `Tenancy/` (joins the existing `Auth/`, `Billing/`,
  `Tenancy/`).
- `src/Concerns/` → `Tenancy/` (`TagsSentryScopeWithTenant`,
  `HasGlobalIdentity`, `TenancyAwareUserModel`), `Auth/`
  (`RequiresAuthenticatedUser`). `PublishesPackageAssets` stays at the root —
  it is package infrastructure, not a domain concern.

`.ai/rules/exception-handling.md` globs
`src/Concerns/TagsSentryScopeWithTenant.php` by exact path — update it.

`Concerns\Billing\Billable`, `Concerns\Tenancy\TenancyAwareUserModel` and
`Concerns\Tenancy\HasGlobalIdentity` go on the **host's** User model. Their
FQCNs change, so `docs/host-requirements.md` and `README.md` need a pass.

### 6. Deliberately not touched

Named here so the next reader does not re-open the question:

- **`src/Jobs/`** (1 file, `SeedTenantDatabase`). Conventional Laravel folder,
  and `.ai/rules/tenant-provisioning.md` documents a live JobPipeline-vs-
  `AsAction` calling-convention conflict — folding it into `Actions/Tenancy/`
  is a behavior change, not a move.
- **`src/Rules/`** (3 files). Conventional Laravel folder; all three are one
  domain, so a subfolder adds a level for nothing.
- **`src/Livewire/Actions/Logout.php`.** Reads like `src/Actions/` but is the
  Fortify/starter-kit convention; moving it breaks the expectation instead.
- **`src/Models/{Central,Tenant}/`.** stancl's split, and the nine FQCNs in
  `numerosis.models` are the host's contract.
- **Root files in `Contracts/` and `Exceptions/`** (`Subscribable`, `Feature`,
  `DomainException`, `ShowsMessageToUser`). Genuinely cross-domain base types;
  a root file is the correct signal.

## Delivery

One commit per numbered section above, each left green (`composer lint` then
`composer test`), so the series is bisectable and can be stopped partway. A
seventh commit updates `.ai/rules/index.md` rows, `docs/`, `README.md` and
`CLAUDE.md` for anything the per-commit rule edits did not already cover.

Use `git mv` so history follows the files.

## Verification

1. `vendor/bin/pint --dirty --format agent` after each commit's edits.
2. `vendor/bin/phpstan clear-result-cache && composer analyse` — cold, level 9;
   a warm cache hides errors here.
3. `composer test` (Pest, parallel). Browser tests need
   `npx playwright install chromium` per `.ai/rules/testing.md`.
4. `tests/Feature/PackageBoundariesTest.php` specifically — it scans `src/`,
   `config/`, `routes/`, `resources/`, `database/`, `workbench/` and is the
   test most likely to notice a namespace that moved without its references.
5. Stale-reference sweep, expecting zero hits:
   `grep -rn 'Numerosis\\\(Commands\|Resolvers\)\\\|Support\\Numerosis\|Support\\Features\b' . --exclude-dir=vendor --exclude-dir=node_modules`
6. Boot the harness once — `composer serve` — since `HostConfig::apply()` runs
   at `booting()` and a broken import there is not necessarily a test failure
   (`.ai/rules/package-host-bootstrap.md`).
7. `git diff` on `.ai/rules/index.md` at the end: confirm the preamble and all
   row notes are intact and only the moved globs changed.

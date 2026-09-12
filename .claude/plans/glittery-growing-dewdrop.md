# Provisioning pipeline redesign

**Status: Phases 1–8 executed and audited, 9 outstanding (specified, not built).**
Approved and started 2026-09-12 on branch `refactor/provisioning-pipeline`,
not yet merged. 690 tests pass and `composer analyse` reports 0 on a cold
cache, now with `NumerosisServiceProvider` named in `testbench.yaml`.

An audit on 2026-09-12 found five gaps, all closed in `3a50dc4`: the
`tenant-chain:` lock release still in `MarkTenantProvisioned` that Phase 4
called for deleting and `TenantProvision`'s docblock already claimed was
gone; no retention sweep for completed rows, so the table grew one row per
tenant forever; the stalled-provision log still keying the slug as `domain`;
`CloneTenantSchema`'s docblock naming two deleted things; and no test for
resumability itself. The first attempt at that test passed with the
step-record check stubbed out — a re-run writes the same outcome and `at` is
second-granular, so `step_records` cannot see one. `CountingStep` counts.

Phase 8 rewrote `.ai/rules/tenant-provisioning.md` (current shape up top, the
`JobPipeline` rejection and the central-write-tracking finding added, the
pre-redesign content kept below a historical marker rather than deleted),
added a "Provisioning steps and the contribution seam" section plus a seam
table row to `docs/extending.md`, and deleted one stale comment in
`config/numerosis.php` that still described the old first-entry-is-special
convention. `docs/architecture.md` and `docs/host-requirements.md` needed no
change — nothing in either named the deleted mechanics. Phase 9 remains
specified below but not built.

Every item under "Open flags" was cleared on 2026-09-12, before Phase 7. Three
of those flags turned out to be wrong about their own facts; the corrections
are recorded in place rather than deleted, since each one misled for a while.

## Context

`numerosis.tenancy.provisioning.steps` advertises itself as the provisioning
extension point, but it configures a slice in the middle of the pipeline and
carries several traps a host walks straight into:

- **One array, two signatures.** `$steps[0]::run($registration): Tenant`;
  every later entry is `run($tenant, $data): void`. `Contracts\Tenancy\CreatesTenant`
  and `Boot\ConfiguredSteps::assertTheFirstProvisioningStepCreatesTenant()`
  exist only to make that asymmetry fail at boot instead of on retry five.
- **The config is read twice** — `ProvisionTenant:53` takes `[0]`,
  `RunProvisioningSteps:27` `array_shift`s the rest — so the "drop the first"
  rule is duplicated and silently wrong if a host reorders.
- **Every host step runs in one job.** `RunProvisioningSteps` loops in
  process, so a failure in step 5 re-runs 1–4. That is the whole source of
  the "idempotency is not optional" mandate in `.ai/rules/tenant-provisioning.md`.
- **Head and tail are hardcoded.** Database jobs, `LinkTenantSubscription`
  and `FinalizeTenantProvisioning` are spliced in by
  `ProvisionTenant::dispatchProvisioningChain()`, so nothing can run before
  the database exists or after finalization.
- **Three overlapping mutexes**: `ShouldBeUnique`, the `tenant-chain:` cache
  lock, and `CreateTenant`'s own `tenant-provision:` lock — with the chain
  lock released in two unrelated files.
- **Failure handling in three places** doing the same two calls:
  `ProvisionTenant::jobFailed`, the chain's `->catch()`, and
  `FinalizeTenantProvisioning::jobFailed`.
- **Two DTOs for one thing.** `TenantRegistrationData` is the pre-checkout
  input, `TenantProvisionData` is that plus Stripe ids. Callers nest them.
- **Host data cannot reach host steps.** The DTO is hand-built field by field
  from wizard state at `Plan.php:142` and `TechnicalSetup.php:120` —
  `ProvidesTenantIdentity`'s docblock says so — so a host wizard step's data
  is dropped on the floor.
- **Two step registries with incompatible conventions.** Config entries are
  `AsAction`s called `::run($tenant, $data)`; `TenancyServiceProvider::$tenantCreatedJobs`
  entries are stancl jobs constructed `new $job($tenant)`.

Outcome: one flat, fully configurable ordered list where every entry has the
same signature and is its own queued chain link; the provision row as the
single source of truth for identity, data, progress and mutual exclusion; and
one uniform contribution seam that core's own billing and custom-domain data
travels through alongside a host's.

`.claude/plans/archive/humming-nibbling-flame.md` and
`.ai/rules/tenant-provisioning.md` are the background; much of the latter
becomes historical and is rewritten in Phase 8.

## Target shape

```php
// config/numerosis.php
'provisioning' => [
    'steps' => [
        CreateTenant::class,
        CreateTenantDatabase::class,   // adapts Stancl\Tenancy\Jobs\CreateDatabase
        MigrateTenantDatabase::class,  // adapts Stancl\Tenancy\Jobs\MigrateDatabase
        SeedTenantDatabase::class,
        AddTenantOwner::class,
        LinkTenantSubscription::class,
        FinalizeTenantProvisioning::class,
    ],
],
```

Every entry implements one contract, is one `Bus::chain` link with its own
`$tries`, and is skipped-and-recorded when its declared contributions are
absent:

```php
interface ProvisioningStep
{
    public function handle(TenantProvision $provision): void;
}

interface ConsumesContributions
{
    /** @return list<class-string<ProvisionContribution>> */
    public static function consumes(): array;
}
```

Entry point, one DTO, everything beyond identity contributed:

```php
app(ProvisionsTenant::class)->queue(new TenantProvisionData(
    slug: 'acme',
    name: 'Acme Co',
    global_id: $user->global_id,
    contributions: [
        new BillingContribution(payment_plan: 'pro', billing_cycle: Monthly),
        new SeatCountContribution(seats: 5),
    ],
));
```

### Named constraint: contributions are transport, queried columns stay columns

Several of the fields moving into contributions are **query predicates**, not
just payload, so they cannot live in a JSON blob:

| Read | Where |
|---|---|
| `where('stripe_setup_intent_id', …)` | `Actions/Billing/Checkout/ResolveSetupIntent.php:34` |
| `whereNull('stripe_subscription_id')` | `Http/Controllers/Billing/WebhookController.php:139` |
| `where('stripe_subscription_id', …)` | `WebhookController.php:309` |
| `unreservedByAnyoneElse('custom_domain', …)` | `Livewire/Tenant/Registration/Steps/TechnicalSetup.php:81` |

So a contribution declares how it persists. `BillingContribution` and
`CustomDomainContribution` implement `fromProvision(TenantProvision): ?static`
and read real columns; contributions without one round-trip through the
`contributions` JSON column. `TenantProvision::contribution(X::class)`
dispatches on which. Core is not privileged here — a host needing a queryable
field adds a column and a `fromProvision()` exactly as core does.

## Phases

Each phase ends with `composer lint` and a green `composer test`.

### 1. The provision row becomes the record of a provision

`PendingTenantProvision` → `TenantProvision`; table `pending_tenant_provisions`
→ `tenant_provisions`; primary key `domain` → `slug`.

Consolidate the three migrations (`2026_07_27_010000_create_pending_tenant_provisions_table.php`,
`2026_07_29_130223_extend_pending_tenant_provisions_for_checkout.php`,
`2026_08_30_000000_add_custom_domain_to_pending_tenant_provisions.php`) into
one `create_tenant_provisions_table`, as commit `067e623` did for the central
set. No installs exist, so there is nothing to migrate.

New columns: `provisioning_started_at`, `completed_at`, `step_records` (json),
`contributions` (json), `settled_at`.

**Split the overloaded `status`.** `TenantProvisionStatus::AwaitingPayment`
and `::Provisioning` are mutually exclusive enum values that both mean
"provisioning is running", which blocks using `status` as a mutex. Delete the
`AwaitingPayment` case; `status` becomes the pure lifecycle
`Reserved`/`Provisioning`/`Completed`/`Failed`, and settlement moves to
`settled_at`. Call sites: `Actions/Billing/Checkout/SettleCheckout.php:41`
sets `settled_at`; `WebhookController.php:310` queries `whereNull('settled_at')`
instead of `where('status', AwaitingPayment)`;
`resources/views/components/billing/payment-status-banner.blade.php` and
`⚡mine.blade.php` read the new field.

Mechanical but wide — ~30 files name the model or `domain`.

### 2. One DTO

Merge `TenantRegistrationData` into `TenantProvisionData` flat: `slug`,
`name`, `global_id` required; the six optional fields stay flat *for this
phase only*. Keep `Wireable` and `rules()`. Delete `TenantRegistrationData`.

`company_name` → `name` (the package should not assume tenants are
companies), `domain` → `slug` (it is the primary key and the subdomain
label, not a domain — `custom_domain` is the actual FQDN).

Call sites: `ReserveTenantDomain`, `Contracts\Billing\CheckoutGateway::begin()`
and its three implementations, `StartSubscriptionCheckout`,
`StartCheckoutRequest::toRegistrationData()`, `LinkSubscriptionToTenant`,
`Livewire/Tenant/Registration/Steps/{Plan,TechnicalSetup}`,
`tests/Concerns/BuildsTenantProvisionData`.

### 3. Contributions

Add `Contracts\Tenancy\ProvisionContribution` (marker on a spatie `Data`
subclass) and `TenantProvision::contribution()` with the column/JSON dispatch
above. Ship `Data\Tenancy\BillingContribution` (`payment_plan`,
`billing_cycle`, `stripe_setup_intent_id`, `stripe_subscription_id`,
`stripeCustomerId`) and `Data\Tenancy\CustomDomainContribution`.

Move those six fields off `TenantProvisionData` into contributions, so the
DTO is the identity triple plus `list<ProvisionContribution> $contributions`.
Update the billing and checkout call sites to build contributions;
`InlineCheckoutGateway`, `CreateInlineSubscription`, `ResumeCheckout`,
`SettleAttachedPaymentMethod` and `AssertPendingReservationIsFresh` keep
reading their columns off the model and are unaffected.

### 4. The pipeline itself

- Add `Contracts\Tenancy\{ProvisioningStep,ConsumesContributions}`.
- Rewrite the seven steps to `handle(TenantProvision $provision): void`.
  `LinkTenantSubscription` declares `consumes(): [BillingContribution::class]`
  and drops its internal `if (! $data->stripeCustomerId …) return;` guard —
  the runner now owns that decision.
- New `CreateTenantDatabase` / `MigrateTenantDatabase` steps adapting stancl's
  jobs (constructor-injected tenant, zero-arg `handle`).
- `ProvisionTenant::queue()` becomes: upsert the provision row from the DTO →
  acquire → `Bus::chain([...configured steps])->onQueue('provisioning')->dispatch()`.
  **No outer queued job**, so nothing runs synchronously-inside-a-job any
  more. Delete `ShouldBeUnique`, `jobFailed`, and `RunProvisioningSteps`.
- **Row as mutex.** One conditional update, affected-rows is the acquire:

  ```php
  $acquired = TenantProvision::where('slug', $slug)
      ->where(fn ($q) => $q
          ->where('status', '!=', Provisioning)
          ->orWhere('provisioning_started_at', '<', now()->subMinutes(15)))
      ->update(['status' => Provisioning, 'provisioning_started_at' => now()]) === 1;
  ```

  Deletes all three cache mechanisms, including `CreateTenant`'s inner lock
  and the `Cache::lock(...)->forceRelease()` in `MarkTenantProvisioned`, and
  the atomic-lock-capable-driver requirement with them. `tenancy:prune-stalled-provisions`
  already reads exactly this state.
- **Per-step records.** A wrapper skips any step already recorded done and
  appends `{step, outcome, at}` to `step_records` — `done`, or
  `skipped: <missing contribution>`. This is what removes the idempotency
  mandate, makes per-step retry safe, allows per-step database gating (which
  is why the segment is all-or-nothing today) and closes the documented gap
  where a permanent migrate failure sends later retries into a broken
  `AddTenantOwner`.
- **One terminal failure handler** on the chain's `->catch()`, capturing
  scalars only. Delete `FinalizeTenantProvisioning::jobFailed` and
  `SeedTenantDatabase::failed`.
- `MarkTenantProvisioned` stamps `completed_at` and `status = Completed`
  instead of deleting the row. `⚡mine.blade.php` already excludes rows whose
  slug is in the user's tenant list, so nothing double-renders. Add a
  retention sweep to `PruneStalledTenantProvisions`.
- `TenancyServiceProvider`: `TenantCreated => []`, delete `$tenantCreatedJobs`.
  `TenantDeleted => [DeleteDatabase]` stays. `CreateTenant` no longer needs
  `withoutEvents()` as a race guard — there is no second path left to race.
  `TenantDatabaseManager::creationJobs()` goes; `databaseExists()` stays (the
  steps use it to self-gate).
- Delete `Contracts\Tenancy\CreatesTenant` and
  `ConfiguredSteps::assertTheFirstProvisioningStepCreatesTenant()`; the boot
  check becomes "every configured step implements `ProvisioningStep`". The
  registration half of `ConfiguredSteps` is untouched.

Tests: `tests/Pest.php:21` and `tests/Feature/FreshHostTest.php:181` override
the config list instead of the static; `tests/Support/CloneTenantSchema.php`
becomes a `ProvisioningStep`. `ProvisionTenantTest` needs rework throughout —
`test_it_dispatches_the_database_segment_for_a_retry_with_no_physical_database`
and `test_it_asks_the_bound_tenant_database_manager` change meaning.

### 5. Wizard collection

Add `Contracts\Tenancy\ContributesProvisionData::provisionContributions(): array`.
`Livewire/Tenant/Registration.php` collects from every configured step,
replacing the two hand-built DTO constructions at `Plan.php:142` and
`TechnicalSetup.php:120`. Core's `Plan` step contributes a
`BillingContribution`, `TechnicalSetup` a `CustomDomainContribution` — same
seam a host uses. Update `ProvidesTenantIdentity`'s docblock, which currently
documents the by-hand construction.

Extend the existing `tests/Support/HostSecretStep` fixture, or add a sibling,
to prove a host step's contribution reaches a host provisioning step.

### 6. `tenancy:provision` console command

`php artisan tenancy:provision acme --owner=<global_id> --name="Acme Co"`
builds a `TenantProvisionData` and calls `ProvisionsTenant::queue()`. First
explicit non-checkout entry point — `src/Console/Commands/` has
`DeleteTenants` but nothing that creates one. Also the handle for exercising
the pipeline against `workbench/` during verification.

### 7. Progress UI — done 2026-09-12

`⚡mine.blade.php` reads `step_records` to show the running step name instead
of a bare "Setting up…" spinner. The existing `wire:poll.5s="refreshTenants"`
and `isWorkOutstanding()` already drive it.

`TenantProvision::currentStep()` already existed, so the work was a label and
a lookup. `currentStepLabel()` translates by class basename through the new
`resources/lang/en/tenancy.php`, and falls back to `Str::headline()` so a
host's own step reads as "Record Seat Count" rather than an FQCN or a missing
key.

Two lists needed feeding, not one. A tenant whose row exists but is not
provisioned sits in `provisioningTenants`, and its provision row is excluded
from `pendingTenants` by the slug filter, so the component now also keeps
`provisionsBySlug`. A `Reserved` row says "awaiting checkout" instead of
naming a step, since nothing is running yet.

Tests pinning the step list are doing it deliberately: `Tests\TestCase` swaps
the migrate and seed steps for `CloneTenantSchema`, so the step in flight
after a given pair differs from what a real install would run.

### 8. Docs and rules — done 2026-09-12

- `docs/extending.md` — provisioning steps, the contribution seam, the Fortify-style
  customization table, the events table at lines 232–235. Add the two seams
  the flag-clearing pass introduced and that nothing outside their own
  docblocks documents: `Contracts\Tenancy\ControlsItsOwnRetries` (a step's own
  `tries()`/`backoff()`) and `Testing\BuildsTenantDatabasesOnCreate` (a host
  test suite getting a database from a bare `Tenant::create()`).
- `.ai/rules/tenant-provisioning.md` — the "Async provisioning chain",
  "one pipeline definition", `JobPipeline`-vs-`AsAction` and
  `$tenantCreatedJobs` sections all become historical; mark them, don't
  delete. Add the `JobPipeline` finding below, which is not recorded anywhere.
- `docs/architecture.md`, `docs/host-requirements.md`, `config/numerosis.php`
  comments.

## Rejected: `TenantCreated`'s JobPipeline as the steps' home

Considered and ruled out against `vendor/stancl/jobpipeline/src/JobPipeline.php`.
Worth recording because it is the obvious-looking seam:

- `handle()` is one `foreach` in a single queued job — the same
  all-steps-in-one-job shape as `RunProvisioningSteps`, so no per-step retry.
- It **swallows failures**: a step with a `failed()` method gets it called and
  the loop `break`s, with no rethrow, so the queue records success. Also
  `if ($result === false) break;` stops silently.
- `TenantCreated` is a `created` model event
  (`vendor/stancl/tenancy/src/Database/Models/Tenant.php:56`), so it fires
  exactly once ever. A crash that leaves the tenant row behind never
  re-fires it — structurally unresumable.
- `CreateTenant` cannot be in the list, since it is what fires the event, so
  the index-0 asymmetry relocates rather than disappears.

Its one useful idea — that steps receive only what the pipeline passes, not a
DTO copied into every link — is what pushed the provision row to be the data
carrier.

## Open flags, recorded 2026-09-12 — resolved 2026-09-12

All of these were addressed in the flag-clearing pass before Phase 7. Each
section below keeps the original finding and records what happened to it.
Three of the findings were themselves wrong; the corrections are inline.

### Trusting `composer analyse` needs a cold cache

A warm PHPStan result cache hid **113 errors** across several phases of this
work. Every per-phase "analysis clean" claim before `53da8f1` was measured
warm and is weaker evidence than it looked. Run
`vendor/bin/phpstan clear-result-cache` first when the answer matters.
`.ai/rules/static-analysis.md` already says this; it was cited in the same
session it was then violated.

### The package's commands are unreachable from `php artisan`

`app()->providerIsLoaded(NumerosisServiceProvider::class)` is **false** in the
bare Testbench console boot, so `numerosis:install`, `tenants:delete`,
`tenancy:prune-stalled-provisions`, `tenancy:provision` and the pruning
commands are all absent from `php artisan list`. A package is the root
package, so composer's auto-discovery never sees it.

This contradicts CLAUDE.md's toolchain table, and it means **any manual
`php artisan` verification done in this repo has been running without the
package loaded.**

Naming the provider in `testbench.yaml` fixes the commands and was tried in
`3b27cbb`, then reverted in `53da8f1`: loading it changes how larastan
resolves `Numerosis::model()`, costing 113 cold analysis errors. The gap is
real and wants its own change that handles both.

**Resolved.** The provider is named in `testbench.yaml` again, and the cold
count is 0. All 113 errors were in `tests/`, none in `src/`, and all were one
family: a test helper whose signature named a workbench subclass
(`App\Models\Central\CentralUser`) while the value reaching it was statically
the package base class, because `ModelResolver::factoryFor()` sends every
`\Models\` class to the package's own factory and that factory's
`@extends Factory<PackageModel>` is what larastan reads. Runtime is
unaffected — `modelFor()` still builds the host subclass.

The fix is to widen the ~30 helper signatures to the package base class,
through an aliased import (`use …\CentralUser as BaseCentralUser`), leaving
every `::factory()` / `::findOrFail()` call site naming the App class exactly
as before. **Swapping whole imports instead does not work**: only factories
redirect through `ModelResolver`, so a direct
`Nvade\…\CentralUser::findOrFail()` returns the package class and 13 tests
fail on `assertAuthenticatedAs` identity comparisons. Two other routes were
tried and abandoned — `#[UseFactory]` on the workbench models, and making the
package factories generic — because larastan resolves `Model::factory()`
through the booted `factoryFor()` and ignores both.

A further 14 errors were not that family at all but genuine nullability gaps
the booted provider newly exposes (`$user->fresh()` is `?static`,
`$plan->getPrice()` is `?int`, `$tenant->database()->getName()` is
`?string`). Those are fixed properly, not widened.

### `Tenant::create()` no longer builds a database

`TenantCreated` is empty, so building a database is a provisioning step and
never a side effect of a model event. That broke 63 tests at once, because
most of the suite creates a tenant directly and only wants a working one;
`tests/TestCase.php` re-registers the convenience as a harness listener, and
it stands aside when a provision row exists so the real steps still do their
own work.

**Undecided at the time:** whether hosts want a supported convenience for
this, or whether provisioning-only is the right constraint. Right now it is
provisioning-only. Phase 9 settles it, and lands on neither: the convenience
is for the development loop, and it runs the configured steps rather than
its own.

**Partly resolved.** The framing was wrong: this is two audiences, not one.
A host's *test suite* wanting a cheap working tenant is a proven need — our own
harness has it, and 63 tests is the measurement. A host's *production* code
calling `Tenant::create()` is the case that would recreate the deleted second
path. `src/Testing/` already ships `CleansUpTenancyDatabases` and the Stripe
fakes to hosts while `tests/TestCase.php` ships nothing, so a host inherited
the cleanup helper and had to reinvent this listener, guard and all.

So the listener is now `src/Testing/BuildsTenantDatabasesOnCreate`, with
`shouldBuildDatabaseFor()` and `afterTenantDatabaseCreated()` as the two
override points; `Tests\TestCase` composes it and supplies the
`CloneTenantSchema` template guard and the clone itself. Production stays
provisioning-only. Whether a *production* opt-in should exist is Phase 9.

### Per-step retries are uniform

`RunProvisioningStep` gives every step `$tries = 5`. `FinalizeTenantProvisioning`
used to have 20, because a silent exhaustion there left the UI spinning
forever. The chain's single terminal handler removes that reason, but the
choice was made for simplicity and is worth revisiting if a step turns out to
need its own retry profile.

**Resolved.** `Contracts\Tenancy\ControlsItsOwnRetries` is the seam, shaped
like `ConsumesContributions`: `tries()` and `backoff()`, both static, read in
`RunProvisioningStep`'s constructor because the worker takes those values off
the serialized job. No core step declares one — 5 and 5 remain the default —
so this opens the door without moving anything through it.

### Stale references that predate this work

- **Seven comments cite `custom-checkout.md`, which does not exist** — no such
  file in `docs/` or `.ai/rules/` (the closest is `billing-checkout.md`):
  `resources/views/components/billing/{payment-element,payment-error,awaiting-payment-card}.blade.php`,
  `resources/js/stripe-checkout.js` (×2), `resources/js/stripe-appearance.js`.
  `general.md` says comments should not cite `docs/` or `.ai/rules` at all, so
  the fix is probably deletion rather than repointing.

  **Wrong premise, right fix.** The file exists, at
  `.claude/plans/archive/custom-checkout.md`; nothing dangles. The citations
  still had to go, because `general.md` bars a comment from naming a
  `.claude/plans/` file at all. Six deleted, all trailing pointers on comments
  that already carried the fact. Three more live in `tests/`, and those stay:
  `general.md` exempts `tests/` explicitly.

  Two gaps in that rule's own detection surfaced here. Its grep covers
  `src/ config/ routes/ database/ workbench/ packages/` but not `resources/`,
  where all six lived; and it is `--include='*.php'`, so the two `.js` files
  could never match.
- **`.ai/rules/testing.md` places `deleteCentralWrites()` in the test suite**;
  it lives in `src/Testing/CleansUpTenancyDatabases.php`.
  `tests/Feature/FreshHostTest.php:227` still points at the old location.

  **Resolved**, and the same rule carried two more errors found while fixing
  it: it claims `deleteCentralWrites()` is `protected` "so a suite with its own
  teardown ordering can call it directly" when the method has been `private`
  since `8984955` first wrote it (`cleanUpTenancyDatabases()` is the protected
  one), and it names `MakeFirstUserAdminTest`, which is
  `PromoteFirstUserToAdminTest`.
- **`tests/Feature/Jobs/` holds tests for classes that are Actions now** —
  `SeedTenantDatabaseTest`, `FinalizeTenantProvisioningTest`. Path drift only.

  **Resolved**: both moved to `tests/Feature/Actions/Tenancy/`. The directory
  came back for `RunProvisioningStepTest`, which tests an actual job. Moving a
  file strands any `phpstan-baseline.neon` entry pointing at it — one entry
  needed its `path:` updated, and it reports as `ignore.unmatched` rather than
  silence.

## Phase 8 additions

Beyond what Phase 8 already lists:

- **The `JobPipeline` finding is recorded nowhere.** See "Rejected" above: it
  runs every job in one queued job, and swallows a failure whose job defines
  `failed()` — the queue records success. Worth a rule of its own; it is the
  obvious-looking seam anyone would reach for next.
- **`.ai/rules/tenant-provisioning.md` is now largely historical.** The async
  chain shape, "one pipeline definition", the `JobPipeline`-vs-`AsAction`
  conflict and `$tenantCreatedJobs` all describe deleted code. Mark, do not
  delete — the reasoning is why the current design looks as it does.
- **`SeedTenantDatabase` can now be an Action.** That rule says it cannot,
  correctly, because `JobPipeline` owned its calling convention. Nothing does
  any more.
- **New rules worth recording**: the central-write tracking lost on
  `refreshApplication()` (fixed in `53da8f1`, and the mechanism that made it
  read as a flake); the contribution seam and why contributions declare their
  own storage; step records and what they replace.

### 9. `Tenant::create()` runs the pipeline inline — specified, not executed

Phase 8's companion question, carved out of the `Tenant::create()` flag above
once the test-suite half was settled. **Nothing here is built.**

The driver is the development loop, not a host's production contract. A
tenant is only usable once six things have happened, and since Phase 4 only
provisioning does them, so `Tenant::create()` in tinker returns a row whose
first use is `Unknown database`. The proper entry point works but dispatches
to the `provisioning` queue — `config('queue.default')` is `database` in the
workbench — so nothing happens until a worker is started in a second
terminal. Both halves have to go for the loop to be quick.

**Shape: a `TenantCreated` listener, default off, running the configured
steps inline.** By the time `Tenant::create()` returns, the database exists,
migrations have run, it is seeded and marked ready, and `step_records` shows
which steps ran.

Not a literal `Tenant::create()` override. `create()` is forwarded to the
query builder rather than defined on `Model`, so a static override catches
that spelling and misses `Tenant::factory()->create()`,
`firstOrCreate()`, `updateOrCreate()` and `$user->tenants()->create()` — this
repo is split about evenly between the first two. It also recurses, since
`CreateTenant` itself calls `$tenantClass::create()`. The event fires for
every path, and the recursion answers itself: during real provisioning the
provision row already exists, so the listener stands aside, which is
`shouldBuildDatabaseFor()`'s existing guard.

**This reverses part of Phase 4 and should read as a reversal.** `TenantCreated`
was emptied deliberately. Three conditions are what keep it from being the
second path the redesign deleted:

- **Default off.** Production keeps one path: queued, resumable, `queue()`.
  On in `workbench/`, and in a host's local environment if it wants it.
- **It reads `numerosis.tenancy.provisioning.steps`,** rather than doing its
  own create-migrate-seed. The mechanism this replaces was a second
  *definition* of what building a tenant means; this one has none. A step
  added to the list runs here too.
- **It refuses inside an open transaction.** Running the steps in a `created`
  event puts the tenant insert and `CREATE DATABASE` in one call stack, and
  MySQL implicitly commits on DDL, so a caller inside a transaction has it
  ended underneath them — the trap `tests/Support/CloneTenantSchema.php`
  documents at the cost of 117 unrelated failures. `DB::transactionLevel() > 0`
  throws with that named.

`withoutEvents()` in `CreateTenant` is **not** part of this, contrary to the
flag this phase came from. The guard it was meant to replace is not
check-then-act: `ProvisionTenant::recordRequest()` commits the provision row
before `claim()` and before the chain dispatches, so the listener reads
settled state. And `withoutEvents()` is indiscriminate — it would blind a
host's own `Tenant` observer for the whole of provisioning. Core's
`Observers\Tenancy\TenantObserver` only handles `deleting`, so core would
never notice the damage.

**Ownership is passed, not inferred.** `Tenant::create(['id' => 'acme',
'created_by' => $user->global_id])` — `created_by` is the real column
`CreateTenant` already populates from the provision row, not a dev-only
parameter. Omitted, `AddTenantOwner` is recorded skipped and the tenant has
no members. Falling back to the first central user was considered and
rejected: it silently attaches the tenant to whoever is row one, and the
mistake surfaces far from its cause. Two lines to add later if naming the
owner proves tedious in practice.

Also in scope, because it is the other half of the slow loop and useful
without any of the above:

- **`ProvisionsTenant::now(TenantProvisionData $data): void`** — same row
  write, same claim, same configured steps, same records, but
  `Bus::chain(...)->onConnection('sync')`, so a failure throws at the call
  site instead of landing in `failed_jobs`.
- **`tenancy:provision --sync`**, on `now()`. Makes the tinker one-liner
  `Artisan::call('tenancy:provision acme --sync')`, with no imports.

The listener is what `now()` calls, so there is one inline implementation.

**`TenantDeleted => [DeleteDatabase]` stays unconditional, deliberately.**
Deletion tearing down what creation only optionally builds is asymmetric on
purpose: a database with no tenant row is unreachable garbage, while a tenant
row with no database is a recoverable state the pipeline owns. Record it, so
it does not read as an oversight later.

`Tests\TestCase` keeps composing `Testing\BuildsTenantDatabasesOnCreate`
rather than switching to this. The suite wants the `CloneTenantSchema`
template copy (~1.9s of migrate-and-seed avoided per tenant); this wants the
real steps. Different jobs, and the plan is for both to exist.

Tests:

- Off by default: `Tenant::create()` leaves no database and no provision row.
- On: `Tenant::create()` returns a tenant whose database is migrated and
  seeded, with `step_records` covering the configured list.
- On, with no `created_by`: `AddTenantOwner` recorded skipped, nothing failed.
- On, inside `DB::transaction()`: throws, naming the DDL commit.
- During real provisioning with the flag on: the listener stands aside and
  each step still runs exactly once — the `CountingStep` assertion shape from
  `test_a_failed_step_resumes_without_re_running_the_steps_before_it`, since
  comparing `step_records` cannot see a second run inside the same second.

Prior art to read first: `.ai/rules/tenant-provisioning.md`'s account of the
`TenantCreated` JobPipeline, and this plan's "Rejected" section, which is why
the event is empty in the first place.

## Verification

- `composer lint` and `composer test` green after every phase. MySQL first:
  `docker start numerosis-mysql-1`.
- Targeted: `vendor/bin/pest --filter=Provision`, `--filter=Checkout`,
  `--filter=Registration`, `tests/Feature/FreshHostTest.php`,
  `tests/Feature/Boot/`.
- **Resumability, the load-bearing new behaviour.** A test that fails
  `MigrateTenantDatabase` permanently, asserts `step_records` holds
  `CreateTenant`/`CreateTenantDatabase` as done, re-dispatches, and asserts
  those two do not run again while migrate does.
- **Skip recording.** Provision with no `BillingContribution`; assert
  `LinkTenantSubscription` is recorded skipped rather than absent, and that
  nothing failed.
- **Mutex.** Two `queue()` calls for one slug; assert one chain dispatched.
  Then age `provisioning_started_at` past the window and assert a third call
  does acquire.
- **Host extension, end to end.** A host contribution + a host step +
  a host wizard step, asserting data travels wizard → row → step.
- Fresh database, not a reused volume: `.ai/rules/tenant-provisioning.md`
  records that a reused MySQL volume hid a broken `SeedTenantDatabase` for
  months, because the template-build path never ran. Drop and recreate before
  trusting a green run.
- By hand against `workbench/`: `composer serve`, then
  `php artisan tenancy:provision …` with `php artisan queue:work --queue=provisioning`,
  watching the step names appear in `⚡mine`.

  **Done 2026-09-12, on MySQL**, and it was worth doing. Seven links ran
  under a real worker, `LinkTenantSubscription` recorded `skipped` for the
  absent `BillingContribution`, and the row finished `completed`. Getting
  there needed three fixes to the harness itself and turned up one live bug
  the whole suite could not see, because `QUEUE_CONNECTION=sync` means no
  test crosses a worker boundary — see `.ai/rules/package-host-bootstrap.md`
  and `.ai/rules/exception-handling.md`. The `⚡mine` half is Phase 7's.

- **Host extension, end to end: done** —
  `tests/Feature/Tenancy/HostProvisioningExtensionTest.php`.

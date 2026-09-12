# Provisioning pipeline redesign

**Status: Phases 1–6 executed, 7–8 outstanding.** Approved and started
2026-09-12 on branch `refactor/provisioning-pipeline` (14 commits,
`5219a09`..`53da8f1`, not yet merged). 677 tests pass; `composer analyse` is
clean **on a cold cache** — see the flags below before trusting a green run.

Phases 1–6 are done and each is audited. **Phase 7 (progress UI) and Phase 8
(docs + rules) have not been started**, and Phase 8 has grown — the new
material at the bottom of this file belongs in it.

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

### 7. Progress UI

`⚡mine.blade.php` reads `step_records` to show the running step name instead
of a bare "Setting up…" spinner. The existing `wire:poll.5s="refreshTenants"`
and `isWorkOutstanding()` already drive it.

### 8. Docs and rules

- `docs/extending.md` — provisioning steps, the contribution seam, the Fortify-style
  customization table, the events table at lines 232–235.
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

## Open flags, recorded 2026-09-12

Things found while executing phases 1–6 that are **not fixed** and are not
this plan's subject. Each is a real finding, not a suspicion.

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

### `Tenant::create()` no longer builds a database

`TenantCreated` is empty, so building a database is a provisioning step and
never a side effect of a model event. That broke 63 tests at once, because
most of the suite creates a tenant directly and only wants a working one;
`tests/TestCase.php` re-registers the convenience as a harness listener, and
it stands aside when a provision row exists so the real steps still do their
own work.

**Undecided:** whether hosts want a supported convenience for this, or whether
provisioning-only is the right constraint. Right now it is provisioning-only.

### Per-step retries are uniform

`RunProvisioningStep` gives every step `$tries = 5`. `FinalizeTenantProvisioning`
used to have 20, because a silent exhaustion there left the UI spinning
forever. The chain's single terminal handler removes that reason, but the
choice was made for simplicity and is worth revisiting if a step turns out to
need its own retry profile.

### Stale references that predate this work

- **Seven comments cite `custom-checkout.md`, which does not exist** — no such
  file in `docs/` or `.ai/rules/` (the closest is `billing-checkout.md`):
  `resources/views/components/billing/{payment-element,payment-error,awaiting-payment-card}.blade.php`,
  `resources/js/stripe-checkout.js` (×2), `resources/js/stripe-appearance.js`.
  `general.md` says comments should not cite `docs/` or `.ai/rules` at all, so
  the fix is probably deletion rather than repointing.
- **`.ai/rules/testing.md` places `deleteCentralWrites()` in the test suite**;
  it lives in `src/Testing/CleansUpTenancyDatabases.php`.
  `tests/Feature/FreshHostTest.php:227` still points at the old location.
- **`tests/Feature/Jobs/` holds tests for classes that are Actions now** —
  `SeedTenantDatabaseTest`, `FinalizeTenantProvisioningTest`. Path drift only.

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

---
paths:
  - 'src/Actions/Tenancy/**'
  - 'src/Jobs/**'
  - 'src/Console/Commands/**'
---
# Tenant Provisioning

> **Everything here is v3, which is the only version this package supports.**
> Three bullets below become stale on an eventual `dev-master` port and are
> flagged for it, not for now: the `Stancl\Tenancy\Commands\Seed`
> double-break is **fixed** there (so `SeedTenantDatabase`'s container-resolve
> workaround exists purely for v3 and must not be deleted while v3 is the
> constraint), the `is_bot`-column crash needs re-testing against the reworked
> `UpdateOrCreateSyncedResource`, and the `JobPipeline`-vs-`AsAction` conflict
> appears intact — dev-master's provider stub still uses `JobPipeline` — but
> jobpipeline itself goes v1 → `2.0.0-rc7`, so re-verify rather than assume.
> See `.ai/rules/stancl-tenancy-v4.md`.

## Current shape (2026-09-12 provisioning-pipeline redesign)

One flat, fully configurable list, `numerosis.tenancy.provisioning.steps`.
Every entry implements `Contracts\Tenancy\ProvisioningStep::handle(TenantProvision $provision): void`
and is dispatched as its own `Bus::chain()` link (`Jobs\RunProvisioningStep`)
by `Actions\Tenancy\ProvisionTenant`. No signature asymmetry, no hardcoded
head/tail, no outer queued job. Everything below this section that predates
2026-09-12 describes what this replaced and is kept for the reasoning, marked
**Historical** where it now conflicts with current code.

- **The provision row is the mutex, the data carrier and the resumability
  record**, `Models\Central\TenantProvision` (renamed from
  `PendingTenantProvision`; primary key `slug`, was `domain`).
  `TenantProvision::claim()` is one conditional `UPDATE`, affected-rows the
  acquire — replaces `ShouldBeUnique`, the `tenant-chain:` cache lock and
  `CreateTenant`'s own lock, all three deleted. No atomic-lock-capable cache
  driver requirement any more.
- **Per-step records, not per-segment.** `RunProvisioningStep::handle()`
  checks `$provision->hasRun($step)` before running it and calls
  `$provision->recordStep()` after — `done`, or `skipped: <reason>` when a
  declared contribution ({@see below}) is absent. This is what makes a step
  not have to be idempotent for retries: a retry resumes from the first
  unrecorded step rather than re-running everything. It is also what makes
  per-step database gating unnecessary — the old segment was all-or-nothing
  specifically because there was nowhere to record partial progress.
- **Contributions are the uniform data-in seam.** `Contracts\Tenancy\ProvisionContribution`
  (a marker on a `spatie/laravel-data` `Data` subclass), collected by
  `ContributesProvisionData::contribute()` from wizard steps and stored on the
  provision row — real columns for a contribution implementing
  `PersistsToProvisionColumns` (`fromProvision()`/`toProvisionColumns()`,
  because something has to query it: `stripe_setup_intent_id`,
  `stripe_subscription_id`, `custom_domain`), the `contributions` JSON blob
  keyed by class name otherwise. A step declares `ConsumesContributions::consumes()`
  to be skipped-and-recorded rather than crash when what it needs was never
  contributed — `LinkTenantSubscription` no longer has its own
  `if (! $data->stripeCustomerId) return;` guard; the runner owns that
  decision uniformly for every step.
- **`ControlsItsOwnRetries`** is the per-step retry seam
  (`tries()`/`backoff()`, both static, read in `RunProvisioningStep`'s
  constructor). No core step declares one — 5 tries / 5s backoff remains the
  default for all of them — the seam exists so a future step that waits on
  something external does not have to fork the runner.
- **One terminal failure handler**, the chain's own `->catch()` in
  `ProvisionTenant::queue()`, shared with `now()` as `recordFailure()`. Captures scalars only — `->catch()`
  closures are wrapped in `SerializableClosure`, which serializes the whole
  `use` scope, so a captured `TenantProvisionData` or model would ride into
  the queue payload for nothing.
- `TenancyServiceProvider::$tenantCreatedJobs` is **gone** —
  `TenantCreated => []`. `CreateTenantDatabase`/`MigrateTenantDatabase` are
  ordinary `ProvisioningStep`s now (adapting stancl's `CreateDatabase`/
  `MigrateDatabase` jobs), configured in the same list as everything else.
  `CreateTenant` no longer needs `withoutEvents()` as a race guard — there is
  no second path left to race.
- **`Tenant::create()` builds no database, anywhere, and nothing reinstates
  it.** There is no listener and no flag: a tenant row without a database is
  a real state, the one between step one and step two. Anything wanting a
  usable tenant calls `ProvisionsTenant` — `queue()` for a request, `now()`
  for a command, a seeder, tinker or a test. Both run the same configured
  list and record the same outcomes; `now()` differs only in running the
  links inline and throwing at the call site.

  Written down because the obvious fix is to put a `TenantCreated` listener
  back, and it was specified in full before being dropped: it makes
  `Tenant::create()` mean two things depending on a flag, and needs a
  stand-aside guard purely to tell which. `withoutEvents()` in `CreateTenant`
  is not the alternative either — the race it would close is not
  check-then-act, since `recordRequest()` commits the provision row before
  `claim()` and before dispatch, and it would blind a host's own `Tenant`
  observer for the whole of provisioning. Core's own `TenantObserver` handles
  only `deleting`, so core would never notice.

- **A tenant's identity is its slug and its name. The owner is a
  contribution.** `OwnerContribution` persists to the `tenant_provisions`
  `global_id` column (checkout looks a reservation up by owner and refuses one
  claimed by somebody else), and both `AddTenantOwner` and
  `PromoteFirstUserToAdmin` declare they consume it, so an ownerless provision
  records them skipped and still finishes.

  Promotion is its own configured step for that reason. It used to be a call
  inside `FinalizeTenantProvisioning`, which would have needed an `if` on a
  contribution — the exact shape `ConsumesContributions` exists to replace, and
  it would have cost `NoPromotableUser` its meaning. The exception still fires
  only when an owner *was* contributed and the row that should exist does not.

  **`TenantProvisionData::from(['global_id' => …])` silently drops it now.**
  Spatie's `from()` ignores keys the constructor has no parameter for, so an
  array-shaped build loses the owner with no error, and the tenant provisions
  successfully with nobody attached. Two tests were building the DTO that way
  and started passing while asserting nothing. Construct it with `new` and a
  contribution list.

- **Swap a slow step, do not swap the mechanism.** `Tests\TestCase` puts
  `CloneTenantSchema` in the configured list where migrate and seed would be,
  so the suite runs the real pipeline against a template copy (~1.9s of
  migrate-and-seed avoided per tenant). `tests/Support/TestTenant` is the
  helper — `provisioned()`, and `withDatabaseOnly()` for a test asserting on
  the state between steps. Static rather than a trait: PHPStan types `$this`
  inside a Pest closure as `Pest\PendingCalls\TestCall`, which puts an
  instance helper out of reach of half the suite.

### `$tenant->run()` leaks tenancy when the callback throws

`vendor/stancl/tenancy/src/Database/Concerns/TenantRun.php:18-33` initializes,
calls `$callback($this)`, then reverts — with **no `try`/`finally`**. A throw
skips the revert, so the process stays initialized against that tenant: its DB
connection, cache prefix, auth guard and Spatie permission registrar all still
pointed at it. In a queue worker the next job then runs in that tenant's
context, which is a cross-tenant read or write.

Reachable today, not theoretical. `Actions/Tenancy/PromoteFirstUserToAdmin`
does `throw_unless($user, NoPromotableUser::class)` *inside* the closure, and
it is a `ProvisioningStep`, so it runs under `Jobs/RunProvisioningStep`
(`ShouldQueue`). `Actions/Tenancy/EnsureTenantUserExists` and
`Models\Central\Tenant::admin()` have the same shape with a lower-probability
throw. `Actions/Tenancy/SeedTenantDatabase` is the only one that handrolls
`initialize()`/`end()` in a `finally` — it is right, and the others are not.

**Wrap it once and never call `run()` directly.** Capture `tenant()`,
initialize, `try { … } finally { restore-or-end }`. The suite cannot catch a
regression here: `tests/TestCase` sets `queue.default = 'sync'`, so no test
ever puts a second job on the same worker.

This was written down before and lost. `SeedTenantDatabase`'s comment cites
`module-marketplace.md` for the guidance, and that file was deleted
2026-09-03 with the module system — which is why three call sites still use
the bare `run()`. Deleting a rule file deletes the reason a workaround exists;
grep for inbound references before removing one.

### The `JobPipeline` seam is not a home for these steps

Recorded because it is the obvious-looking alternative anyone maintaining
this would reach for next, and it was tried and rejected here. Against
`vendor/stancl/jobpipeline/src/JobPipeline.php`:

- `handle()` is one `foreach` inside a single queued job — same
  all-steps-in-one-job shape the old `RunProvisioningSteps` had, so no
  per-step retry.
- **It swallows failures.** A step whose class has a `failed()` method gets
  it called and the loop `break`s with no rethrow, so the queue records
  success on a step that actually failed. `if ($result === false) break;`
  stops just as silently.
- `TenantCreated` is a `created` model event
  (`vendor/stancl/tenancy/src/Database/Models/Tenant.php:56`) — fires exactly
  once, ever. A crash that leaves the tenant row behind never re-fires it, so
  anything hung off it is structurally unresumable.
- The step that creates the tenant cannot itself be in the list, since it is
  what fires the event — the index-0 signature asymmetry this redesign
  removed would simply relocate, not disappear.

Its one useful idea survived anyway: steps receive only what the pipeline
passes rather than a payload copied into every link, which is why the
provision row (not a DTO) is what a step is handed.

### Central-write tracking and `refreshApplication()`

`RefreshDatabase` transacts the default connection only; central rows survive
rollback and are cleaned up by `Testing\CleansUpTenancyDatabases` tracking
which central tables got written to. `refreshApplication()` (called between
tests in some suites) rebuilds the container and loses whatever in-memory
state that tracking depended on, so a test relying on it — without composing
the trait's own hook that re-establishes tracking after a refresh — reads as
an intermittent flake rather than a structural gap. Fixed in `53da8f1`. If a
provisioning test refreshes the application mid-test, re-verify tracking
survives it before trusting a green run.

## Historical: the shape before 2026-09-12

Everything below predates the redesign above. Kept for the reasoning behind
several invariants that still hold in spirit (idempotent domain creation, the
`is_bot` sync trap, the broken `tenants:seed` command), even though the
provisioning-chain mechanics they describe (`RunProvisioningSteps`,
`ShouldBeUnique`, the `tenant-chain:` lock, `$tenantCreatedJobs` driving
database creation, the `[0]`-is-special step convention) are deleted code.

- **Readiness = `tenants.provisioned_at`, never "`Tenant` row exists".**
  Stripe webhook (`WebhookController::handleCustomerSubscriptionCreated`)
  provisions on path w/ no `pending_tenant_provisions` row at all — user
  closed tab on Stripe page, never came back. UI/query treating "row in
  `tenants`" as ready links people to tenant whose DB not created yet.
  `resources/views/pages/tenant/⚡mine.blade.php` splits on this:
  `provisioned_at` null = spinner, not null = visitable. **Still true** — the
  row this describes is now `TenantProvision`, and `⚡mine` also reads
  `step_records`/`currentStepLabel()` for the spinner state, per Phase 7.

- **`tenants` column only exists if `Tenant::getCustomColumns()` names it.**
  Model composes `Stancl\VirtualColumn\VirtualColumn`, default custom-column
  list `['id']` — every other attribute folds into `data` JSON blob on save,
  real column stays NULL. Migration only half of adding column. Bit
  `provisioned_at`, `stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at` —
  all sat in `data` while columns NULL for weeks. Hides well: model decodes
  `data` on read, so `$tenant->provisioned_at` answers correctly; only
  SQL-level access — `whereNotNull('provisioned_at')`, Cashier resolving
  customer by `stripe_id`, any join/index — sees nothing.
  `tests/Feature/Models/Central/TenantColumnsTest.php` guards shape by reading
  raw row; extend when column added. `name` genuinely virtual (no column), so
  belongs in `data`. **Still true**, unaffected by the redesign — `Tenant` and
  `TenantProvision` are different models.

  **Fixed 2026-08-04: `id` (plus `registration_date`, `created_by` — every
  key `CreateTenant` passes) added to `Fillable` list,
  `CreateTenant` now uses plain `Tenant::create()`.** Before this,
  `Tenant::create(['id' => 'acme'])` silently dropped `id` (not in
  `Fillable`), mass assignment skipped it, `config('tenancy.id_generator')`
  (`UUIDGenerator`) assigned uuid instead — no failure, just tenant w/ id
  nobody expected, surfacing far away as 404 from Filament's `{tenant}`
  route binding once subdomain no longer matched tenant key. Grepped `app/`
  for other `Tenant::create()` / `->update([...'id'...])` call sites before
  adding `id` to `Fillable` — none besides this one, so no new
  mass-assignment surface opened. `tests/Support/CloneTenantSchema.php`
  still uses `forceCreate` (unaffected, own call site). Tests hand-rolling
  tenant w/ custom id may now use `Tenant::create(['id' => ...])` directly;
  `Tenant::factory()` was and remains safe either way. **Whenever
  `CreateTenant`'s `create()` call gains a new key, add it to
  `Fillable` too** — unlike `forceCreate`, silently dropped rather than
  failing loud.

  Same trap for `Fillable`: `title`, `slug`, `initials` are read-only
  accessors and were listed fillable, so mass-assigning them would write 3
  derived values into `data`. Never add accessor name to `Fillable`.

  **The mirror image, found 2026-08-31: passing `data` itself is also wrong,
  and silently drops everything inside it.** `Database\Factories\Central\TenantFactory`
  set `'data' => ['name' => $company]`. VirtualColumn folds every *non-custom*
  attribute into the `data` column on save — and `data` is not in
  `getCustomColumns()`, so it was treated as one more virtual attribute rather
  than as the blob. The written column came out
  `{"user_id":…,"tenancy_db_name":…}` with **no `name` key at all**, so every
  tenant the suite ever created had `$tenant->name === null`. Set virtual
  attributes at the top level (`'name' => $company`); never assign `data`
  directly on a `VirtualColumn` model.

  It survived unnoticed because nothing reads a tenant's `name` in a way that
  fails on null: accessors like `title` derive from `id`, and the only hard
  requirement is Filament's own tenant layout
  (`FilamentManager::getTenantName(): string`), which no test rendered until
  `tests/Browser/PathModeTest` did. **A null that only a return-type
  declaration in vendor code rejects is invisible to every test that stops
  short of rendering that vendor code.**

- **Historical.** All provisioning funneled through one queued action,
  `ProvisionTenant`, reached only through `ProvisionsTenant::queue()` — that
  part is still true. What follows describes the pre-redesign entry-point
  fan-in (`ShouldBeUnique` keyed on domain) which the current row-claim
  mechanism replaced.

- **Historical**, describes `CreateTenant`'s `Cache::lock("tenant-provision:{domain}", …)`
  and `LinkSubscriptionToTenant`'s `reconcile-subscription:` lock. Both
  deleted — `TenantProvision::claim()` is the only mutex now.

- `CreateTenant` uses `Tenant::find($domain) ?? Tenant::withoutEvents(...create...)`
  and re-runs `CreateTenantDomain` every time, so run crashed halfway
  finished by next attempt. Works only cuz that action idempotent
  (`firstOrCreate`) — keep that way. **Historical on the rest**: `CreateTenant`
  today is one `ProvisioningStep` among the configured list, keyed on `slug`
  not `domain`, and no longer needs `withoutEvents()` (see current shape,
  above) since there is no `$tenantCreatedJobs` path left to race.

- **Historical: async provisioning chain (2026-08-04).** Describes
  `RunProvisioningSteps`, `$tenantCreatedJobs`-driven database creation, the
  `[0]`-is-special step, `ShouldBeUnique` plus a `tenant-chain:` lock, and
  `FinalizeTenantProvisioning`'s standalone `jobFailed`. All replaced by the
  "Current shape" section above. The three correctness fixes this section
  used to describe in detail (chain-link-must-be-a-job, gate database
  inclusion on `databaseExists()` not "row is new", `ShouldBeUnique` not
  covering the async window) are subsumed: every step is already its own job,
  `CreateTenantDatabase`/`MigrateTenantDatabase` self-gate on
  `databaseExists()` the same way, and the row claim has no
  dispatch-is-fast blind spot because the claim and the dispatch happen in
  the same synchronous call.

- **`FinalizeTenantProvisioning` must run last** — still true, now expressed
  as chain *position*, same as before, just the last entry in
  `numerosis.tenancy.provisioning.steps` rather than a hardcoded splice.
  `$tries = 20` is gone; the chain's single `->catch()` is what prevents a
  silently-exhausted retry from spinning the UI forever, so uniform retries
  (5/5) are safe now — see `ControlsItsOwnRetries` above.

- **Historical: "one pipeline definition… separate on purpose from the
  physical-database one."** The two lists this described
  (`numerosis.tenancy.provisioning.steps` vs `TenancyServiceProvider::$tenantCreatedJobs`)
  are one list now — the reason they had to stay separate
  (`AsAction::run($tenant, $data)` vs `new $job($tenant)`, incompatible
  calling conventions) is resolved by giving every step the same
  `handle(TenantProvision $provision): void` signature and adapting stancl's
  jobs into that shape (`CreateTenantDatabase`, `MigrateTenantDatabase`) via
  a `ProvisioningStep` wrapper rather than invoking `JobPipeline` at all.

- Owner membership written few statements *after* `TenantCreated` fires
  (`forceCreate` triggers event, `AddTenantOwner` runs later in same lock), so
  `PromoteFirstUserToAdmin` (called from `FinalizeTenantProvisioning`) treats
  missing owner as "nobody to notify" not error — tenant still fully
  provisioned. **Still true in spirit**; "same lock" is now "same chain,
  later step" — there is no lock any more, `AddTenantOwner` and
  `FinalizeTenantProvisioning` are just two entries in the same ordered list.

- `pending_tenant_provisions` rows created in `ReserveTenantDomain` *before*
  user sent to Stripe, not on way back (called from
  `StartSubscriptionCheckout`). That reservation stops two users both paying
  for same domain, and is why `TechnicalSetup` validates against that table as
  well as `domains`. Its own domain-format/reserved-word check goes through
  `Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy`, same policy `StartCheckoutRequest`
  validates against — but that policy deliberately does **not** check
  `pending_tenant_provisions`, cuz "already claimed by someone else" rule has
  same-user-retry idempotency semantics (`firstOrCreate` + ownership check)
  living only in `ReserveTenantDomain`. Abandoned `reserved` rows expected
  garbage, swept by `tenancy:prune-stalled-provisions`; `provisioning` rows
  past cutoff are real incident — logged, not deleted. **Still true**, table
  is `tenant_provisions` now and the status enum lost `AwaitingPayment`
  (settlement is `settled_at`, a timestamp, not a status value) — see
  `.claude/plans/glittery-growing-dewdrop.md` Phase 1.

- `TenantCreated` job list lives in `TenancyServiceProvider::$tenantCreatedJobs`
  rather than inline in `events()`, so test bootstrap can replace
  `MigrateDatabase` + `SeedTenantDatabase` w/ template copy. Application code
  must not touch it — see `.ai/rules/testing.md`. **Historical**: the property
  is deleted; `TenantCreated => []`. Test bootstrap now swaps
  `MigrateTenantDatabase`/`SeedTenantDatabase` for `CloneTenantSchema` inside
  the `numerosis.tenancy.provisioning.steps` config list instead
  (`tests/Pest.php`).

- **`SeedTenantDatabase` can now be an Action** — the historical note below
  says it cannot, and it was correct at the time: `JobPipeline` owned its
  calling convention (`new $job(...)` then zero-arg `handle()`), and
  `AsAction`'s contract is incompatible with that. Nothing calls
  `SeedTenantDatabase` through `JobPipeline` any more, so the constraint that
  produced this note no longer applies. It has not been converted; recorded
  so nobody re-derives the old reasoning as a reason to leave it alone.

  Historical text follows, for the mechanism, not the current constraint:
  `$tenantCreatedJobs` is consumed by stancl's
  `Stancl\JobPipeline\JobPipeline::handle()`
  (`vendor/stancl/jobpipeline/src/JobPipeline.php`), which does
  `new $job(...$this->passable)` then `app()->call([$instance, 'handle'])`
  with no parameter array — i.e. it requires the job's tenant to arrive via
  constructor injection and `handle()` to take zero parameters (reading
  `$this->tenant`). `AsAction`'s job path is the opposite contract: `::run()`/
  `::dispatch()` construct the action via `app(static::class)` with **no**
  data at all, and every argument flows into `handle(...)` positionally from
  the call site (confirmed against `vendor/lorisleiva/laravel-actions/src/Decorators/JobDecorator.php`'s
  `getPrependedParameters()`). **Before converting any Job to an Action, grep
  for `new $job(` / `new static(` constructions of it outside its own file —
  a caller doing that owns the calling convention, and the Job can't switch
  conventions out from under it** — this grep-first habit is the durable
  takeaway, independent of `JobPipeline` being gone.

- Lock requires atomic-lock-capable cache driver (Redis, database, file, array
  — not plain `memcached`). Tests run w/ `CACHE_STORE=array` (see
  `phpunit.xml`), which supports it. **Historical**: no lock, no cache-driver
  requirement — `TenantProvision::claim()` is a plain conditional `UPDATE`.

- **Tenant user can't be created before its central counterpart exists.**
  Saving `Tenant\User` fires `SyncedResourceSaved`, and when no central user
  carries that `global_id` yet, stancl's `UpdateSyncedResource` listener
  creates one from `getAttributes()` — *every* attribute, not just the four
  named in `getSyncedAttributeNames()`. Central `users` has no `is_bot`
  column, so tenant user carrying one dies with
  `Unknown column 'is_bot' in 'field list' (Connection: central)`, and
  creating tenant user w/ no tenancy initialized at all dies earlier w/
  `ModelNotSyncMasterException`. Any future tenant-only column same trap:
  `Database\Factories\Tenant\UserFactory` therefore leaves `is_bot` to its DB
  default. Structural fix, if this bites again: override listener so create
  path filtered to columns central table actually has. **Still true**,
  unaffected by the redesign.

- `pending_tenant_provisions.status` is `Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus`
  (`Reserved`/`Provisioning`/`Failed`), cast on model — not old `STATUS_*`
  string constants, which gone. **Superseded**: table is `tenant_provisions`,
  enum is `Reserved`/`Provisioning`/`Completed`/`Failed` (`AwaitingPayment`
  deleted, `Completed` added; settlement moved to the `settled_at` timestamp)
  per Phase 1 of `.claude/plans/glittery-growing-dewdrop.md`.

- **`Stancl\Tenancy\Commands\Seed` ("tenants:seed") does not work — it never
  did, in the installed `^v3.9`, and every tenant this package has ever
  provisioned for real hit this.** `Nvade\Numerosis\Jobs\SeedTenantDatabase`
  used to call `Artisan::call('tenants:seed', ['--tenants' => ...])`, which
  always threw `CommandNotFoundException`. Cause is in the vendor class
  itself: `Commands\Seed extends Illuminate\Database\Console\Seeds\SeedCommand`,
  which already declares `protected $signature = 'db:seed ...'`.
  `Illuminate\Console\Command::__construct()` takes the fluent-signature
  branch whenever `$signature` is set (`if (isset($this->signature)) {
  $this->configureUsingFluentDefinition(); }`), which calls `setName()` from
  that *inherited* signature — so `Commands\Seed`'s own `protected $name =
  'tenants:seed';` is silently overwritten back to `db:seed` before the
  command ever registers. Sibling commands (`Migrate`, `Rollback`,
  `MigrateFresh`) avoid this because they compose
  `Stancl\Tenancy\Concerns\ExtendsLaravelCommand`, which overrides
  `getName()`/`getDefaultName()` directly rather than relying on `$name`;
  `Seed` does not use that trait. Calling `Artisan::call('db:seed', ...)`
  instead (the name it actually registers under, since the console app
  resolves the collision in this package's favour, not Laravel's own
  `SeedCommand`) throws `InvalidOptionException: The "--tenants" option does
  not exist` the moment `Commands\Seed::handle()` calls
  `$this->option('tenants')` — `HasATenantsOption::__construct()` is what
  adds that option (via `specifyParameters()`), but `Commands\Seed` declares
  its *own* `__construct(ConnectionResolverInterface $resolver)`, which
  shadows the trait's constructor, so `specifyParameters()` never runs
  either. The command is broken two independent ways, not one. **Still
  true and unaffected** — `SeedTenantDatabase` is a `ProvisioningStep` now
  instead of a `$tenantCreatedJobs` entry, but its fix (below) did not
  change.

  **Why this shipped invisibly for months of "0 failed" runs**: `Tests\Support\CloneTenantSchema`
  only rebuilds its `tenantphpunittemplate` database when that physical
  database doesn't already exist (see `.ai/rules/testing.md`), and every
  measurement of this suite ran against a MySQL volume where a much earlier
  session had already built it — so `SeedTenantDatabase::handle()` (the one
  path that would have hit this) never actually ran. It surfaced only once
  numerosis got its own fresh compose MySQL
  (`.claude/plans/archive/package-extraction.md`, step 5) with no leftover volume.
  **Any "0 failed" number measured against a reused MySQL volume is
  unverified for whatever code path only runs on a database that doesn't
  exist yet** — template-build, first-migration, first-seed. Prefer a
  dropped-and-recreated `testing` plus a fresh compose volume before
  trusting a suspicious green run, not just for schema drift
  (`testing.md`'s existing warning) but for this class of bug too.

  Fixed by not going through Artisan at all:
  `SeedTenantDatabase::handle()` now does `tenancy()->initialize($this->tenant)`,
  resolves `TenantDatabaseSeeder` from the container with
  `setContainer(app())` (what `SeedCommand::getSeeder()` does internally),
  wraps the call in `Model::unguarded()` (same), and reverts tenancy in a
  `finally` — see "`$tenant->run()` leaks tenancy when the callback throws"
  above; the guidance used to live in `module-marketplace.md`, deleted
  2026-09-03. It holds that
  `$tenant->run()` gives no such guarantee and async/queued code must manage
  its own try/finally.

## Local environment

- Container runs as uid 1000 only cuz `WWWUSER`/`WWWGROUP` set in `.env`.
  Without them Sail's PHP container runs as uid 1337, can't write into repo,
  surfacing as confusing "Permission denied" failures from `artisan make:*`,
  Pest's `.temp` result cache, and Playwright — not as permissions error
  you'd recognize. Two directories also mode `700`, had to relax to `755`.

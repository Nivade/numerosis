---
topic: tenant-provisioning
updated: 2026-07-27
---
# Tenant Provisioning

- **Readiness = `tenants.provisioned_at`, never "`Tenant` row exists".**
  Stripe webhook (`WebhookController::handleCustomerSubscriptionCreated`)
  provisions on path w/ no `pending_tenant_provisions` row at all — user
  closed tab on Stripe page, never came back. UI/query treating "row in
  `tenants`" as ready links people to tenant whose DB not created yet.
  `resources/views/pages/tenant/⚡mine.blade.php` splits on this:
  `provisioned_at` null = spinner, not null = visitable.

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
  belongs in `data`.

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

- All provisioning funnels through one queued action,
  `App\Actions\Tenancy\ProvisionTenant`, reached only through
  `App\Contracts\Tenancy\ProvisionsTenant::queue()` contract — never called
  direct, so consumer provisioning onto separate DB servers/regions can swap
  it. Queues rather than provisions sync because creating DB + running
  migrations/seeders comfortably exceeds Stripe webhook's response budget.
  Checkout-success redirect (`CompleteCheckout`), Stripe webhook, local dev
  shortcut (`StartLocalCheckout` / `LocalCheckoutGateway`) all go through it,
  nothing else. `ShouldBeUnique` keyed on domain, so concurrent dispatches
  from redirect + webhook collapse into one job. Fourth entry point means
  calling `ProvisionsTenant::queue()`, not dispatching `ProvisionTenant` or
  calling `CreateTenant` direct.

- `CreateTenant` (renamed `CreateTenant` → `CreateTenantWithOwner` → back to
  `CreateTenant` on 2026-08-04, once `AddTenantOwner` split back out into its
  own provisioning step — see "Async provisioning chain" below) still wraps
  body in
  `Cache::lock("tenant-provision:{$domain}", 10)->block(5, ...)`, and
  `LinkSubscriptionToTenant` (renamed from `ReconcileTenantSubscription`) in
  `Cache::lock("reconcile-subscription:{$stripeSubscriptionId}", ...)`.
  `ShouldBeUnique` is optimization on top; these locks remain correctness
  guarantee, cover case where unique lock already expired
  (`$jobUniqueFor = 300`). **Don't replace either w/
  catch-the-unique-constraint-violation logic** — original approach, dropped
  cuz it duplicated driver-specific SQLSTATE matching across two files.

- `CreateTenant` uses `Tenant::find($domain) ?? Tenant::withoutEvents(...create...)`
  and re-runs `CreateTenantDomain` every time, so run crashed halfway
  finished by next attempt. Works only cuz that action idempotent
  (`firstOrCreate`) — keep that way. As of 2026-08-04 this action only
  creates the tenant row + domain; it no longer creates the database or
  attaches the owner (see "Async provisioning chain" below) — those moved
  into `ProvisionTenant`'s chain, so `CreateTenant::run()` alone no
  longer guarantees a usable tenant database or an attached owner. The only
  non-test callers are `ProvisionTenant` and `config/billing.php`'s
  `provisioning.steps` default.

- **Async provisioning chain (2026-08-04).** `ProvisionTenant::handle()` runs
  the first configured step synchronously (fast — tenant row + domain only),
  then dispatches the rest as a real `Bus::chain()` on a dedicated
  `provisioning` queue (`docker/8.5/supervisord.conf`'s
  `[program:queue-provisioning]`, `--queue=provisioning`), separate from the
  shared `default,stripe` worker. Replaces the old inline
  `createTenantDatabase()` loop that ran `CreateDatabase`/`MigrateDatabase`/
  `SeedTenantDatabase` synchronously inside the job holding the
  `tenant-provision` lock — that inline approach existed only because
  queuing the database jobs the normal way (via stancl's `TenantCreated`
  `JobPipeline`) deadlocked the single `queue:work` worker against itself;
  a dedicated worker removes the reason for the workaround. Chain order:
  database jobs (if needed) → `RunProvisioningSteps` (the remaining
  `config('billing.provisioning.steps')` entries — `AddTenantOwner` lives in
  that list now, not hardcoded as its own chain link; see "one pipeline
  definition" below) → `LinkTenantSubscription` (only if a Stripe
  subscription id is present) → `FinalizeTenantProvisioning`, always last.
  `AsJob::makeJob()` (bundled in
  `lorisleiva/laravel-actions`) turns any `AsAction` class into a valid chain
  link (`JobDecorator implements ShouldQueue`) without converting it to a
  plain `Illuminate` job — this is unrelated to the
  `JobPipeline`-vs-`AsAction` calling-convention conflict below, since
  nothing in the chain goes through `JobPipeline` or the `TenantCreated`
  event (`CreateTenant` still wraps its `create()` in
  `withoutEvents()` for exactly that reason — letting `TenantCreated`'s own
  pipeline fire too would race a second `CreateDatabase` into
  `TenantDatabaseAlreadyExistsException`). `->onQueue('provisioning')` on
  the chain propagates to every link via `Queueable::dispatchNextJobInChain()`
  — no per-job queue annotation needed.

  Three correctness fixes this chain shape required, easy to get wrong again
  if a chain like this is rebuilt elsewhere:

  1. **Anything used as a chain link must actually be a job.**
     `Illuminate\Bus\Queueable`'s `dispatchNextJobInChain()` calls
     `$next->onConnection(...)`/`onQueue(...)` on every link — a plain class
     invoked via `app()->call()` (which is all the old inline loop needed)
     throws `Call to undefined method ...::onConnection()` the moment it's
     put in a `Bus::chain()` array. `tests/Support/CloneTenantSchema` (test
     bootstrap's stand-in for `MigrateDatabase`+`SeedTenantDatabase`, wired
     via `TenancyServiceProvider::$tenantCreatedJobs` override in
     `tests/Pest.php`) hit exactly this and now implements `ShouldQueue`
     with `Dispatchable, InteractsWithQueue, Queueable, SerializesModels` —
     its constructor/`handle()` shape didn't need to change.
  2. **Gate database-job inclusion on `databaseExists()`, not on "the tenant
     row was newly created".** The old `if ($existing === null)` guard skips
     database creation *forever* once the row exists — a run that died
     after `Tenant::create()` but before the database jobs ran could never
     recover; every retry would fail again the moment `AddTenantOwner`
     tried `$tenant->run()` against a database that was never created.
     `ProvisionTenant::databaseJobs()` instead checks
     `$tenant->database()->manager()->databaseExists($tenant->database()->getName())`
     — the same check `DatabaseManager::ensureTenantCanBeCreated()` performs
     — and includes/excludes the *whole* `$tenantCreatedJobs` segment
     accordingly (all-or-nothing, not per job — per-job gating would re-run
     `CloneTenantSchema`/`SeedTenantDatabase` against an already-populated
     database and break the idempotency test below). Residual, accepted: DB
     created but migrate/seed then fails permanently ⇒ a later retry skips
     database creation and goes straight to a broken `AddTenantOwner`. Same
     gap the old code had; worker `--tries` covers the transient case, a
     hard failure marks the provision `Failed`.
  3. **`ShouldBeUnique` only protects while the job is running — once
     `ProvisionTenant::handle()` does almost nothing itself (just kicks off
     the chain), it returns in milliseconds, and the checkout redirect +
     Stripe webhook race is back.** Before this chain existed,
     `ProvisionTenant` ran the ~2s of database work inline, so its unique
     lock (released on completion) reliably outlived the webhook's dispatch
     attempt and the two collapsed into one job — the whole reason
     `ShouldBeUnique` was believed sufficient. After the chain, the *dispatch*
     of the chain is what takes milliseconds; the actual database work now
     happens later, asynchronously, outside the window `ShouldBeUnique`
     covers. A second dispatch for the same domain (redirect landing while
     the webhook also fires, or vice versa) would see the chain as "not
     running" and start a second one, racing `CreateDatabase` into
     `TenantDatabaseAlreadyExistsException` or `AddTenantOwner` into
     `TenantDatabaseDoesNotExistException` — either way, the chain's
     `->catch()` marks a perfectly healthy provisioning `Failed`. Fixed
     with a second, non-blocking lock scoped to the chain itself —
     `Cache::lock("tenant-chain:{$domain}", 900)->get()` — acquired right
     before `Bus::chain(...)->dispatch()` and skipped (no dispatch at all)
     if not acquired. Released on both outcomes: success, inside
     `MarkTenantProvisioned` (already the sole emitter of the "provisioning
     finished" signal — "no chain in flight" is the same fact); failure,
     inside the chain's `->catch()` closure. Both release via
     `forceRelease()`, so no owner token has to survive job serialization;
     the 900s TTL is the backstop for a worker killed mid-chain. The
     `->catch()` closure captures **scalars only** (`$domain`, `$globalId`)
     — `->catch()` callbacks are wrapped in `SerializableClosure`, which
     serializes the entire `use` scope, so capturing `$data` or `$tenant`
     would drag a spatie `Data` object and an Eloquent model into the queue
     payload for no benefit.

- **`FinalizeTenantProvisioning` (renamed from `MakeFirstUserAdmin`) must stay
  last thing running after tenant/domain/owner creation** — enforced by chain
  *position* now (always the last link `ProvisionTenant` builds), not by a
  comment or by being the last statement inside `CreateTenant`. Net
  timing for default config (no extra steps) unchanged — still fires right
  after owner synced in, before subscription reconciliation. Reads users out
  of tenant DB, so can't run before that DB seeded *and* before owner synced
  into it. Also sole emitter of "provisioning finished" signal
  (`provisioned_at`, pending-row deletion, `TenantProvisioned` broadcast, and
  now the chain-lock release from fix 3 above), so if it silently exhausts
  retries UI spins forever. Hence `$tries = 20` and `failed()` handler
  marking pending row `failed` (`App\Actions\Tenancy\MarkProvisionFailed`).

- **One pipeline definition for business-level provisioning
  (`config('billing.provisioning.steps')`), separate on purpose from the
  physical-database one (`TenancyServiceProvider::$tenantCreatedJobs`).**
  Default list is `[CreateTenant::class, AddTenantOwner::class]`; consumer
  appends idempotent post-creation steps after those, each
  `::run(Tenant $tenant, TenantProvisionData $data): void`, that
  `RunProvisioningSteps` (a chain link) runs in order, before
  `LinkTenantSubscription`/`FinalizeTenantProvisioning`. First entry in that
  list must create/find tenant and return it (default `CreateTenant`);
  invoked differently from the rest (`::run($registration)` synchronously
  inside `ProvisionTenant::handle()`, not as a chain link) precisely cuz
  it's the one step w/ no `Tenant` yet to receive and the one step that must
  run before deciding whether to acquire the chain lock at all. Every step
  re-runs whenever the chain retries, so idempotency not optional.

  `AddTenantOwner` used to be hardcoded as its own `Bus::chain()` link in
  `ProvisionTenant`, separate from this config list — same
  `::run($tenant, $data)` calling convention as every other step, no reason
  it lived apart. Folded in 2026-08-04: `AddTenantOwner::handle()` now takes
  `TenantProvisionData` (was `User&CentralUserModel $user`) and resolves the
  owner itself via `CentralUser::where('global_id',
  $data->registration->global_id)->firstOrFail()`, matching every sibling
  step instead of having `ProvisionTenant` resolve the user and pass it in.
  Two direct test callers (`tests/Feature/ProfileSyncTest.php`) had to switch
  from passing a `CentralUser` to building a `TenantProvisionData`.

  **Do not try to fold `$tenantCreatedJobs` into this same list** — tempting
  since both are "ordered steps that run during provisioning," but they are
  genuinely incompatible: entries here are `AsAction` classes invoked
  `$step::run($tenant, $data)`, while `$tenantCreatedJobs` entries are raw
  stancl/queue jobs constructed `new $job($tenant)` (see the
  `JobPipeline`-vs-`AsAction` calling-convention bullet below). Mixing both
  conventions into one array would need runtime type detection to know which
  construction path to use per entry, and `$tenantCreatedJobs` also has to
  stay independently overridable — `tests/Pest.php` swaps it for
  `[CreateDatabase, CloneTenantSchema]` to avoid paying real migrate/seed
  cost per test, and that override mechanism only works because it's a
  narrowly-scoped list of one shape.

- Owner membership written few statements *after* `TenantCreated` fires
  (`forceCreate` triggers event, `AddTenantOwner` runs later in same lock), so
  `PromoteFirstUserToAdmin` (called from `FinalizeTenantProvisioning`) treats
  missing owner as "nobody to notify" not error — tenant still fully
  provisioned.

- `pending_tenant_provisions` rows created in `ReserveTenantDomain` *before*
  user sent to Stripe, not on way back (called from
  `StartSubscriptionCheckout`). That reservation stops two users both paying
  for same domain, and is why `TechnicalSetup` validates against that table as
  well as `domains`. Its own domain-format/reserved-word check goes through
  `App\Contracts\Tenancy\TenantDomainPolicy`, same policy `StartCheckoutRequest`
  validates against — but that policy deliberately does **not** check
  `pending_tenant_provisions`, cuz "already claimed by someone else" rule has
  same-user-retry idempotency semantics (`firstOrCreate` + ownership check)
  living only in `ReserveTenantDomain`. Abandoned `reserved` rows expected
  garbage, swept by `tenancy:prune-stalled-provisions`; `provisioning` rows
  past cutoff are real incident — logged, not deleted.

- `TenantCreated` job list lives in `TenancyServiceProvider::$tenantCreatedJobs`
  rather than inline in `events()`, so test bootstrap can replace
  `MigrateDatabase` + `SeedTenantDatabase` w/ template copy. Application code
  must not touch it — see `.claude/rules/testing.md`.

- **`SeedTenantDatabase` (and anything else living in `$tenantCreatedJobs`)
  can't be ported to `lorisleiva/laravel-actions`' `AsAction`+`ShouldQueue`
  job pattern — its caller's contract is incompatible, not just a style
  mismatch.** `$tenantCreatedJobs` is consumed by stancl's
  `Stancl\JobPipeline\JobPipeline::handle()`
  (`vendor/stancl/jobpipeline/src/JobPipeline.php`), which does
  `new $job(...$this->passable)` then `app()->call([$instance, 'handle'])`
  with no parameter array — i.e. it requires the job's tenant to arrive via
  constructor injection and `handle()` to take zero parameters (reading
  `$this->tenant`). `AsAction`'s job path is the opposite contract: `::run()`/
  `::dispatch()` construct the action via `app(static::class)` with **no**
  data at all, and every argument flows into `handle(...)` positionally from
  the call site (confirmed against `vendor/lorisleiva/laravel-actions/src/Decorators/JobDecorator.php`'s
  `getPrependedParameters()`). A class shaped for one contract fails the
  other: give `SeedTenantDatabase` an `AsAction`-style parameterless-construction
  and parameterized-`handle()` and `JobPipeline`'s `app()->call()` has no way
  to supply the tenant (its constructor-typed `TenantWithDatabase` interface
  is unbound in the container, so `app(static::class)` throws immediately —
  same failure whether triggered by the real pipeline or by calling
  `SeedTenantDatabase::run()` directly). Converted `FinalizeTenantProvisioning`,
  `MigrateModules`, `RollbackModules`, `SyncTenantToStripe` to Actions
  instead (none of them go through `JobPipeline` — each is reached via a
  plain `dispatch()`/`dispatchSync()` call, which is `AsAction`-compatible)
  and left `SeedTenantDatabase` as a plain `Illuminate` Job. **Before
  converting any Job to an Action, grep for `new $job(` / `new static(`
  constructions of it outside its own file — a caller doing that owns the
  calling convention, and the Job can't switch conventions out from under
  it.**

- Lock requires atomic-lock-capable cache driver (Redis, database, file, array
  — not plain `memcached`). Tests run w/ `CACHE_STORE=array` (see
  `phpunit.xml`), which supports it.

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
  path filtered to columns central table actually has.

- `pending_tenant_provisions.status` is `App\Enums\TenantProvisionStatus`
  (`Reserved`/`Provisioning`/`Failed`), cast on model — not old `STATUS_*`
  string constants, which gone.

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
  either. The command is broken two independent ways, not one.

  **Why this shipped invisibly for months of "0 failed" runs**: `Tests\Support\CloneTenantSchema`
  only rebuilds its `tenantphpunittemplate` database when that physical
  database doesn't already exist (see `.claude/rules/testing.md`), and every
  measurement of this suite ran against a MySQL volume where a much earlier
  session had already built it — so `SeedTenantDatabase::handle()` (the one
  path that would have hit this) never actually ran. It surfaced only once
  numerosis got its own fresh compose MySQL
  (`.claude/plans/package-extraction.md`, step 5) with no leftover volume.
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
  `finally` — matching `.claude/rules/module-marketplace.md`'s guidance that
  `$tenant->run()` gives no such guarantee and async/queued code must manage
  its own try/finally.

## Local environment

- Container runs as uid 1000 only cuz `WWWUSER`/`WWWGROUP` set in `.env`.
  Without them Sail's PHP container runs as uid 1337, can't write into repo,
  surfacing as confusing "Permission denied" failures from `artisan make:*`,
  Pest's `.temp` result cache, and Playwright — not as permissions error
  you'd recognize. Two directories also mode `700`, had to relax to `755`.
# Async tenant DB provisioning on a dedicated queue worker

> Supersedes the earlier `peaceful-noodling-eich` draft (deleted). Same goal,
> three correctness fixes and a firmer chain shape.

## Context

`CreateTenantWithOwner::handle()` creates the tenant row and then — inside the
same `Cache::lock("tenant-provision:{$domain}")` block — creates, migrates and
seeds the physical tenant database **inline** (`createTenantDatabase()`, a
`foreach` over `TenancyServiceProvider::$tenantCreatedJobs` calling
`app()->call([$job, 'handle'])`), then attaches the owner. `Tenant::create()` is
wrapped in `withoutEvents()` so stancl's queued `TenantCreated` `JobPipeline`
never fires.

That was deliberate: the pipeline is `->shouldBeQueued(true)` and there is one
worker (`docker/8.5/supervisord.conf`, `queue:work --queue=default,stripe`), so
queuing it from inside a job that then waits on it deadlocks — the worker cannot
drain the pipeline until the job holding it finishes.

Cost: ~1.9s of `CREATE DATABASE` + migrate + seed runs inline on the shared
`default,stripe` worker, blocking Stripe webhook work behind it.

Goal: make tenant-DB creation **genuinely asynchronous** — real queued jobs on a
new worker dedicated to provisioning — not merely move the same inline work onto
a different queue.

## Design

Keep `withoutEvents()`. Replace the inline loop with a real `Bus::chain()` on a
new `provisioning` queue, consumed by a new supervisor program. Every link is an
ordinary queued job, so ordering that is convention today becomes structural.

```php
// ProvisionTenant::handle()
$tenant = $firstStep::run($data->registration);   // fast: tenant row + domain only

$lock = Cache::lock("tenant-chain:{$domain}", 900);

if (! $lock->get()) {
    return;   // another chain is already in flight for this domain
}

Bus::chain([
    ...$this->databaseJobs($tenant),                    // only if the DB is absent
    AddTenantOwner::makeJob($tenant, $user),
    RunProvisioningSteps::makeJob($tenant, $data),      // new
    ...($data->stripeSubscriptionId ? [LinkTenantSubscription::makeJob($tenant, $data)] : []),
    FinalizeTenantProvisioning::makeJob($tenant),       // last, structurally
])
    ->onQueue('provisioning')
    ->catch(function (Throwable $e) use ($domain, $globalId): void {
        Cache::lock("tenant-chain:{$domain}")->forceRelease();
        MarkProvisionFailed::run($domain, $e->getMessage());
        event(new TenantProvisioningFailed($domain, $globalId));
    })
    ->dispatch();
```

Verified against vendor source:

- `AsJob::makeJob()` (bundled in `AsAction`) returns a `JobDecorator` that
  `implements ShouldQueue` and `use Queueable` — valid chain links, no need to
  convert any Action into a plain Illuminate job. The `JobPipeline`-vs-`AsAction`
  calling-convention conflict in `.claude/rules/tenant-provisioning.md` does not
  apply: nothing here goes through `JobPipeline` or the `TenantCreated` event.
- `PendingChain::dispatch()` sets `chainQueue`/`chainCatchCallbacks` on the first
  job, and `Queueable::dispatchNextJobInChain()` propagates both to every later
  link (`$next->onQueue($next->queue ?: $this->chainQueue)`), so one
  `->onQueue('provisioning')` covers the whole chain.
- `CreateDatabase`, `MigrateDatabase`, `SeedTenantDatabase` are already
  `ShouldQueue` + `Queueable` — unchanged.

Ordering note: today `FinalizeTenantProvisioning::dispatch()` is queued and the
Stripe link runs inline right after, so with one busy worker the link always
lands first. The chain preserves that by placing `LinkTenantSubscription` before
`FinalizeTenantProvisioning`, which stays last — now enforced by position, not by
a comment in `config/billing.php`.

`TenancyServiceProvider::$tenantCreatedJobs` stays the source of the DB segment
(not a hardcoded triple), so `tests/Pest.php`'s `[CreateDatabase,
CloneTenantSchema]` override keeps working — see the required change to
`CloneTenantSchema` below.

### Fix 1 — `CloneTenantSchema` must become a real queued job

`tests/Support/CloneTenantSchema` is a plain class: no `ShouldQueue`, no
`Queueable`, no `Dispatchable`. It works today only because
`createTenantDatabase()` invokes it via `app()->call()`. As a chain link,
`dispatchNextJobInChain()` calls `$next->onConnection(...)` on it and the test
suite dies with `Call to undefined method Tests\Support\CloneTenantSchema::onConnection()`.

Fix: `class CloneTenantSchema implements ShouldQueue` with
`use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;`. Its
constructor (`TenantWithDatabase $tenant`) and `handle(): void` already match
`CreateDatabase`'s shape, so nothing else changes.

The predecessor plan asserted this override "keeps working unchanged". It does
not.

### Fix 2 — gate the DB segment on `databaseExists()`, not on "row was new"

Today the guard is `if ($existing === null)`. On any retry the tenant row exists,
so DB creation is skipped **forever** — a run that died after `Tenant::create()`
but before `CreateDatabase` can never recover, and every retry fails again in
`AddTenantOwner`'s `$tenant->run()`.

Gate instead on the same check `CreateDatabase` itself performs via
`DatabaseManager::ensureTenantCanBeCreated()`:

```php
$tenant->database()->manager()->databaseExists($tenant->database()->getName())
```

All-or-nothing on the whole `$tenantCreatedJobs` segment, not per job. Per-job
gating would re-run `CloneTenantSchema` against an already-populated database and
break `ProvisionTenantTest::test_it_is_idempotent_when_run_twice_for_the_same_domain`.

Residual, unchanged from today and accepted: DB created but migrate/seed failed
permanently ⇒ a later retry skips migration. Worker `--tries` covers the
transient case; a hard failure marks the provision `Failed`, which is the current
behaviour too.

### Fix 3 — guard against two concurrent chains for one domain

`ShouldBeUnique` releases its lock when the job **completes**. `ProvisionTenant`
currently runs ~2s (it does the DB work), so the redirect and the Stripe webhook
collapse into one. After this change it completes in milliseconds, so the webhook
dispatch passes uniqueness while chain #1 is still creating the database. Chain #2
then sees the DB as absent-or-present mid-creation and either races
`CreateDatabase` into `TenantDatabaseAlreadyExistsException` or hits
`TenantDatabaseDoesNotExistException` in `AddTenantOwner` — either way the catch
marks a perfectly healthy provisioning `Failed`.

Guard: non-blocking `Cache::lock("tenant-chain:{$domain}", 900)->get()` before
dispatch; skip dispatch when not acquired. Released on both paths:

- success — inside `MarkTenantProvisioned`, which is already the sole emitter of
  the "provisioning finished" signal (stamps `provisioned_at`, deletes the
  pending row); "no chain in flight" is the same fact.
- failure — inside the chain `catch` closure.

`forceRelease()` on both, so no owner token has to be threaded through job
serialization. 900s TTL is the backstop for a worker killed mid-chain.

Requires an atomic-lock-capable cache driver — already required by
`CreateTenantWithOwner`'s existing lock; `CACHE_STORE=array` in `phpunit.xml`
supports it.

### Catch closure

Capture **scalars only** (`$domain`, `$globalId`). `->catch()` callbacks are
wrapped in `SerializableClosure`, which serializes the entire `use` scope —
capturing `$data` or `$tenant` drags a spatie `Data` object and an Eloquent model
into the payload for no benefit.

`ProvisionTenant::jobFailed()` stays as-is for the synchronous phase (lock, row
creation, resolving the owning `CentralUser`). `SeedTenantDatabase::failed()`
keeps its own `MarkProvisionFailed`/event call — a harmless double-fire on top of
the chain catch for that one link; both calls are idempotent.

## Files

- **`app/Actions/Tenancy/CreateTenantWithOwner.php`** — delete
  `createTenantDatabase()` and its docblock, delete the `CentralUser` lookup and
  `AddTenantOwner::run()`. `handle()` becomes: lock, find-or-create row
  (`Tenant::withoutEvents(fn () => Tenant::create([...]))`, unchanged),
  `CreateTenantDomain::run()`, return. Replace the removed docblock with a short
  one stating why `withoutEvents()` **stays**: the chain owns DB creation, so
  letting `TenantCreated` fire as well would race a second `CreateDatabase` into
  `TenantDatabaseAlreadyExistsException`.

- **`app/Actions/Tenancy/ProvisionTenant.php`** — add
  `public string $jobQueue = 'provisioning';`. `handle()` runs the first
  configured step to get `$tenant`, then a private
  `dispatchProvisioningChain(Tenant $tenant, TenantProvisionData $data): void`
  doing the lock, `databaseJobs()` gate and `Bus::chain()` above. The
  `runProvisioningSteps()` loop over later steps, the
  `FinalizeTenantProvisioning::dispatch()` call and the Stripe block all leave
  this class.

- **`app/Actions/Tenancy/RunProvisioningSteps.php`** (new) —
  `handle(Tenant $tenant, TenantProvisionData $data): void`: re-read
  `config('billing.provisioning.steps')`, `array_shift` the first entry (already
  run), `$stepClass::run($tenant, $data)` for the rest. Same code that leaves
  `ProvisionTenant`.

- **`app/Actions/Tenancy/LinkTenantSubscription.php`** (new) —
  `handle(Tenant $tenant, TenantProvisionData $data): void`:
  `Cashier::stripe()->subscriptions->retrieve(...)` +
  `LinkSubscriptionToTenant::run($data, StripeSubscriptionData::fromStripe($sub), $tenant)`.
  Guard-return when either Stripe id is null, so the link is safe even if
  conditionally appended and safe on retry.

- **`app/Actions/Tenancy/MarkTenantProvisioned.php`** — release the chain lock
  (`Cache::lock("tenant-chain:{$tenant->getTenantKey()}")->forceRelease()`)
  alongside the existing `provisioned_at` stamp and pending-row delete.

- **`app/Actions/Tenancy/AddTenantOwner.php`** — no logic change; its comment
  claims the tenant DB is guaranteed to exist because "migrate/seed listeners
  already ran earlier in this same lock". Now it is guaranteed by chain position.
  Update the comment.

- **`tests/Support/CloneTenantSchema.php`** — Fix 1 above.

- **`docker/8.5/supervisord.conf`** (the only image built — `docker-compose.yml`
  uses `context: './docker/8.5'`) — new `[program:queue-provisioning]`,
  `command=php artisan queue:work --queue=provisioning --tries=3 --backoff=10`,
  same `stopwaitsecs=3600` / logging shape as `[program:queue]`. Existing
  `[program:queue]` keeps `--queue=default,stripe`.

- **`.claude/rules/tenant-provisioning.md`** — rewrite the bullets describing the
  inline mechanism: the `createTenantDatabase()` / single-worker-deadlock
  explanation, "`FinalizeTenantProvisioning` must stay last thing running" (now
  chain position), and the local-environment note. Add the three fixes above as
  their own bullets — each is exactly the kind of trap that file exists for,
  particularly `wasRecentlyCreated`-vs-`databaseExists()` and the
  `ShouldBeUnique`-lock-releases-on-completion race.

- **`config/billing.php`** — the `provisioning.steps` comment says steps run
  "inside the existing per-domain lock". No longer true; they run as a chain link
  on the `provisioning` queue. Comment only.

## Contract change to flag

`CreateTenantWithOwner::run()` no longer guarantees a usable tenant database or
an attached owner — only going through `ProvisionTenant` does. Grepped: the only
non-test callers are `ProvisionTenant` and `config/billing.php`;
`WebhookController` already goes through the `ProvisionsTenant::queue()`
contract.

## Test fallout

`tests/Feature/Actions/Tenancy/CreateTenantWithOwnerTest.php` has two tests whose
premise dies:

- `test_it_creates_the_tenant_database_synchronously_without_relying_on_the_queue`
  — `Queue::fake()` + `$tenant->run(fn () => Schema::hasTable('users'))` +
  `Queue::assertNotPushed(CreateDatabase::class)`. Rewrite to assert
  `CreateTenantWithOwner::run()` alone creates the row + domain and does **not**
  touch the tenant database; drop the `Schema` and `assertNotPushed` assertions.
- `test_it_creates_a_tenant_with_domain_and_subscription` — asserts
  `$user->tenants->contains($tenant)` immediately after
  `CreateTenantWithOwner::run()`. Change the Act step to
  `ProvisionTenant::run(new TenantProvisionData(registration: $registration, centralUserId: (string) $user->id))`,
  keep the rest.

`QUEUE_CONNECTION=sync` (`phpunit.xml:33`) means `Bus::chain()` executes every
link inline during `ProvisionTenant::run()`, so "DB usable" / "owner attached"
assertions keep working once pointed at the right entry point. `ProvisionTenantTest`,
`TenantProvisioningSignalTest` and `InterviewShowcaseTest` already go through
`ProvisionTenant` and should pass unchanged.

New coverage to add in `ProvisionTenantTest`:

1. after `ProvisionTenant::run()`, the tenant DB is migrated + seeded and usable
   (the assertion moved out of `CreateTenantWithOwnerTest`);
2. with `Bus::fake()`, a chain is dispatched on the `provisioning` queue and its
   last link is `FinalizeTenantProvisioning`;
3. holding `Cache::lock("tenant-chain:{$domain}")` before calling
   `ProvisionTenant::run()` results in **no** chain dispatch (Fix 3);
4. a tenant row that exists with no physical database still gets the DB segment
   dispatched (Fix 2) — the retry case the old `$existing === null` guard
   permanently skipped.

## Verification

1. `vendor/bin/sail bin pint --dirty --format agent`
2. `vendor/bin/sail exec -T laravel.test bash -lc "vendor/bin/phpstan analyse"` —
   compare error count against `git stash`, per `.claude/rules/static-analysis.md`
   (the run is red on `master`).
3. `vendor/bin/sail artisan test --compact --filter="ProvisionTenantTest|CreateTenantWithOwnerTest|TenantProvisioningSignalTest|InterviewShowcaseTest|FinalizeTenantProvisioningTest|StartLocalCheckoutTest"`
   — one run, touched files only.
4. Container check: `vendor/bin/sail build` then `vendor/bin/sail up -d`, and
   `vendor/bin/sail exec laravel.test supervisorctl status` shows both
   `queue` and `queue-provisioning` RUNNING.
5. End-to-end locally (`QUEUE_CONNECTION=redis`): run `StartLocalCheckout`, watch
   `provisioning` drain the chain (`vendor/bin/sail artisan queue:monitor provisioning`
   or Telescope) while `default,stripe` stays free, and confirm the tenant reaches
   `provisioned_at` — the UI spinner in
   `resources/views/pages/tenant/⚡mine.blade.php` flipping is the honest signal.

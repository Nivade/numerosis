# Actions/Jobs cleanup — from audit findings

**Status: ✅ Executed.** Steps 1–3 done as planned. Step 4: `SyncTenantToStripe`,
`FinalizeTenantProvisioning`, `MigrateModules`, `RollbackModules` ported to
Actions; `SeedTenantDatabase` deliberately left as a plain Job — see the
correction inline below and `.claude/rules/tenant-provisioning.md`. Verified:
targeted tests pass, PHPStan diff shows zero new `app/` errors, Pint clean.

## Context

`.claude/plans/actions.md` audit of `app/Actions/*` vs `app/Jobs/*` vs
Laravel Actions (`lorisleiva/laravel-actions`) usage surfaced nine findings.
Five (#2–#5) are "no gap, already correct" and need no work. Four are real
defects: dead `ShouldQueue` interface, inconsistent Action-invocation style,
two container-singleton traps matching an already-documented anti-pattern
(`.claude/rules/auth-guards.md`), and five plain `Illuminate` Jobs
hand-rolling what `AsAction` + `ShouldQueue` gives for free — causing two
parallel test idioms for the same "queued business op" concept. This plan
turns those four into ordered, verifiable work.

Confirmed against source during exploration:
- `LinkSubscriptionToTenant` (`app/Actions/Billing/Subscriptions/LinkSubscriptionToTenant.php`) implements `ShouldQueue`, only ever called via `LinkSubscriptionToTenant::run()` from `ProvisionTenant::handle()`.
- `ProvisionTenant::runProvisioningSteps()` (`app/Actions/Tenancy/ProvisionTenant.php:75,78`) mixes `resolve($firstStep)->handle(...)` and `resolve($stepClass)($tenant, $data)` for the same "call an Action" operation.
- `AppServiceProvider::register()` singleton-binds 6 stateless Actions (`app/Providers/AppServiceProvider.php:46-51`).
- `BillingServiceProvider::register()` loops `config('billing.implementations')` and singleton-binds all 11 entries including `ProvisionsTenant::class => ProvisionTenant::class`, a `ShouldBeUnique`+`ShouldQueue` Action (`app/Providers/BillingServiceProvider.php:24-28`, `config/billing.php:187`).
- 5 plain Jobs duplicate `AsAction`'s job trait: `app/Jobs/FinalizeTenantProvisioning.php`, `SeedTenantDatabase.php`, `MigrateModules.php`, `RollbackModules.php`, `SyncTenantToStripe.php`. Verified `lorisleiva/laravel-actions`'s `AsJob` trait (`vendor/lorisleiva/laravel-actions/src/Concerns/AsJob.php`, `Decorators/JobDecorator.php`) supports every feature these five use: `jobTries`, `jobBackoff`, `jobUniqueFor`/`getJobUniqueId`, `jobFailed(Throwable $e)`, `jobDeleteWhenMissingModels`, and `configureJob(JobDecorator $job)` for anything not covered by a named property (e.g. `$job->afterCommit()`, `$job->onQueue('stripe')`).

## Order of work

Do in this order — each step is independent and separately testable, but
later steps touch files earlier steps also touch (`ProvisionTenant`,
`config/billing.php`), so doing singleton fixes before the Job→Action port
avoids re-touching the same lines twice.

### 1. Drop dead `ShouldQueue` from `LinkSubscriptionToTenant`

File: `app/Actions/Billing/Subscriptions/LinkSubscriptionToTenant.php`

- Remove `implements ShouldQueue` and the now-unused `use Illuminate\Contracts\Queue\ShouldQueue;` import.
- Keep everything else as-is — the `Cache::lock(...)->block(...)` stays; it's the real correctness mechanism since nothing ever dispatches this action async (confirmed: grep found zero `LinkSubscriptionToTenant::dispatch(`).
- No call-site changes — `ProvisionTenant::handle()` already calls `::run()`.

### 2. Uniform Action-invocation style in `ProvisionTenant::runProvisioningSteps()`

File: `app/Actions/Tenancy/ProvisionTenant.php:67-82`

Change:
```php
$tenant = resolve($firstStep)->handle($data->registration);
...
resolve($stepClass)($tenant, $data);
```
to:
```php
$tenant = $firstStep::run($data->registration);
...
$stepClass::run($tenant, $data);
```
Both are already equivalent to `::run()` under the hood (`resolve($x)->handle(...)` and the `__invoke` forwarding both resolve to the same call) — this is a readability-only change, no behavior change. `$firstStep`/`$stepClass` remain `class-string` from `config('billing.provisioning.steps')`, so `$firstStep::run(...)` is valid PHP (static call via variable holding a class-string).

### 3. `bind()` instead of `singleton()` for stateless Actions

**`app/Providers/AppServiceProvider.php:46-51`** — change all six:
```php
$this->app->singleton(ResolvesLoginCandidate::class, FindLoginCandidate::class);
```
to
```php
$this->app->bind(ResolvesLoginCandidate::class, FindLoginCandidate::class);
```
(repeat for `AuthenticatesLoginCandidate`, `ResolvesPostLoginRedirectUrl`, `CreatesRegisteredUser`, `SendsEmailVerificationNotification`, `CreatesInvitedUser`).

**`app/Providers/BillingServiceProvider.php:24-28`** — the loop binds all 11
`config('billing.implementations')` entries singleton with no per-entry
control. Carve out `ProvisionsTenant::class` (and treat any future
Action-backed contract the same way) rather than changing the loop's default
for all 11 — some of the 10 others (repositories/resolvers) may have a real
reason to be shared, unlike `DomainTenantResolver`'s documented case
(`TenancyServiceProvider.php:185`), nothing here has been audited for them.
Minimal, correct fix:
```php
foreach ($implementations as $contract => $concrete) {
    $this->app->bind($contract, $concrete);
}
```
Actually — simplest and matches every other Action in the app (resolved
fresh per call, no binding at all): switch this loop to `bind()` outright.
Confirm during implementation whether any of the 11 concretes hold
constructor-injected state that's expensive to rebuild per-request (none of
the reviewed ones do); if one does, carve it out with an inline comment
explaining why, mirroring the `DomainTenantResolver` precedent. Default to
`bind()` for the whole loop unless that check finds a reason not to.

### 4. Port 5 plain Jobs to Actions

Mechanical, one file at a time. Pattern (using `SyncTenantToStripe` as the
smallest example):

**Before** (`app/Jobs/SyncTenantToStripe.php`):
```php
class SyncTenantToStripe implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public array $backoff = [10, 30];
    public function __construct(public Tenant $tenant) { $this->onQueue('stripe'); }
    public function handle(): void { ... }
}
```

**After** (moved to `app/Actions/Billing/SyncTenantToStripe.php`, namespace
adjusted to match its new location per existing `app/Actions/*` layout):
```php
class SyncTenantToStripe implements ShouldQueue
{
    use AsAction;
    public int $jobTries = 3;
    /** @var array<int, int> */
    public array $jobBackoff = [10, 30];
    public function __construct(public Tenant $tenant) {}
    public function configureJob($job): void { $job->onQueue('stripe'); }
    public function handle(): void { ... }
}
```

Per-file mapping, all confirmed against `AsJob`'s supported surface:

| Job | New location | Notable mapping |
|---|---|---|
| `SyncTenantToStripe` | `app/Actions/Billing/SyncTenantToStripe.php` | `$tries`→`jobTries`, `$backoff`→`jobBackoff`, `onQueue('stripe')` in constructor → `configureJob($job){ $job->onQueue('stripe'); }` |
| `FinalizeTenantProvisioning` | `app/Actions/Tenancy/FinalizeTenantProvisioning.php` | `$tries`→`jobTries`, `$backoff`→`jobBackoff`, `deleteWhenMissingModels`→`jobDeleteWhenMissingModels`, `$this->afterCommit=true` in constructor → `configureJob($job){ $job->afterCommit(); }`, `failed(Throwable $e)`→`jobFailed(Throwable $e)`, drop `TagsSentryScopeWithTenant`'s trait-based use only if it still applies cleanly (check the trait's own assumptions — it's designed for `failed()`-handler jobs, confirm it still works named `jobFailed`) |
| `MigrateModules` | `app/Actions/Modules/MigrateModules.php` | no queue-config properties to map, straight `handle()` port |
| `RollbackModules` | `app/Actions/Modules/RollbackModules.php` | straight `handle()` port |

**`SeedTenantDatabase` excluded — not converted.** Confirmed during implementation:
it is invoked exclusively through stancl's `JobPipeline` (`app/Providers/TenancyServiceProvider.php`'s
`$tenantCreatedJobs`), whose `handle()` does `new $job(...$this->passable)` then
`app()->call([$instance, 'handle'])` with no parameter array — i.e. it requires
constructor-injected data and a zero-parameter `handle()`. `AsAction`'s job path
(`::dispatch()`/`::run()`) is the opposite contract: the action is constructed via
`app(static::class)` with *no* data, and all arguments flow into `handle(...)`
positionally from the dispatch call. `SeedTenantDatabase`'s constructor takes
`TenantWithDatabase $tenant` (an unbound interface) — under the `AsAction` contract
that would make `app(static::class)` throw immediately on construction, since Laravel
can't resolve an unbound interface with no default. The Job/Action rewrite for the
other four is only safe because none of them are called through `JobPipeline`. Left
as a plain `Illuminate` Job, unchanged. See `.claude/rules/module-marketplace.md`
(or wherever this gets recorded) for the general rule.

For each: `use AsAction;` replaces `Dispatchable, InteractsWithQueue,
Queueable, SerializesModels`; keep `implements ShouldQueue`; keep
`handle()` body unchanged (no behavior change — this is a structural port).

**Call sites** — `dispatch(new X(...))` becomes `X::dispatch(...)`:
- `app/Actions/Tenancy/ProvisionTenant.php:45` — `dispatch(new FinalizeTenantProvisioning($tenant))` → `FinalizeTenantProvisioning::dispatch($tenant)`
- `app/Listeners/Modules/QueueModuleMigration.php:14` — `dispatch(new MigrateModules(...))` → `MigrateModules::dispatch(...)`
- `app/Listeners/Billing/SyncTenantToStripeOnSave.php:25` — `dispatch(new SyncTenantToStripe($tenant))` → `SyncTenantToStripe::dispatch($tenant)`
- `RollbackModules` has no production dispatcher today (only test call sites) — leave that way, not in scope to wire one up.

**Other references to update** (grepped, not exhaustive-listed per file —
same mechanical class-reference swap each time, `App\Jobs\X` → new
namespace):
- `app/Providers/TenancyServiceProvider.php:8,81` and `tests/Pest.php:17` — both reference `App\Jobs\SeedTenantDatabase` in the `$tenantCreatedJobs` list. **Unchanged** — `SeedTenantDatabase` stays a plain Job (see above), so this reference is untouched.
- `tests/Support/CloneTenantSchema.php:130` — `app()->call([new SeedTenantDatabase($tenant), 'handle'])`. **Unchanged** for the same reason.
- Test files under `tests/Feature/Jobs/*Test.php` (`FinalizeTenantProvisioningTest`, `MigrateModulesTest`, `RollbackModulesTest`) — consider moving alongside the new `app/Actions/*` location (e.g. `tests/Feature/Actions/Tenancy/...`) to match existing test-directory convention, but this is optional polish, not required for correctness. `SeedTenantDatabaseTest` (if it exists) is unaffected — that class didn't move.
- `tests/Feature/SyncTenantToStripeTest.php` — currently uses `Queue::fake()` + `assertPushed(SyncTenantToStripe::class)`; after the port this can (optionally) switch to `SyncTenantToStripe::fake()` / `assertPushed()` idiom used elsewhere, unifying the two parallel test idioms the audit called out as the actual cost of not converting. Not required — `Queue::fake()`+`assertPushed()` still works against an Action-as-job.
- Other test files that merely reference these classes for setup (`NotesModuleTest`, `TasksModuleTest`, `BrandingModuleTest`, `AnnouncementsModuleTest`, `ApplyBrandingTest`, `QueueModuleMigrationTest`, `PurchaseModuleTest`, `CreateTenantWithOwnerTest`, `InterviewShowcaseTest`, `TenantProvisioningSignalTest`) only need their `use App\Jobs\X` import path updated to `use App\Actions\...\X` — no logic changes.

## Verification

- After each numbered step, run the narrowest relevant test file(s) with
  `vendor/bin/sail artisan test --compact --filter=<Name>` before moving to
  the next step (per `.claude/rules/testing.md` — don't run full suite
  mid-change).
- Step 1: `vendor/bin/sail artisan test --compact` filtered to tenant
  provisioning + subscription linking specs (`LinkSubscriptionToTenantTest`
  if one exists, else whatever covers `ProvisionTenant`).
- Step 2: same `ProvisionTenant`-covering tests — behavior must be identical,
  this is a no-op change functionally.
- Step 3: run auth tests (login/registration/invitation flows) since those
  six Actions sit on the login/registration path, plus anything covering
  tenant provisioning (`ProvisionsTenant` contract).
- Step 4: run each ported class's own test file after that specific port,
  not all five at once — isolates which port broke something if one does.
  Finish with `vendor/bin/sail bin pint --dirty --format agent` (PHP files
  touched) and `vendor/bin/sail exec -T laravel.test bash -lc "vendor/bin/phpstan analyse"`
  per `.claude/rules/static-analysis.md` — diff the error count against
  `git stash` baseline rather than assuming a red run is this change's
  fault (master is currently red per that rule file).
- Do **not** run the full suite with `--parallel` (`.claude/rules/testing.md`
  forbids it) and don't run two suite invocations concurrently.

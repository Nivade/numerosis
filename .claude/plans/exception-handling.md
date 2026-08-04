# Exception Handling — Analysis & Plan

**Status: ✅ Executed.** Findings 1–5 shipped (`fccd1b6`) — domain exceptions,
`ShowsMessageToUser`, `context()`, narrowed catches, job exit-code checks.
Phase 5 (Sentry tenant-tagging on job failure) closed via
`App\Concerns\TagsSentryScopeWithTenant`, used by `FinalizeTenantProvisioning`
and `SeedTenantDatabase` — see `.claude/rules/exception-handling.md`. Phase 6
(`failed_jobs` table) and Phase 7 (coverage/consistency) both confirmed done.

Branch: `chore/exception-handling`

## Current state

- `bootstrap/app.php` `withExceptions()` contains exactly one line: `Integration::handles($exceptions)`. No context, no `dontReport`, no `render`, no throttling.
- 41 `throw` sites, of which ~14 throw bare `\Exception` as a *domain* error.
- Only 11 `catch` sites app-wide. Two of them display `$e->getMessage()` directly in the UI; two swallow `Throwable`/`Exception` and return a fallback with no report.
- `app/Exceptions/` holds a single class, `ProviderNotFoundException`, whose constructor is byte-identical to its parent.
- 8 queued classes; exactly one (`FinalizeTenantProvisioning`) has a `failed()` handler, and it is the only one with `$tries`/`$backoff`.
- Sentry is configured, `send_default_pii => false`, so events carry no user and — more importantly — **no tenant**.
- Error views for 401/402/403/404/419/429/500/503 exist and are unreferenced by any test.

## Findings

### 1. Sentry events from tenant requests carry no tenant (highest value)

Every exception raised inside a tenant is indistinguishable from every other tenant's. With stancl switching database, cache prefix and guard per request, "which tenant" is the first question for any report and the payload cannot answer it.

Fix: `$exceptions->context()` returning tenant key, tenancy-initialized flag, current guard (`Auth::getDefaultDriver()`), current Filament panel, and the authenticated user's `global_id` (a stable, non-PII id — safe with `send_default_pii` off).

Caveat: `context()` runs at report time, which for a queued job is inside the job's tenant context and for an HTTP request is after tenancy identification — both correct. It must be defensive (`tenancy()->initialized` guard, try/catch nothing) because it runs while the app is already in a bad state.

### 2. Internal exception messages rendered to end users

- `app/Filament/TenantAdmin/Pages/Billing.php:169` — `catch (Exception $e)` sends `$e->getMessage()` as a Filament danger notification. The try block calls Stripe, so a `Stripe\Exception\*` message, a `QueryException` with SQL, or a `RuntimeException` from our own code all reach the browser verbatim.
- `app/Livewire/Invitations/Accept.php:67` — same shape, flashes `$e->getMessage()` to session.
- `app/Http/Controllers/Socialite/Login.php:127` — `catch (Throwable $e)` flashes `$e->getMessage()`.

These are safe *today* only because the messages happen to be ours. They are not typed, so nothing keeps it that way.

Fix: a marker interface for exceptions whose message is intentionally user-facing, and catch that instead.

### 3. No domain exception types

Bare `throw new Exception(...)` at:

| Site | Meaning |
|---|---|
| `Actions/Billing/Checkout/StartSubscriptionCheckout.php` | payment plan not found |
| `Services/Billing/Checkout/StripeCheckoutGateway.php` (×4) | plan not found, price id missing, bad billable, missing cycle |
| `Actions/Tenancy/ReserveTenantDomain.php` | domain already claimed |
| `Actions/Tenancy/PromoteFirstUserToAdmin.php` | no non-bot users |
| `Actions/Invitations/AcceptInvitation.php` (×3) | already accepted / expired / wrong tenant |
| `Filament/TenantAdmin/Pages/Billing.php` | no tenant initialized |

`Exception` is uncatchable-by-meaning: callers must catch everything or nothing, which is exactly what finding 2 shows. Note the `RuntimeException`/`LogicException`/`InvalidArgumentException` sites are *correct* and should stay — they signal programmer error, not user error, and must keep reaching Sentry.

### 4. Silent swallows with no report

- `app/Concerns/InteractsWithTenantModules.php:45` — `catch (Throwable) { return null; }`. A module then reads as *disabled* rather than *broken*, and nothing is logged. Catch is also too wide for what the block does (one query on a temp connection).
- `app/Filament/TenantAdmin/Pages/Billing.php:234` — `catch (Exception) { return 'N/A'; }`. Billing figures silently degrade.

Fix: narrow the catch, keep the fallback, add `report($e)`.

### 5. Queued work fails invisibly

- `MigrateModules` / `RollbackModules` call `Artisan::call(...)` and discard the exit code. A failed module migration returns non-zero, throws nothing, and the job is marked successful.
- `SyncTenantToStripe` has no `$tries`, no `$backoff`, no `failed()`. A single Stripe `ApiConnectionException` loses the sync permanently and the report has no tenant on it (see finding 1).
- `SeedTenantDatabase`, `MigrateModules`, `RollbackModules` have no `failed()`; per `.claude/rules/tenant-provisioning.md`, anything that dies between tenant creation and `FinalizeTenantProvisioning` leaves the UI spinning forever.
- `ProvisionTenant::handle()` calls `Cashier::stripe()->subscriptions->retrieve()` unguarded. A Stripe outage burns all 5 tries and lands in `jobFailed()` — marking the provision failed even though the tenant database is fine. A transient Stripe error should `release()`, not consume a try.

### 6. No error-report throttling

Provisioning retries (`ProvisionTenant` 5×, `FinalizeTenantProvisioning` 20×) plus Stripe webhook retries multiply one incident into dozens of Sentry events. `$exceptions->throttle()` with a per-type limit is the standard fix and costs one closure.

### 7. Effectively no test coverage

`grep` finds one file using `expectException`. Nothing asserts the 404/500 views render, nothing asserts a domain exception maps to its status, nothing exercises a `failed()` path except `FinalizeTenantProvisioning`.

### 8. Small items

- `ProviderNotFoundException`'s constructor is redundant — delete it, keep the class.
- `WebhookController` imports `use Log;` (the root alias) instead of `Illuminate\Support\Facades\Log`; inconsistent with the rest of the app and invisible to static analysis of the facade.

## Plan

Phases are independently shippable; 1 and 2 carry most of the value.

### Phase 1 — Global handler (`bootstrap/app.php`)

1. `->context()` — tenant key, `tenancy()->initialized`, guard, panel id, `global_id`. Defensive; never throws.
2. `->dontReportDuplicates()`.
3. `->throttle()` — `Limit::perMinute(30)` for everything, with `Lottery` sampling for the noisiest known types.
4. `->render()` for `ProviderNotFoundException` → 404 (currently 500), and for the new domain exceptions per Phase 2.

Tests: feature test asserting the context array contains the tenant key when reported inside `tenancy()->initialize()`, and 404 for an unknown socialite provider.

### Phase 2 — Domain exception hierarchy

New under `app/Exceptions/`:

- `interface ShowsMessageToUser` — marker; message is intentionally user-facing.
- `abstract class DomainException extends RuntimeException implements ShowsMessageToUser`, with an optional `status(): int` for `render()`.
- Concretes replacing the bare `Exception` throws in finding 3, grouped `Billing/` and `Tenancy/` and `Invitations/` to match the actions' own layout: `PaymentPlanNotFound`, `StripePriceNotConfigured`, `UnsupportedBillable`, `BillingCycleRequired`, `DomainAlreadyClaimed`, `NoPromotableUser`, `TenantNotInitialized`, `InvitationAlreadyAccepted`, `InvitationExpired`, `InvitationTenantMismatch`.

Then narrow the three UI catches (finding 2) to `catch (ShowsMessageToUser $e)` and let anything else escape to the handler. Keep a generic fallback notification where a page must not white-screen — but with a fixed string, not `getMessage()`.

Leave `RuntimeException`/`LogicException`/`InvalidArgumentException` sites alone.

Tests: update the existing invitation/checkout tests to expect the new types; add one asserting a non-domain exception in `Billing::changePlan` is *not* shown to the user.

### Phase 3 — Swallowed-exception cleanup

- `InteractsWithTenantModules` — catch `QueryException` (plus `InvalidArgumentException` for the connection), `report($e)`, keep `return null`.
- `Billing.php:234` — same treatment, keep `'N/A'`.

Test: module lookup against a missing tenant database returns null *and* reports.

### Phase 4 — Queue hardening

- `MigrateModules` / `RollbackModules` — check `Artisan::call()` exit code, throw on non-zero with the command output in the message.
- `SyncTenantToStripe` — `$tries = 3`, `$backoff = [10, 30]`, `failed()` reporting with the tenant key.
- `SeedTenantDatabase`, `MigrateModules`, `RollbackModules` — `failed()` handlers that mark the provision failed via `MarkProvisionFailed`, consistent with `FinalizeTenantProvisioning`.
- `ProvisionTenant` — wrap the Stripe retrieve; on `ApiConnectionException` / rate-limit, `release($this->jobBackoff)` instead of consuming a try.
- Consider a `Queue::failing` listener that adds the job's tenant tag to the Sentry scope — the queue payload has it and the context hook (Phase 1) may not, since job failure is reported after tenancy has ended.

Tests: one per new `failed()` path; `Artisan::shouldReceive('call')->andReturn(1)` asserts `MigrateModules` throws.

### Phase 5 — Sentry scope tagging for job failures

Surfaced while implementing Phase 4, not fixed there — bigger than a queue tweak.

`$exceptions->context()` (Phase 1) reads `tenancy()->initialized` at report time. For an HTTP request that's correct. For a queued job that fails *inside* a `$tenant->run()` closure (e.g. `FinalizeTenantProvisioning`, `PromoteFirstUserToAdmin`), it is not: `$tenant->run()` reverts tenancy in its own `finally` as the exception unwinds, and only afterwards does `Illuminate\Queue\Worker::runJob()`'s catch block call `$this->exceptions->report($e)` (confirmed in `vendor/laravel/framework/src/Illuminate/Queue/Worker.php:507` — `$reportJobExceptions` defaults `true`, called from the `runJob()` catch, which sits above `process()` → `handleJobException()` → the job's own `handle()`/`failed()`). By the time that automatic report fires, `tenancy()->initialized` is already `false`, so the event reports with no tenant — for exactly the job failures where knowing the tenant matters most.

Ordering that makes the fix possible: `Job::fail($e)` calls the job's own `failed($e)` (via `$this->failed($e)` in `Illuminate\Queue\Jobs\Job::fail()`) *before* control returns to `Worker::runJob()`'s catch and its automatic `report($e)`. So a job's `failed()` handler runs first and can put the tenant on Sentry's scope while it's still known — that tag then survives the automatic report that follows.

Fix: a trait (e.g. `TagsSentryScopeWithTenant`) used by `failed()` handlers that currently know a tenant (`FinalizeTenantProvisioning`, `SeedTenantDatabase`, and any future one), calling something equivalent to `\Sentry\configureScope(fn ($scope) => $scope->setTag('tenant_id', $tenantKey))` before the automatic report fires. Keep it a no-op if Sentry isn't configured (local dev without a DSN).

Tests: a job whose `handle()` throws inside `$tenant->run()`, asserting the tag lands on the reported event (fake the Sentry client / assert via a test double) rather than asserting on `tenancy()->initialized`, which is exactly the thing proven not to help here.

### Phase 6 — `failed_jobs` table doesn't exist

Surfaced by a direct question ("are failed jobs logged with the reason?") rather than by the original audit — worth having asked.

`config/queue.php:109` — `QUEUE_FAILED_DRIVER` defaults to `database-uuids`, which persists exhausted-retry jobs to a `failed_jobs` table. `database/migrations/2026_01_07_195854_remove_redundant_tables.php` drops that table alongside `cache`/`sessions`/`jobs`/`job_batches` on the reasoning that moving to Redis (`.env`: `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`) made all of them redundant — true for `jobs` (Redis stores the queue itself), false for `failed_jobs` (an unrelated concern, gated by `QUEUE_FAILED_DRIVER`, not `QUEUE_CONNECTION`). `QUEUE_FAILED_DRIVER` is never overridden in `.env`, so it's still trying to write there. Nothing recreates the table afterward.

Consequence, confirmed by reading `vendor/laravel/framework/.../Queue/Console/WorkCommand.php:341` (`logFailedJob()`, registered on the `JobFailed` event in `listenForEvents()`, both uncaught by anything in that path): every job that exhausts its retries triggers a `QueryException` from `queue:work`'s own failure-handling code, since it inserts into a table that no longer exists. That's not a missing log line — it can take the worker process down. `php artisan queue:failed` will show nothing regardless of how many jobs actually failed.

Separately, `Illuminate\Queue\Worker::runJob()` (a different code path — its own try/catch around `process()`, not the `WorkCommand` listener) does call `$this->exceptions->report($e)` for every job exception by default (`$reportJobExceptions = true`), which is wired to Sentry via `Integration::handles($exceptions)`. So the exception was already reaching Sentry with its message — Phase 6 is about the *local* record (`failed_jobs`/`queue:failed`), which was silently broken independent of that.

Fix: recreate `failed_jobs` (same shape as the original `0001_01_01_000002_create_jobs_table.php` — `uuid` unique, `connection`, `queue`, `payload`, `exception`, `failed_at`). Central only: `config('queue.failed.database')` resolves `env('DB_CONNECTION')` → `'mysql'`, which is the same physical database as the `central` connection (`config/database.php` — both point at `DB_DATABASE`), so a tenant-side copy would never be read. The tenant migration that also dropped it (`database/migrations/tenant/2026_01_07_200130_remove_redundant_tables.php`) was dead weight to begin with, not a second copy of this bug — left alone.

### Phase 7 — Coverage and consistency

- Feature tests rendering each error view (404/403/419/500) so a broken layout is caught.
- Fix the `use Log;` import.
- Delete `ProviderNotFoundException`'s redundant constructor.
- Run `vendor/bin/phpstan analyse` (includes `tests/`) after Phase 2's renames — per `.claude/rules/testing.md` this is where a missed call site surfaces.

## Explicitly not doing

- No new error-tracking dependency; Sentry already covers it.
- Not touching `SeatLimitPlanPolicy`'s `catch (ValidationException) { return false; }` — deliberate and correct.
- Not converting `RuntimeException`/`LogicException` throws to domain types; they mean "bug", and that signal is worth keeping.
- No `try/catch` added around code that currently has none purely for defensiveness — an uncaught exception that reaches the handler with good context is better than a caught one that degrades silently.

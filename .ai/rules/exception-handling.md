---
paths:
  - 'src/Exceptions/**'
  - 'src/Concerns/Tenancy/TagsSentryScopeWithTenant.php'
  - 'src/Jobs/**'
  - 'database/migrations/**'
---
# Exception Handling

- **`Numerosis::exceptions()` is idempotent per `Handler` instance, and has to be.** `NumerosisServiceProvider::registerExceptionHandling()` always calls it from `packageBooted()`, so a host that also calls it from its own `bootstrap/app.php` would register the context callback and the throttle twice onto the same handler. A `WeakMap<Handler, true>` records which instances have been done; the second call for a given `$exceptions->handler` no-ops whichever order the two arrive in, while a different `Handler` (a fresh one per test, or a new application under Octane) always registers. Keyed on the handler rather than a boolean for that reason: a static flag would leave the second application in a process silently without exception context.

- **A `withExceptions()` context closure must never itself throw — it can run before the container is fully bootstrapped.** `Numerosis::exceptions()`'s `context()` closure called `Auth::user()` unconditionally. If the exception being reported was thrown *during* application bootstrap — specifically before the `RegisterFacades` bootstrapper runs — `Facade::$app` is still null, so `Auth::user()` (or any facade call) throws `RuntimeException: A facade root has not been set`. That secondary throw happens inside `Handler::report()` → `exceptionContext()`, uncaught, so the framework's own exception handling fatals reporting the *original* exception — the real error is never logged, only the masking "facade root not set" one is, and it's identical on every occurrence regardless of what actually broke. In a supervisor-managed process (`queue`, `reverb`) this reproduces on every respawn, crash-looping forever with a useless, repeated log line. Fixed by wrapping the whole closure body in `try/catch (Throwable) { return []; }` — context is best-effort, never a hard dependency. See `package-host-bootstrap.md` for the actual bootstrap-time bug (`Domains::appUrl()`) that this masked in the 2026-08-07 incident.

- **`Nvade\Numerosis\Exceptions\DomainException` (implements `ShowsMessageToUser`) only exception type UI allowed show verbatim.** Marks expected, user-facing fails from normal use (already-accepted invitation, claimed domain, missing Stripe price) — not bugs. Eleven catch sites across six files — `Livewire\Billing\Checkout` (5), `Actions\Billing\Checkout\CompleteRedirectCheckout` (2), `Http\Controllers\Socialite\Login`, `Livewire\Invitations\Accept`, and both `Livewire\Tenant\Registration\Steps\{Plan,TechnicalSetup}` — are all typed `catch (ShowsMessageToUser $e)`, never `catch (Exception $e)` / `catch (Throwable $e)`, so an unexpected error can't leak `$e->getMessage()` (SQL, Stripe internals, our own runtime errors) to the browser. Grep for `catch (ShowsMessageToUser` rather than trusting that list; it grows. **`RuntimeException`/`LogicException`/`InvalidArgumentException` throws elsewhere deliberately left alone** — signal programmer error, must stay uncaught by UI, escape to handler with full context. Don't add new bare `throw new Exception(...)` for domain-expected fail; add concrete subclass under `src/Exceptions/{Billing,Tenancy,Invitations}/` instead, grouped to match actions' own layout.

- **`bootstrap/app.php`'s `$exceptions->context()` closure reads `tenancy()->initialized` at *report* time — correct for HTTP, wrong for job that failed inside `$tenant->run()` closure.** `$tenant->run()` reverts tenancy in own `finally` as exception unwinds; only after does `Illuminate\Queue\Worker::runJob()`'s catch call `$this->exceptions->report($e)` (`$reportJobExceptions` defaults `true`). By time automatic report fires, tenancy already reverted, so Sentry event for exactly job fails where tenant identity matters most used to report `tenant_id: null`. **Fixed**: `Nvade\Numerosis\Concerns\Tenancy\TagsSentryScopeWithTenant` is `failed()`-handler trait tagging Sentry's scope with tenant before automatic report, exploiting that `Job::fail($e)` calls job's own `failed($e)` *before* `Worker::runJob()`'s automatic report runs. Used by `FinalizeTenantProvisioning` and `SeedTenantDatabase` — two jobs whose fails most likely need tenant to debug. No-ops if `app()->bound('sentry')` false (local dev, no DSN). Test: `tests/Feature/Concerns/TagsSentryScopeWithTenantTest.php` asserts tag actually lands on event built from scope, not on `tenancy()->initialized`, exactly thing proven not help here.

- **`QUEUE_FAILED_DRIVER` (`database-uuids`) independent of `QUEUE_CONNECTION` (`redis`); dropping `failed_jobs` under "moved to Redis, so redundant" broke `queue:work`'s own failure handling.** Migration once dropped `cache`/`sessions`/`jobs`/`job_batches`/`failed_jobs` together on that reasoning — true for `jobs` (Redis stores live queue), false for `failed_jobs` (*record* of exhausted-retry jobs, gated by `QUEUE_FAILED_DRIVER`, not `QUEUE_CONNECTION`). Every job exhausting retries then threw `QueryException` from `WorkCommand`'s own `JobFailed` listener trying insert into table no longer existed — not missing log line, worker-killing error. Recreated in `2026_07_28_233114_recreate_failed_jobs_table.php`. **Central only** — `config('queue.failed.database')` resolves to same physical DB as `central` connection, so tenant-side copy never read; tenant migration also dropping it was dead weight, not second copy of this bug, left alone. Future cleanup pass looking at "redundant tables" again: check what gates table (`QUEUE_FAILED_DRIVER`), not what changed most recently (`QUEUE_CONNECTION`).

- **A queue job that calls `Artisan::call()` must check the exit code.** Worked example was `MigrateModules`/`RollbackModules` — both deleted with the module system in Phase 2 (2026-09-03), so there is no live instance of this in core today; the rule is what survives. A failed migration returns non-zero, throws nothing, and the job reports success. Check the return value and throw with the command output on non-zero. A job "succeeding" without doing work is worse than one that throws — nothing downstream knows to retry or alert.

- **Swallowed exceptions must narrow to what the block actually expects, and must still `report()`.** Two worked examples, one gone: `InteractsWithTenantModules` (deleted Phase 2) and `Billing::getRenewsAt` used to `catch (Throwable)` / `catch (Exception)` and return a silent fallback (a module read "disabled" rather than "broken"; billing figures degrade to `'N/A'`) with nothing logged. Fix pattern: catch the specific exception the block can actually produce (e.g. `QueryException` for a query against a tenant connection that might not exist), keep the fallback return, add `report($e)` so the failure is visible without changing behavior.
## Never re-throw a caught exception's `getCode()` (2026-09-12)

`PDOException::getCode()` is the SQLSTATE **string** (`'HY000'`), and
`RuntimeException`/`Exception` take an `int`. So
`throw new RuntimeException($msg, $e->getCode(), previous: $e)` raises a
`TypeError` *from inside the catch block* the moment the cause is a database
error, and the real exception is destroyed along with the handler.

It surfaced as `Argument #2 ($code) must be of type int, string given`,
pointing at `SeedTenantDatabase::handle()` and saying nothing about the query.
Five sites did this; all now pass `0`. `previous:` already carries the cause,
so the code was never buying anything.

**A test for this is vacuously green unless the code is set the way a driver
sets it.** `new PDOException('...')` leaves `$code` at the default int `0`, so
the first regression test passed against the unfixed code. Subclass and assign
`$this->code = 'HY000'` in the constructor, then check the test fails with the
fix reverted.

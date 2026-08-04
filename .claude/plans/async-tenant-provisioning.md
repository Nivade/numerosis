# Fully-Async Tenant Provisioning

**Status: ✅ Executed.** `pending_tenant_provisions` table, `ProvisionTenant`,
`tenants.provisioned_at` readiness signal all in place — see
`.claude/rules/tenant-provisioning.md`.

## Context

Today, tenant creation happens synchronously inside `ProcessSuccessfulCheckout`
(the browser redirect after Stripe Checkout) and is duplicated as a fallback
inside `WebhookController::handleCustomerSubscriptionCreated`. Both paths call
`CreateTenant::handle()` (via the `CreatesTenant` contract) for the same
domain, protected from racing each other by `Cache::lock("tenant-provision:{domain}")`
(see `.claude/rules/tenant-provisioning.md`). The user sits on the Stripe
redirect while `CreateTenant` runs domain creation, owner membership, and
dispatches `MakeFirstUserAdmin`.

Agreed direction: stop making the user wait. Checkout success does the
minimum synchronous work, redirects immediately to `tenants/mine`, and all
heavy lifting (tenant DB creation, migration, seeding, domain, owner
membership, admin role, subscription reconciliation) happens in the
background. `tenants/mine` shows a spinner for any tenant still being
provisioned and promotes it to a normal row live, via Reverb broadcasting,
once done — with a visible failure state and a polling fallback so the UI
never dead-ends.

This plan reuses the existing Lorisleiva `AsAction` pattern throughout and
rewrites existing actions/jobs where the async model requires it. Direct
answers to the two questions asked: **yes, build `ProcessFailedCheckout`**
(item 6) — there is currently no real handling of a cancelled checkout at
all — and the other gaps found are in item 11.

## Core design decision: readiness signal

The naive version of this design uses "a `pending_tenant_provisions` row
exists" as the *only* still-provisioning signal. **That is not sufficient**,
because `WebhookController` creates `Tenant` rows on a path that has no
pending row (user closes the tab on Stripe's page and never returns). During
that window `tenants/mine` would render a fully clickable "Visit" row for a
tenant whose database does not exist yet — exactly the bug this redesign
exists to eliminate.

So readiness is defined as **`tenants.provisioned_at !== null`**, and the
pending table exists only to render a placeholder *before* the `Tenant` row
itself exists. `tenants/mine` shows a spinner for:

1. `PendingTenantProvision` rows for this user (no `Tenant` row yet), and
2. `Tenant` rows belonging to this user where `provisioned_at` is null.

`provisioned_at` is load-bearing, not just observability.

## Data model

New central-DB migration `create_pending_tenant_provisions_table`, alongside
the existing `add_stripe_columns_to_tenants_table` (same directory, central
connection, same Blueprint style):

```php
Schema::create('pending_tenant_provisions', function (Blueprint $table) {
    $table->string('domain')->primary();
    $table->string('company_name');
    $table->string('global_id')->index();
    $table->string('status')->default('reserved'); // reserved | provisioning | failed
    $table->timestamp('failed_at')->nullable();
    $table->text('error')->nullable();
    $table->timestamps();
});
```

- `domain` as primary key mirrors how `tenants.id` is already the domain
  (`Tenant::forceCreate(['id' => $data->domain, ...])` in `CreateTenant`).
- `status`: `reserved` when the checkout session is created (domain claimed,
  user has not paid yet), `provisioning` once payment is confirmed and the
  job is dispatched, `failed` when the job exhausts retries. Distinguishing
  `reserved` from `provisioning` matters so the prune command does not page
  someone about a merely-abandoned checkout (item 10).
- Row is deleted once provisioning succeeds (end of `MakeFirstUserAdmin`).

Second migration `add_provisioned_at_to_tenants_table` (central):

```php
Schema::table('tenants', function (Blueprint $table) {
    $table->timestamp('provisioned_at')->nullable();
});

// Existing tenants are already provisioned — without this backfill every
// pre-existing tenant renders as a permanent spinner.
DB::connection('central')->table('tenants')->update([
    'provisioned_at' => DB::raw('created_at'),
]);
```

New Eloquent model `App\Models\Central\PendingTenantProvision` (central
connection, matching `Tenant`/`CentralUser` conventions in
`app/Models/Central/`), `$primaryKey = 'domain'`, `$incrementing = false`,
`$keyType = 'string'`.

## 1. Reserve the domain at checkout-session creation

`app/Actions/Billing/Subscription/StripeCheckout.php`

Create the pending row **before** redirecting the user to Stripe, not on the
way back. Two problems this solves that a success-time row does not:

- **Domain race.** `TechnicalSetup` currently validates
  `unique:domains,domain` only. Two users can both reach checkout for
  `acme` and both pay; whoever returns second silently loses (their
  `firstOrCreate` attaches to the first user's row and they never get a
  tenant). Reserving at session-creation time makes the claim atomic on the
  primary key, at the moment the user commits to that domain.
- **Closed-tab case.** The user gets a placeholder row on `tenants/mine`
  even if they never come back from Stripe's page.

Just before `return $stripeSubscription->checkout(...)`:

```php
PendingTenantProvision::firstOrCreate(['domain' => $data->domain], [
    'company_name' => $data->company_name,
    'global_id' => $data->global_id,
    'status' => 'reserved',
]);
```

If the row already exists with a different `global_id`, abort — the domain
is claimed by someone else mid-checkout. Add the matching validation rule to
`app/Livewire/Tenant/Registration/Steps/TechnicalSetup.php` so the wizard
rejects it before the user ever reaches Stripe:

```php
#[Validate('required|string|unique:domains,domain|unique:central.pending_tenant_provisions,domain|regex:/^[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]$/')]
public string $domain = '';
```

Trade-off accepted: abandoned checkouts leave `reserved` rows behind. That
is the same garbage `PruneOrphanedStripeCustomers` already tolerates for
Stripe customers, and item 10 prunes them on the same schedule.

## 2. `ValidateCheckout` — one Stripe call, ownership gate, error handling

`app/Http/Middleware/ValidateCheckout.php`

Three changes, all in the same method. This middleware is already bound to
the success route via `ProcessSuccessfulCheckout::getControllerMiddleware()`,
and controller middleware runs after the route's `auth` middleware, so
`$request->user()` is populated here.

```php
public function validate(Request $request, Closure $next)
{
    if (! $request->has('session_id')) {
        return $this->redirector
            ->intended($this->urlGenerator->route('tenants.mine'))
            ->with('error', 'Invalid checkout session.');
    }

    try {
        $session = Cashier::stripe()->checkout->sessions->retrieve(
            $request->get('session_id'),
            ['expand' => ['subscription']]
        );
    } catch (ApiErrorException) {
        return to_route('tenants.mine')->with('error', 'Invalid checkout session.');
    }

    if (! $session || $session->payment_status !== 'paid') {
        return to_route('tenants.mine')->with('error', 'Payment not completed.');
    }

    abort_unless(
        ($session->metadata['global_id'] ?? null) === $request->user()->global_id,
        403,
    );

    $request->attributes->set('checkoutSession', $session);

    return $next($request);
}
```

- **Ownership gate.** Currently nothing checks that the authenticated user
  owns the checkout session being processed. The tenant owner comes from
  Stripe metadata (`global_id`) while the subscription's user id comes from
  `$request->user()->id` — replaying someone else's `session_id` provisions a
  tenant owned by the victim with billing rows pointing at the replayer.
  Fails closed: missing metadata is also a 403, since this route only ever
  handles tenant checkouts.
- **Exception handling.** `sessions->retrieve()` on an expired or tampered
  `session_id` currently throws and surfaces as a 500.
- **Single retrieve.** `ProcessSuccessfulCheckout` made a second, near-
  identical call; the session is now handed forward on `$request->attributes`.
  `Request::createFrom()` passes `$from->attributes->all()` through, so this
  survives Lorisleiva's `ActionRequest` resolution — worth an explicit test
  since it fails silently if that ever changes.

## 3. `ProcessSuccessfulCheckout` rewrite

`app/Actions/Billing/Subscription/ProcessSuccessfulCheckout.php`

Now purely: mark the reservation as paid, dispatch, redirect.

```php
public function handle(ActionRequest $request)
{
    $session = $request->attributes->get('checkoutSession')
        ?? Cashier::stripe()->checkout->sessions->retrieve(
            $request->get('session_id'), ['expand' => ['subscription']]
        );

    $checkoutData = CheckoutData::from($session->metadata->toArray());

    if (! Tenant::whereId($checkoutData->domain)->exists()) {
        PendingTenantProvision::updateOrCreate(
            ['domain' => $checkoutData->domain],
            [
                'company_name' => $checkoutData->company_name,
                'global_id' => $checkoutData->global_id,
                'status' => 'provisioning',
            ],
        );

        $stripeSubscription = $session->subscription;

        ProvisionTenant::dispatch(
            $checkoutData,
            $session->customer,
            $stripeSubscription instanceof StripeSubscription
                ? $stripeSubscription->id
                : $stripeSubscription,
            $request->user()->id,
        );
    }

    return to_route('tenants.mine')->with('success', 'Your tenant is being set up.');
}
```

- The `CreatesTenant` contract dependency is removed from this action
  entirely — the constructor injection goes away.
- `updateOrCreate` promotes the `reserved` row from item 1 to
  `provisioning`, and still works if the reservation is somehow missing.
- Only the subscription **id** is passed to the queue, not the expanded
  `StripeSubscription` SDK object. Serializing SDK objects into job payloads
  is fragile across `stripe/stripe-php` upgrades and bloats the payload;
  `ReconcileTenantSubscription` re-retrieves it when needed.
- Falls back to a fresh retrieve if the middleware did not run, so calling
  `->handle()` directly in a test stays correct.

## 4. New action: `ProvisionTenant`

`app/Actions/Tenancy/ProvisionTenant.php` (new file, `ShouldQueue`,
`ShouldBeUnique`, `AsAction`)

The single queued entry point for all provisioning, used by the
checkout-success path, the webhook path, and `DevCheckout` (item 9).

Note the lorisleiva job-decorator API: an `AsAction` class does **not** use
plain `$tries` / `$backoff` / `failed()` — those live on the decorator, and
the action configures them via `configureJob()`, `getJobUniqueId()` and
`jobFailed()`. Getting this wrong fails silently (the properties are simply
ignored and retries/uniqueness never apply), which matters here because the
`failed` state is what the UI depends on.

```php
public function configureJob(JobDecorator $job): void
{
    $job->tries(5)->backoff(10);
}

public function getJobUniqueId(CheckoutData $checkoutData): string
{
    return $checkoutData->domain;
}

public function handle(
    CheckoutData $checkoutData,
    string $stripeCustomerId,
    ?string $stripeSubscriptionId,
    string|int $userId,
): void {
    $tenant = CreateTenant::run($checkoutData);

    if ($stripeSubscriptionId) {
        $stripeSubscription = Cashier::stripe()->subscriptions->retrieve($stripeSubscriptionId);

        ReconcileTenantSubscription::run(
            $stripeCustomerId,
            $stripeSubscriptionId,
            $stripeSubscription,
            $checkoutData,
            $tenant,
            $userId,
        );
    }
}

public function jobFailed(Throwable $e, array $parameters): void
{
    /** @var CheckoutData $checkoutData */
    $checkoutData = $parameters[0];

    PendingTenantProvision::where('domain', $checkoutData->domain)->update([
        'status' => 'failed',
        'failed_at' => now(),
        'error' => $e->getMessage(),
    ]);

    TenantProvisioningFailed::dispatch($checkoutData->domain, $checkoutData->global_id);
}
```

`ShouldBeUnique` keyed on domain stops duplicate jobs from ever queuing when
the redirect and the webhook both fire; the `Cache::lock` inside
`CreateTenant` remains the correctness guarantee, this is the cheap
optimization on top.

`CreateTenant` itself needs **no changes** — already race-safe
(`Cache::lock("tenant-provision:{domain}")`) and idempotent (find-or-create),
which is exactly what the queued caller needs.

## 5. `MakeFirstUserAdmin` — order it correctly, make it the done-signal

`app/Jobs/MakeFirstUserAdmin.php` + `app/Providers/TenancyServiceProvider.php`

This job reads `User::where('is_bot', false)->first()` **from the tenant
database** — which only exists after stancl/tenancy's queued
`CreateDatabase` → `MigrateDatabase` → `SeedTenantDatabase` pipeline (see
`TenancyServiceProvider::events()`, `TenantCreated`) has run. Today
`CreateTenant` dispatches it independently of that pipeline, so it races the
database's existence and survives only on retry — and it declares
`$backoff = 3` with **no `$tries`**, so it gets the queue default. That is
tolerable while nothing depends on it; this plan makes it the sole emitter
of "provisioning finished", so a silent exhaustion means a permanent
spinner.

Recommended: append it to the existing pipeline so ordering is guaranteed
rather than retried into:

```php
TenantCreated::class => [
    JobPipeline::make([
        CreateDatabase::class,
        MigrateDatabase::class,
        SeedTenantDatabase::class,
        MakeFirstUserAdmin::class,
    ])->send(fn (TenantCreated $event) => $event->tenant)
        ->shouldBeQueued(true),
],
```

and drop the `dispatch(new MakeFirstUserAdmin($tenant))` line from
`CreateTenant::handle()`.

Pipeline jobs receive only the tenant, so the owner is resolved inside the
job via the existing `Tenant::owner()` method rather than a constructor
argument. One narrow race remains — `TenantCreated` fires on
`Tenant::forceCreate()`, a few lines before `AddTenantOwner` runs inside the
same lock — so keep bounded retries for that window:

```php
public int $tries = 20;

public int $backoff = 3;

public function __construct(protected Tenant $tenant)
{
    $this->afterCommit = true;
}

public function handle(): void
{
    $owner = $this->tenant->owner();

    if (! $owner) {
        throw new Exception('Tenant owner membership not yet written');
    }

    $this->tenant->run(function () {
        $user = User::where('is_bot', false)->first();

        if (! $user) {
            throw new Exception('No non-bot users found');
        }

        if (! $user->assignRole('admin')) {
            throw new Exception('Failed to assign admin role');
        }
    });

    $this->tenant->update(['provisioned_at' => now()]);
    PendingTenantProvision::where('domain', $this->tenant->id)->delete();

    TenantProvisioned::dispatch($this->tenant, $owner->id);
}

public function failed(Throwable $e): void
{
    PendingTenantProvision::where('domain', $this->tenant->id)->update([
        'status' => 'failed',
        'failed_at' => now(),
        'error' => $e->getMessage(),
    ]);

    TenantProvisioningFailed::dispatch($this->tenant->id, $this->tenant->owner()?->global_id);
}
```

Minimal-diff alternative if folding into the pipeline turns out to fight
stancl's job-pipeline serialization: keep the current dispatch from
`CreateTenant` (passing `$user->id` as a constructor arg) and just add the
explicit `$tries = 20` plus the `failed()` handler. Ordering stays
retry-based, which is what it is today — the `failed()` handler is the part
that is non-negotiable either way.

Idempotency on the crash-recovery path (`CreateTenant` re-entered,
uncontended lock, tenant already exists): `assignRole` on an existing role is
a Spatie no-op, the `provisioned_at` update and the pending-row delete are
naturally idempotent, and a repeat `TenantProvisioned` broadcast just
re-confirms a row the UI may already show as ready.

## 6. New action: `ProcessFailedCheckout` — yes, build this

Currently `checkout/subscription/failed` (Stripe's `cancel_url`) routes to
`Tenant\Mine::class`, a Livewire component with **no logic tied to that
route at all** — it does not read query params, flash a message, or know a
checkout failed. A user who cancels Stripe Checkout gets a silent redirect
with no explanation. Stripe's `cancel_url` carries no `session_id`, so this
can only acknowledge that *a* checkout was abandoned, not look up which.

New `app/Actions/Billing/Subscription/ProcessFailedCheckout.php`:

```php
class ProcessFailedCheckout
{
    use AsAction;

    public function handle(): RedirectResponse
    {
        return to_route('tenants.create')
            ->with('error', 'Checkout was cancelled. You can try again below.');
    }

    public function asController(): RedirectResponse
    {
        return $this->handle();
    }
}
```

Redirects to `tenants.create` (the registration wizard) rather than
`tenants.mine` — the user was mid-signup, so returning them to the step
where they can retry beats a tenant list that, for a first-time signup, is
empty. Route change in `routes/web.php`:

```php
Route::get('/checkout/subscription/failed', ProcessFailedCheckout::class)->name('checkout.tenant.failed');
```

Then delete `app/Livewire/Tenant/Mine.php` and `resources/views/livewire/tenant/mine.blade.php`
— verified referenced only from `routes/web.php:44` and from its own
`view()` call, nowhere else.

## 7. `tenants/mine` — pending, provisioning, failed, ready

`resources/views/pages/tenant/⚡mine.blade.php`

```php
public ?CentralUser $user = null;

public Collection $pendingTenants;

public Collection $provisioningTenants;

public Collection $readyTenants;

public function mount(): void
{
    $this->user = GetAuthenticatedUser::run();
    $this->refreshTenants();
}

#[On('echo-private:user.{user.id},.tenant.provisioned')]
#[On('echo-private:user.{user.id},.tenant.provisioning-failed')]
public function refreshTenants(): void
{
    if (! $this->user) {
        $this->pendingTenants = new Collection;
        $this->provisioningTenants = new Collection;
        $this->readyTenants = new Collection;

        return;
    }

    $tenants = $this->user->tenants()->get();

    $this->readyTenants = $tenants->whereNotNull('provisioned_at');
    $this->provisioningTenants = $tenants->whereNull('provisioned_at');
    $this->pendingTenants = PendingTenantProvision::query()
        ->where('global_id', $this->user->global_id)
        ->whereNotIn('domain', $tenants->pluck('id'))
        ->get();
}
```

Typed `Collection` properties are initialised on every path — an
uninitialised typed property throws on Livewire's render/hydrate cycle when
`$user` is null.

Rendering:
- `readyTenants` — current row markup, "Visit" button.
- `provisioningTenants` + `pendingTenants` (status `reserved`/`provisioning`)
  — spinner row above the ready list, no link. Name comes from
  `PendingTenantProvision::company_name` for pending rows, since the
  `Tenant` record (and thus `name`/`initials`) does not exist yet.
- `pendingTenants` with status `failed` — error row showing `error`, with a
  "Try again" link to `tenants.create`.

**Polling fallback.** Wrap the in-progress block in `wire:poll.5s="refreshTenants"`,
rendered only while something is in progress. Without it, a Reverb outage, a
failed websocket auth, or a broadcast that fires while the user is off-page
leaves a permanent spinner — the websocket becomes a single point of failure
for the entire point of the feature. The poll self-terminates once nothing
is pending.

Broadcast channel `user.{userId}` in `routes/channels.php` **already exists**
(`fn ($user, $userId) => (int) $user->id === $userId`) and is currently unused
anywhere — this is its first consumer. Two new events in a new
`app/Events/Tenancy/` namespace, following the `MessageSent` shape:

```php
class TenantProvisioned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Tenant $tenant, public readonly string|int $ownerId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->ownerId}")];
    }

    public function broadcastAs(): string
    {
        return 'tenant.provisioned';
    }

    public function broadcastWith(): array
    {
        return ['domain' => $this->tenant->id, 'name' => $this->tenant->name];
    }
}
```

`TenantProvisioningFailed` mirrors it with `broadcastAs('tenant.provisioning-failed')`.

Note the channel is keyed on `CentralUser->id` while `PendingTenantProvision`
is keyed on `global_id` — `TenantProvisioningFailed` is dispatched from
`ProvisionTenant::failed()` where only `global_id` is on hand, so resolve the
id (`CentralUser::where('global_id', ...)->value('id')`) before broadcasting.

Frontend needs no new JS: `resources/js/central.js` already configures
`window.Echo`, and Livewire's `#[On('echo-private:...')]` handles
subscription itself, same as the existing chat feature.

`GetTenantsByGlobalId` (cached query action, currently called by nothing) is
deliberately **not** wired in — it caches under `user:{globalId}` tags via
`global_cache()`, and this component needs live reads the instant
provisioning completes. Using it would mean threading cache invalidation
into `MakeFirstUserAdmin` for no gain over the direct query.

## 8. `WebhookController` — dispatch, don't provision inline

`app/Http/Controllers/Billing/WebhookController.php`

Replace `ensureTenantExists()` (which calls `CreatesTenant::handle()`
synchronously inside the webhook request) with a `ProvisionTenant::dispatch()`
guarded by the same "already exists" check. Two reasons:

- Stripe times out webhook responses (~10s) and retries on timeout. Running
  database creation, migration and seeding inline invites timeout-driven
  retry storms.
- It makes `ProvisionTenant` genuinely the single entry point, so the
  `ShouldBeUnique` guard and the `failed()` handler cover this path too —
  today a webhook-path failure has no failure handling whatsoever.

The `CreatesTenant` contract binding stays (it is `CreateTenant`'s
interface and still used inside `ProvisionTenant`), but `WebhookController`
no longer depends on it directly.

## 9. `DevCheckout` — repair it and route it through `ProvisionTenant`

`app/Actions/Billing/Subscription/DevCheckout.php`

Confirmed intent: a local shortcut to provision a tenant without clicking
through the Stripe UI. It has to change here for two reasons.

**It is broken today.** It reads `$request->session()->get('tenant_checkout_data')`,
and nothing anywhere writes that key — the wizard's final step
(`Plan::register()`) redirects to `checkout.subscription` with query
parameters, not session state. It also has no route, so it is currently
unreachable dead code that would return `to_route('tenants.create')` if it
ever were reached.

**It would diverge from production under the new design.** Calling
`CreateTenant::run()` directly skips the pending row (no spinner), skips
`ReconcileTenantSubscription` (tenant with no subscription), and no longer
even triggers `MakeFirstUserAdmin` from the same place production does. A
dev shortcut that exercises a path production never takes is worse than no
shortcut — it hides exactly the bugs it should surface.

Rewrite to mirror `StripeCheckout`'s controller signature and dispatch the
same job production dispatches, with no Stripe subscription attached:

```php
class DevCheckout
{
    use AsAction;

    public function handle(CheckoutData $data): RedirectResponse
    {
        PendingTenantProvision::updateOrCreate(
            ['domain' => $data->domain],
            [
                'company_name' => $data->company_name,
                'global_id' => $data->global_id,
                'status' => 'provisioning',
            ],
        );

        ProvisionTenant::dispatch($data, '', null, GetAuthenticatedUser::run()->id);

        return to_route('tenants.mine')->with('success', 'Your tenant is being set up.');
    }

    public function asController(CheckoutData $data): RedirectResponse
    {
        return $this->handle($data);
    }
}
```

`ProvisionTenant` already skips reconciliation when `$stripeSubscriptionId`
is null, so no branching is needed inside it. The dev flow then exercises the
real pending row, the real queue job, the real pipeline, the real broadcast
and the real spinner — everything except Stripe.

Route it behind an environment guard so it can never exist in production:

```php
if (app()->isLocal()) {
    Route::get('checkout/subscription/dev', DevCheckout::class)->name('checkout.subscription.dev');
}
```

Add a link to it from the wizard's `Plan` step, visible under the same
`@if(app()->isLocal())` guard, carrying the same query parameters
`Plan::register()` already builds for `checkout.subscription`.

Security note: this route provisions a paid resource with no payment. The
environment guard is the only thing preventing that, so it must wrap the
route registration itself — not merely hide the button — and there should be
a test asserting the route is absent outside `local`.

Also drop `DevCheckout`'s `extends BaseCheckout` and the now-meaningless
`failureRoute()`/`successRoute()` overrides (`successRoute()` returns a route
*name* while `StripeCheckout::successRoute()` returns a `route()` *URL* —
the base class's contract is already inconsistent between the two subclasses
and nothing consumes either method).

## 10. New command: stalled-provision cleanup

`app/Console/Commands/PruneStalledTenantProvisions.php`, mirroring
`PruneOrphanedStripeCustomers` in shape and options:

```php
#[Description('Alert on tenant provisioning attempts that never completed, and prune abandoned domain reservations')]
#[Signature('tenancy:prune-stalled-provisions
                {--hours=2 : Grace period since the pending provision was created}
                {--dry-run : Report what would be flagged or pruned without acting}')]
class PruneStalledTenantProvisions extends Command
{
    public function handle(): void
    {
        $cutoff = now()->subHours((int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');

        // Abandoned reservations: user never completed checkout. Expected
        // garbage, not an incident — delete quietly so the domain frees up.
        $abandoned = PendingTenantProvision::query()
            ->where('status', 'reserved')
            ->where('created_at', '<', $cutoff);

        if ($dryRun) {
            $this->line("Would release {$abandoned->count()} abandoned domain reservations");
        } else {
            $abandoned->delete();
        }

        // Paid but never finished, and not already marked failed: real incident.
        PendingTenantProvision::query()
            ->where('status', 'provisioning')
            ->where('created_at', '<', $cutoff)
            ->each(function (PendingTenantProvision $pending) use ($dryRun) {
                if ($dryRun) {
                    $this->line("Would flag stalled provision {$pending->domain}");

                    return;
                }

                Log::warning('Tenant provisioning stalled', [
                    'domain' => $pending->domain,
                    'global_id' => $pending->global_id,
                    'created_at' => $pending->created_at,
                ]);
            });
    }
}
```

Scheduled alongside the existing prune in `routes/console.php`:
`Schedule::command('tenancy:prune-stalled-provisions')->hourly();`

Scope: **log/alert only** for paid-but-stalled, matching the safe-minimum
reasoning already used by `PruneOrphanedStripeCustomers`. It does not cancel
the Stripe subscription or refund — the customer *did* pay, so touching
their money automatically is a separate, higher-stakes decision this plan
does not make. Frequent firing is a signal to investigate why
`ProvisionTenant` is failing, not to build auto-refund.

## 11. Other gaps found

Fixed by this plan:

- **No "provisioned" signal existed at all** — root cause of the redesign
  (`provisioned_at` + broadcast).
- **No failed-checkout handling** — `ProcessFailedCheckout` (item 6). Direct
  answer to "do we need `ProcessUnsuccessfulCheckout`": yes.
- **No failure path for provisioning itself** — `failed()` handlers, `failed`
  status, `TenantProvisioningFailed` broadcast, error UI (items 4, 5, 7).
- **Webhook-path tenants appeared ready before their DB existed** — the
  `provisioned_at` readiness rule (Core design decision).
- **`MakeFirstUserAdmin` raced the tenant DB pipeline with no `$tries`** —
  item 5.
- **No ownership check on the checkout session** — item 2.
- **`ValidateCheckout` 500s on a bad/expired `session_id`** — item 2.
- **Duplicate Stripe API call** between middleware and action — item 2.
- **Two users could pay for the same domain** — item 1.
- **Webhook provisioning inline risked Stripe timeout retries** — item 8.
- **No detection of stalled provisioning** — item 10.
- **`DevCheckout` was broken and would have diverged from prod** — item 9.
- **Dead code**: `app/Livewire/Tenant/Mine.php` + view (item 6); the
  commented-out `// $tenantData = CheckoutData::collect(...)` line this
  diff passes through anyway.

Closed as "will not build":

- **Webhook event-id deduplication.** Stripe delivers at-least-once, but
  after item 8 a duplicate `customer.subscription.created` is absorbed three
  times over: `ShouldBeUnique` on `ProvisionTenant` keyed by domain,
  `Cache::lock` inside `CreateTenant`, and Cashier's own upsert semantics in
  the parent handler. The cost of a duplicate delivery is one no-op job. A
  `stripe_events` table plus unique constraint would be infrastructure
  guarding nothing — not deferred, declined.

- **`StripeCheckout`'s "swap logic" comment block (lines ~36–49).** Delete
  the comment; do not build what it describes. Its premise — "the
  requirement is don't swap without going through checkout" — is already
  contradicted by shipped code: `app/Filament/TenantAdmin/Pages/Billing.php:167`
  performs plan changes in-app with `$this->subscription->swapAndInvoice($priceId)`,
  bypassing Checkout entirely. Checkout is only ever entered for *new*
  tenants, where a new subscription is the correct outcome, so the block
  describes a problem this code path does not have. Replace it with a
  one-line comment stating that fact.

  Separate ticket, not this plan: `swapAndInvoice()` charges the stored
  payment method immediately with no confirmation step, so a plan change on
  an EU card requiring SCA/3DS authentication will fail. That is a real
  billing gap, but it lives in the Filament billing page, not in provisioning.

## Test plan

Following existing conventions in `tests/Feature/` (`CreateTenantTest.php`,
`ReconcileTenantSubscriptionTest.php`):

- `StripeCheckoutTest` (extend): reservation row created before redirect;
  second user checking out an already-reserved domain is rejected.
- `ValidateCheckoutTest` (new): expired/invalid `session_id` redirects
  instead of 500ing; `global_id` mismatch returns 403; missing metadata
  returns 403; happy path attaches the session to `$request->attributes` and
  the action receives it (guards the `createFrom` behaviour).
- `ProcessSuccessfulCheckoutTest` (new): promotes the reservation to
  `provisioning` and dispatches `ProvisionTenant` (Bus fake); does neither
  when the `Tenant` row already exists; repeat hit does not double-dispatch.
- `ProvisionTenantTest` (new): calls `CreateTenant` then
  `ReconcileTenantSubscription`; `failed()` marks the row `failed` and
  broadcasts `TenantProvisioningFailed`. Existing `CreateTenantTest` and
  `ReconcileTenantSubscriptionTest` stay as-is — those contracts do not change.
- `MakeFirstUserAdminTest` (new/extended): sets `provisioned_at`, deletes the
  pending row, broadcasts `TenantProvisioned` to the right owner id; retries
  rather than dies when the owner membership is not yet written;
  `failed()` marks the row failed.
- Webhook path test: `customer.subscription.created` with tenant metadata
  dispatches `ProvisionTenant` and does not provision inline; a second
  delivery of the same event does not queue a second job.
- Migration test: existing tenants get `provisioned_at` backfilled and do not
  render as spinners.
- `ProcessFailedCheckoutTest` (new): redirects to `tenants.create` with an
  error flash.
- `DevCheckoutTest` (new): creates the pending row and dispatches
  `ProvisionTenant` with a null subscription id; the resulting tenant reaches
  `provisioned_at` through the same pipeline as the paid path; the route is
  **not registered** outside the `local` environment.
- `PruneStalledTenantProvisionsTest` (new): deletes abandoned `reserved` rows
  past the cutoff, logs `provisioning` rows past the cutoff, leaves fresh
  rows and already-`failed` rows alone, `--dry-run` acts on nothing.
- Livewire test for `pages::tenant.mine` (new): tenant with null
  `provisioned_at` renders a spinner and no Visit link; pending row renders a
  spinner; failed row renders the error and retry link; ready tenant renders
  the normal row; the echo listener re-queries; the poll fallback resolves the
  spinner with broadcasting faked off.

Run `vendor/bin/sail artisan test --compact --filter=<Name>` per file while
building, full suite before calling it done. Format touched PHP with
`vendor/bin/sail bin pint --dirty --format agent`.

## Files touched summary

New:
- `database/migrations/..._create_pending_tenant_provisions_table.php`
- `database/migrations/..._add_provisioned_at_to_tenants_table.php` (with backfill)
- `app/Models/Central/PendingTenantProvision.php`
- `app/Actions/Tenancy/ProvisionTenant.php`
- `app/Actions/Billing/Subscription/ProcessFailedCheckout.php`
- `app/Events/Tenancy/TenantProvisioned.php`
- `app/Events/Tenancy/TenantProvisioningFailed.php`
- `app/Console/Commands/PruneStalledTenantProvisions.php`

Rewrite:
- `app/Actions/Billing/Subscription/ProcessSuccessfulCheckout.php`
- `app/Actions/Billing/Subscription/DevCheckout.php`
- `app/Jobs/MakeFirstUserAdmin.php`
- `app/Http/Middleware/ValidateCheckout.php`
- `resources/views/pages/tenant/⚡mine.blade.php`

Edit:
- `app/Actions/Billing/Subscription/StripeCheckout.php` (reserve domain, delete stale swap comment)
- `resources/views/livewire/tenant/registration/wizard/steps/plan.blade.php` (local-only dev checkout link)
- `app/Actions/Tenancy/CreateTenant.php` (drop `MakeFirstUserAdmin` dispatch)
- `app/Providers/TenancyServiceProvider.php` (append to `TenantCreated` pipeline)
- `app/Http/Controllers/Billing/WebhookController.php` (dispatch instead of inline)
- `app/Livewire/Tenant/Registration/Steps/TechnicalSetup.php` (domain uniqueness)
- `routes/web.php` (rebind `checkout.tenant.failed`, add local-only dev checkout route)
- `routes/console.php` (schedule prune command)

Delete:
- `app/Livewire/Tenant/Mine.php` + `resources/views/livewire/tenant/mine.blade.php`

Unchanged (verified compatible):
- `CreateTenantDomain`, `AddTenantOwner`, `ReconcileTenantSubscription`,
  `CreatesTenant` contract.

## Rules to record after implementation

Per `.claude/rules/`, `tenant-provisioning.md` needs updating once this
lands — the "two racing paths" framing changes (both paths now funnel
through one queued `ProvisionTenant`), and two new non-obvious invariants
appear: readiness is `provisioned_at`, never "a `Tenant` row exists"; and
`MakeFirstUserAdmin` must stay last in the `TenantCreated` pipeline because
it is the sole emitter of the done-signal.

---
paths:
  - 'src/{Events,Listeners,Observers}/**'
  - 'src/Notifications/**'
---
# Events Listeners Observers

## Domain event conventions
Every event under src/Events carries scalars (ids) alongside any model — SerializesModels re-queries on unserialize, which fails for a deleted model and resolves against the wrong connection for a tenant-scoped one. Anything dispatched from inside a transaction needs ShouldDispatchAfterCommit; event() fires immediately otherwise and a queued listener can read a row that hasn't committed. Listeners are unordered — never encode a sequence across two of them; work core must do stays in an action the code path calls directly, events are for reactions only. Core registers its own listeners with an explicit Event::listen() map in NumerosisServiceProvider::registerEventListeners() (plus one in BillingServiceProvider) since Laravel's auto-discovery never scans a package's src/.

"Scalars" includes backed enums. `MemberJoined`/`MemberRemoved` carry `Enums\Tenancy\MembershipRole` and `InvitationCreated`/`InvitationAccepted` carry an `int` id beside the model. An enum round-trips a queued payload by value with no re-query, so it carries none of the risk a model does, and it stops each listener from repeating the string `'owner'`. The rule is against *models* without an accompanying id, not against value objects.

Events\Auth\UserAccountDeleting is the exception that proves the scalars rule: it carries the CentralUser deliberately so a listener can read $user->tenants before the delete, which only works synchronously. A queued listener on it unserializes after the delete committed and gets ModelNotFoundException.

## Pivot events fire only from the Syncable side
CentralUser::tenants() and Tenant::users() are both ->using(Membership::class), so attach() goes through attachUsingCustomClass() -> newPivot()->save() and Membership's model events fire either way. Stancl's sync does not: Membership extends TenantPivot, whose boot() hooks saved -> triggerSyncEvent() **only when $pivot->pivotParent instanceof Syncable**. CentralUser is (via ResourceSyncing); Tenant is not. So $user->tenants()->attach($tenant) reaches stancl's queued UpdateSyncedResource and $tenant->users()->attach($globalId) does not — a difference invisible at the call site and, in a test, the difference between Queue::fake() being load-bearing and being decorative. A raw DB::table('memberships')->insert() bypasses everything, observer included, silently.

## The membership -> tenant-user-row invariant, and why AddTenantOwner writes it itself
Three paths create the tenant-side users row, all through Actions\Tenancy\EnsureTenantUserExists, which is firstOrCreate-idempotent on global_id and wraps the create in withoutEvents() to stop the tenant User's own ResourceSyncing from firing a SyncedResourceSaved back at central — the same guard stancl's UpdateSyncedResource::updateResourceInTenantDatabases() uses:

1. MembershipObserver::created() -> SyncTenantUserForMembership, synchronous, but **only when Tenant::isProvisioned()** — before provisioned_at is set the tenant database may not exist.
2. AddTenantOwner and AcceptInvitation call it directly, synchronously, because a login follows immediately in both.
3. Listeners\Tenancy\BackfillTenantUsers, queued, on TenantProvisioned — the sweep for anything attached during the provisioning window.

AddTenantOwner's direct call is not redundant with (1) or (3), and deleting it breaks provisioning outright: it runs as a `numerosis.tenancy.provisioning.steps` entry, so provisioned_at is null and the observer skips, and FinalizeTenantProvisioning::handle() runs PromoteFirstUserToAdmin **before** MarkTenantProvisioned — so TenantProvisioned, and therefore BackfillTenantUsers, has not fired yet when promotion reads the tenant's users and throws NoPromotableUser. Stancl's queued sync is not a fallback either: the provisioning chain is Bus::chain(...)->onQueue('provisioning') while UpdateSyncedResource lands on `default`, two queues with no ordering between them. The whole class of bug is invisible in tests, because TestCase sets queue.default = 'sync' and every ShouldQueue listener runs inline. tests/Feature/Actions/Tenancy/AddTenantOwnerTest.php pins it with Queue::fake() — note the fake has to come *after* Tenant::factory()->create(), which queues the tenant's own database-creation jobs.

## One dispatch site per fact
Events\Billing\SubscriptionPlanChanged is dispatched only from WebhookController::handleCustomerSubscriptionUpdated(), never from SwapSubscriptionPlan — the swap produces that webhook, so dispatching in both places fires it twice for one change. The handler reads the local subscriptions.stripe_price *before* calling Cashier's parent handler, which overwrites it from the payload; a null previous price means the row is being created, not changed.

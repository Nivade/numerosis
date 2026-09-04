# Inline Checkout

- **`subscriptions.subscribable_id` holds the owner's *primary* key, never its
  `global_id` — and getting that wrong is silent in one direction and
  intermittent in the other.** `Subscription::subscribable()` is a plain
  `morphTo()`, so its owner key is whatever each type's `getKeyName()` returns:
  `users.id` for `CentralUser`, `tenants.id` for `Tenant`. That is also what
  Cashier's own `newSubscription()` writes, since it goes through
  `Billable::subscriptions()`'s `morphMany`. `global_id` is this package's
  cross-database *identity* (what `ResolveSetupIntent` and
  `TenantRegistrationData` carry); it is not this column's key, and the two are
  easy to conflate because almost everything else about a `CentralUser`
  crossing a database boundary uses `global_id`.

  Put a `global_id` here and `$subscription->subscribable`,
  `$subscription->owner` and `$user->subscriptions()` all resolve to
  **nothing** — no error, because `Subscription::$with` eager-loads
  `subscribable` and a miss is just a null. `tests/Feature/Actions/Billing/Subscriptions/LinkSubscriptionToTenantTest`
  did exactly this for months and passed, because it only ever asserted the
  row had been re-pointed at the tenant afterwards.

  The loud half is rarer and looks unrelated: `subscribable_id` is a `string`
  column, but `CentralUser::getKeyType()` is `'int'` (its key genuinely is),
  so `Relation::whereInMethod()` picks **`whereIntegerInRaw`**, which casts
  every value. Harmless for `"1"`; for a UUID shaped like
  `3e106911-ec68-484d-95e1-7aabbb8a0766` — a valid PHP float-string, roughly 1
  in 250 UUIDs — PHP 8.5 raises `The float-string "…" is not representable as
  an int, cast occurred`, from `Query\Builder::whereIntegerInRaw()`, in
  whichever test happened to draw that UUID. It reads as a random flake in an
  unrelated billing test and disappears on re-run.

  `tests/Feature/Models/Central/SubscriptionOwnerTest` now pins the round trip
  for both billables and was verified to fail against the old fixture shape.
  **`Tenant` is unaffected either way** — its key *is* `tenants.id` and its
  `getKeyType()` is `'string'` (stancl's `GeneratesIds::getKeyType()` returns
  `'string'` whenever a `UniqueIdentifierGenerator` is bound), which is why
  only the central-user side was ever wrong.

> **Naming note (2026-07-31):** registration wizard's `Payment` step no
> longer owns `subscribe()`/`confirmed()`/`settle()`/`$pendingDomain` — only
> resolves reserved domain, embeds `Nvade\Numerosis\Livewire\Billing\Checkout`, now
> single implementation of protocol (standalone at
> `/checkout/{domain}`, embedded in wizard). Bullets below written against
> `Payment::x()` describe `Checkout::x()` today; reasoning unchanged.

- **`Cashier::findBillable($id)` only consults `config('cashier.model')`,
  can't answer "the tenant" specifically — both `Tenant` and
  `CentralUser` billable.** `WebhookController` used to repeat
  `Cashier::findBillable($id)` + `instanceof Tenant` at four sites (nullable-id
  guard written slightly differently each time) plus one site reaching for
  `CentralUser::where('stripe_id', …)` instead. Centralized in
  `Nvade\Numerosis\Actions\Billing\FindTenantByStripeCustomer::run(?string $customerId): ?Tenant`.
- **Stripe customer is single source of truth for billing
  address — no local column, no override.** `SyncBillingAddress` (writes
  address + optional VAT from checkout) and `AddVatNumber` (VAT-only,
  post-signup) both read country back off live Stripe customer rather
  than caching locally, both wrap `Cashier::stripe()->customers`
  calls same way (`report($e)` + rethrow as `BillingAddressUnavailable`)
  so Stripe outage reaches user as checkout-page copy, not 500.

- **For redirect/bank-auth payment method (iDEAL, Bancontact), Stripe never
  updates `$setupIntent->payment_method` — confirmed during 2026-07-30
  incident (two stuck tenants: `blaap`, `banaan`).** Keeps pointing at
  original, permanently-unattached `ideal`-typed PaymentMethod forever.
  Stripe instead creates *separate*, already-attached `sepa_debit`
  PaymentMethod, reachable only via SetupAttempt's own
  `payment_method_details.{type}.generated_sepa_debit`. Cards/Link attach
  `payment_method` synchronously, don't hit this.
  `Nvade\Numerosis\Actions\Billing\Checkout\ResolveAttachedPaymentMethod` finds
  reusable PaymentMethod for both cases.
- **`ResolveSetupIntent` is the one ownership check shared by
  `Checkout::subscribe()` and redirect-return route
  (`CompleteRedirectCheckout`).** Fetches `PendingTenantProvision` by
  `stripe_setup_intent_id`, confirms resolved billable is `CentralUser`
  matching `$pending->global_id` (checkout only ever runs before tenant
  exists, so never `Tenant`), then confirms Stripe customer on
  SetupIntent matches billable's own `stripe_id`. Its
  `hasLiveSubscription()` guard fails closed: subscription id with no
  matching local row, or any status but `canceled`, counts as still live —
  `CreateInlineSubscription` sole writer of `stripe_subscription_id`
  this early, so missing row means "created but not yet synced," not "safe
  to recreate."
- **`CreateInlineSubscription` takes explicit `$billable` override since
  `WebhookController::handlePaymentMethodAttached` runs with no session at
  all** — `BillableResolver::resolve()` reads current auth context, finds
  nobody there. Every other caller passes nothing, resolves from
  authenticated request. On `IncompletePayment` (3DS still pending), stamps
  `pending->stripe_subscription_id` from already-created Stripe
  subscription before rethrowing — not just happy path — so
  `ResolveSetupIntent`'s replay guard above also covers window where
  challenge still pending.
- **`FinalizeCheckoutSubscription` extracts "create subscription, then
  settle" pair so `CompleteRedirectCheckout` (sync return-request path) and
  `WebhookController::handlePaymentMethodAttached` (deferred path) run
  identical sequence instead of two copies drifting apart** — same reasoning
  as `ResolveSetupIntent` being shared. `Nvade\Numerosis\Livewire\Billing\Checkout`'s own
  `subscribe()`/`settle()` deliberately separate third copy for
  non-redirect (card/Link) path, not touched by this. Doesn't catch
  `IncompletePayment` itself — each caller decides what that means for own
  context (redirect response vs log-and-acknowledge, since webhook
  must not surface error status back to Stripe).
- **`CompleteRedirectCheckout` is landing point for redirect-flavoured
  method bouncing back from customer's bank; cards never reach it** —
  they confirm inline via `Checkout::subscribe()` directly. Its "payment
  method not yet attached, defer" branch exists because cards always reach
  here with `customer` already set, while redirect method's reusable
  PaymentMethod may not have finished attaching — forcing it used to crash
  with `"PaymentMethods of type 'ideal' cannot be saved to customers."`;
  `WebhookController::handlePaymentMethodAttached` finishes same
  checkout once Stripe's attach completes. Also clears
  `session('registration.wizard_state')` on success — same key
  `Checkout::settle()` clears on own path — since this route can reach
  "registration finished" without ever calling `settle()`.

- **Public Livewire property is client input, checkout component has
  two that decide what gets provisioned.** `$pendingDomain` is what
  `settle()` looks reservation up by, so while writable any user
  could point it at somebody else's `pending_tenant_provisions` row and have
  `SettleCheckout` stamp *their* `stripe_subscription_id` onto it. Ownership
  itself survived — `AddTenantOwner` (a provisioning step, see
  tenant-provisioning.md) resolves owner from `$registration->global_id`,
  read off pending row, not from payer —
  so damage was cross-user state corruption rather than takeover. Now
  `#[Locked]` *and* re-checked in `settle()` against authenticated
  `CentralUser`, because lock is Livewire-level guarantee and
  ownership rule is domain one; `ResolveSetupIntent` makes same check on
  way in. Same bug `StartCheckoutRequest::authorize()` was
  written to close ("previously accepted any global_id in query string"),
  reintroduced on path that does not go through FormRequest — real
  lesson: **Livewire wizard bypasses every rule in
  `StartCheckoutRequest`**, so anything that request validates has to be
  enforced again in `Plan`/`Payment` or in action underneath.

- **`stripe_status` on local `subscriptions` row stale for exactly
  moment `confirmed()` runs.** 3DS challenge resolves in browser,
  `customer.subscription.updated` hasn't landed yet, so row still says
  `incomplete` — value it was created with. Any check written against
  local row therefore has to either sync first (`syncStripeStatus()`, what
  `confirmed()` now does) or accept reading pre-challenge
  state. Original guard rejected only `incomplete_expired`, status
  Stripe doesn't reach until ~23h after abandoned challenge, so every
  genuinely-unpaid state passed and provisioned tenant. Guards on payment
  state must be allowlists (`active`/`trialing`, matching
  `SettleCheckout::$settled`), never denylists.

- **`findBySlug()` is one choke point every checkout path shares, slug
  reaching it is client input.** Unscoped, so retired plan
  (`payment_plans.available = false`, or `archived` in config repository)
  stayed purchasable at old price by anyone remembering slug —
  `Plan` validates `payment_plan` as only `required|string`,
  `StartCheckoutRequest`'s `exists:central.payment_plans,slug` proves
  existence, not availability. `PaymentPlanRepository::findBySlug()` now
  contractually required to exclude retired plans, `findAnyBySlug()` for
  admin/reporting paths that must still describe subscriptions sitting on
  one. Both implementations must stay in step.

- **Every refusal `StartSubscriptionCheckout` throws is `DomainException`
  whose message is customer copy, `Plan::register()` used to catch none of
  them.** `TooManyUnpaidTenants` says "complete payment on existing
  workspace before creating another" and user saw 500. Livewire renders
  uncaught exception as dead button, reads as broken form rather
  than refused request. `Plan` now catches `ShowsMessageToUser` into
  `$checkoutError` — typed to interface, never `Throwable`, per
  `.ai/rules/exception-handling.md`. Any new step calling action
  directly needs same catch.

- **`Payment`'s Livewire alias is `tenant.registration.steps.payment`, not
  `payment` like every sibling step.** `CompanyInfo`/`TechnicalSetup`/`Plan`
  normalize to short kebab aliases; `Payment` does not, because Cashier's
  published `resources/views/vendor/cashier/payment.blade.php` already holds
  that name. Production unaffected — spatie's wizard derives step names
  through same resolver, so they agree — but test hand-writing
  `allStepNames` gets `Unable to find component: [payment]`, reads like
  missing component but is name collision. Resolve rather than hardcode:
  `app('livewire.finder')->normalizeName(Payment::class)`.

- **Ownership is not identity: proving resolved row belongs to caller
  is different question from proving it's row this component mounted
  for.** `Checkout::subscribe($setupIntentId)` resolved pending row from
  client-supplied `$setupIntentId` (via `ResolveSetupIntent`, checks
  `global_id`), created subscription against *that* row, then called
  `settle()` — which re-reads row by `$this->pendingDomain`. Both checks
  pass for one user holding two reservations, so mounting `/checkout/domain-b`
  and calling `subscribe()` with domain-a's SetupIntent provisioned tenant B
  from A's subscription, while A kept own `stripe_subscription_id` stamp and
  stayed resumable off same subscription: **two tenants, one payment**. The
  `#[Locked]` on `$pendingDomain` doesn't help — nothing tampered with; two
  legitimately-owned identifiers simply allowed to disagree. Fixed by
  asserting `$resolved->pending->domain === $this->pendingDomain` before
  anything charged. Any future method taking id *and* reading component
  state needs same equality check, not just ownership check.

- **`InlineCheckoutGateway::begin()`'s update scoped by `global_id` as well
  as `domain`,** even though only caller (`StartSubscriptionCheckout`) runs
  `ReserveTenantDomain` first and that already refuses domain claimed by
  someone else. Gateway doesn't enforce that itself, unscoped
  `where('domain', …)->update([...'stripe_setup_intent_id' => …])` would
  overwrite stranger's stored SetupIntent — leaving rightful owner
  resuming checkout against Stripe customer that isn't theirs, which
  `ResolveSetupIntent` then refuses outright, locking them out of own
  reservation. Cheap belt-and-braces on write whose correctness currently
  depends entirely on call order.

- **Suspension must ask "is anything still valid?", not "did something just
  end".** `WebhookController::handleCustomerSubscriptionDeleted` suspended
  tenant on *any* subscription deletion for that customer. Cashier already
  deleted cancelled subscription's local row by time override runs,
  so honest test is `$tenant->subscriptions()->get()->contains(fn ($s) =>
  $s->valid())` — anything else locks customer out of workspace they're
  still paying for moment one of several subscriptions ends. Same shape as
  allowlist-not-denylist rule above: enumerate what grants access, never
  what removes it.
- **`SuspendTenant`/`RestoreTenant` set `suspended_at`; never delete
  anything.** `EnsureTenantSubscriptionActive` actually refuses panel
  access, routes owner to `⚡suspended` once column set — data
  stays intact. `tenancy:prune-orphaned-databases` separate, explicit
  cleanup path for tenants that stay suspended, never pay. Both actions
  idempotent (no-op if already in target state), what stops
  webhook retry — or two events for same subscription — from
  re-sending notification or clobbering `suspended_at`.

- **Provisioning deliberately not gated on payment, so guards above are
  only thing standing between unpaid checkout and real tenant.**
  `SettleCheckout` queues `ProvisionTenant` whether or not subscription
  settled — only varies pending row's status
  (`Provisioning` vs `AwaitingPayment`) — because trials collect zero money
  upfront, gating authorised-but-settling debit would be stricter than
  deliberate 14-day unpaid trial. Correct, but means bug in
  `confirmed()`/`settle()` provisions rather than merely mislabels. See
  `.claude/plans/archive/custom-checkout.md`, "Provisioning and settlement".

- **`ResumeCheckout` reloads `PendingTenantProvision` by domain, reuses
  stored `stripe_setup_intent_id`** so refresh or direct
  `/checkout/{domain}` visit resumes rather than bounces to start —
  `InlineCheckoutGateway::begin()` is what persists that id in first
  place. Same ownership check as `ResolveSetupIntent`.
- **`StartLocalCheckout` deliberately takes same route as paid
  flow** — same pending row, same queued `ProvisionTenant`, same broadcast —
  so dev shortcut exercises production's code path rather than
  parallel one; only difference is no subscription gets attached. Always
  uses `LocalCheckoutGateway` directly, never container-bound
  `CheckoutGateway`, so dev route stays local regardless of what
  production has configured — route itself gated by
  `app()->isLocal()`, since this provisions paid resource for free.
- **`StartSubscriptionCheckout`'s quota/policy checks only fire on paths
  where they mean something.** `PlanPolicy::assertEligible()` no-ops for
  anything but `Tenant` — brand-new registration has no seats to count
  yet, real check is `SwapSubscriptionPlan` changing existing
  tenant's plan. `UnpaidTenantQuota::assertAvailable()` only runs for
  `CentralUser` billable — existing `Tenant` billable (plan swap) is
  already counted or not by definition. `UnpaidTenantQuota` exists at all
  because provisioning happens before settlement (14-day trial collects
  zero money upfront, same as authorised-but-still-settling payment) —
  without cap that's free tenant-database faucet.

## Known gap — fixed

- **Enabling module now runs its migrations.** Fixed in `549223e` (module
  marketplace rewrite). `Nvade\Numerosis\Actions\Modules\PurchaseModule::handle()`
  dispatches `MigrateModules::dispatch($tenant, $slug)` right after
  `RecordModulePurchase::run()` — no longer dead code, no longer missing
  caller. `Nvade\Numerosis\Jobs\RollbackModules` is uninstall-side counterpart,
  documented on `Nvade\Numerosis\Actions\Modules\CancelModule` but deliberately **not**
  called from it: `CancelModule` stops billing, disables module
  without dropping its tenant rows, by design — dropping data separate,
  explicitly-confirmed operation. `Nvade\Numerosis\Concerns\InteractsWithTenantModules`
  remains orphaned (its consumer was clients module, since removed) and
  still only mechanism for registering Filament plugin conditionally
  on module being enabled — that part of gap unchanged.
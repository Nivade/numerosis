# Audit remediation — 80-commit review

**Status: executed 2026-09-15**, on branch
`fix/audit-remediation-eighty-commits`, in seven commits (one per tier, tier 7
alone as required). Five items were not done and are listed at the bottom
under "Not done, and why", with the evidence: several findings had already
been fixed or named code since deleted, and D5 could not be honoured as
written. Written 2026-09-15 from an eight-batch review of
`f6f1251..1d8cc8c` (80 commits, 1467 files), rewritten the same day to carry
every finding rather than an ordered head. 67 items in seven tiers, ordered by
importance across the whole set, not within batches.

Every item was re-verified against `HEAD` after the batch agents reported.
Findings that were already fixed, or that named files since deleted, are listed
at the end under "Verified fixed or stale" with the evidence — they are not
work, and they are recorded so the next reader does not re-raise them.

Seven decisions, all settled 2026-09-15 and recorded under "Decisions". No
open questions block execution.

Tier order is the execution order. Tiers 1 and 2 change runtime behaviour;
tier 7 is a formatting sweep that must land alone.

---

## Tier 1 — Silent wrong behaviour

A wrong answer with no error. Everything else in this plan is downstream of
these.

1. **`SwapSubscriptionPlan` nulls the plan on an unknown slug.**
   `src/Actions/Billing/Subscriptions/SwapSubscriptionPlan.php:37` —
   `Numerosis::model(PaymentPlan::class)::where('slug', $to->slug())->value('id')`
   returns `null` for a slug that does not resolve and writes it straight to
   `payment_plan_id`. Move the lookup behind `PaymentPlanRepository` and fail
   on a miss. **D1 open.**

2. **Plan cards render blank for any host plan.**
   `resources/views/components/billing/plan-card.blade.php:105,182` read
   `{{ $plan->description }}` as a property; `Contracts\Billing\Plan` declares
   no `description`. **D2 settled: make the seam real** — add
   `description(): ?string` to the contract, implement on `PaymentPlan`, change
   both reads to `$plan->description()`.

3. **`featuresFor()` silently returns nothing for a host plan.**
   `src/Services/Billing/EloquentPaymentPlanRepository.php:192-196` returns an
   empty `Collection` for any `Plan` that is not a `PaymentPlan`, so a host
   plan shows a featureless card rather than an error. Per D2, throw.

4. **`ProvisionTenant::now()` reports success having run nothing.**
   `src/Actions/Tenancy/ProvisionTenant.php:47-51` returns silently when
   `claimFor()` yields `null`. `tenancy:provision --sync` then prints
   `Provisioned [slug].`, against the contract docblock and `docs/extending.md`
   ("a failure throws at the call site"). Throw, or report the unclaimed case.

5. **Two static memos outlive their request.**
   `src/Actions/Queries/GetTenantsByGlobalId` and
   `src/Services/Billing/EloquentPaymentPlanRepository::$memo` are flushed only
   in provider boot and by an explicit forget action. Queue workers and Octane
   do not re-register providers per request, so a plan edit on one worker
   leaves another stale for the worker's life. **D3 settled: drop both.**

6. **The tenant query loses ordering and discards rows silently.**
   `src/Actions/Queries/GetTenantsByGlobalId.php:66` — `whereIn('id', $ids)`
   drops the ordering the old `tenants()->get()` carried, and
   `array_filter(… instanceof Tenant)` throws rows away rather than failing.
   Land with item 5.

7. **`GlobalCache`'s memo ignores a changed default store.**
   `src/Cache/GlobalCache.php:47` keys on the container and
   `numerosis.cache.store` but not `cache.default`, so with `store` null a
   changed default never takes effect — the defect `$resolvedName` exists to
   prevent.

8. **Invitation ownership check depends on an eager load only one caller does.**
   `src/Policies/Invitations/InvitationPolicy.php:49-52` reads `invitedBy`.
   The Blade path loads it; `DestroyInvitationController`'s route-bound model
   lazy-loads and fatals under `preventLazyLoading`. Load it in the policy.

9. **Wizard state rebuilt by string key, so a typo yields no contribution.**
   `src/Livewire/Tenant/Registration/Steps/Plan.php:151` and
   `TechnicalSetup.php:135` call `self::contribute(['payment_plan' => …])`
   against a static designed for dehydrated wizard state. A mistyped key gives
   `null` and the contribution vanishes. Give the contribution a typed path the
   live component can call.

10. **`stepProviding()` returns an empty string on no match.**
    `src/Livewire/Tenant/Registration/RegistrationState.php:131` — the
    exception then reads "`[]` was not completed" and `showStep('')` misfires.
    Throw, or name the fallback step.

11. **Half the identity-step derivation is hardcoded.**
    `RegistrationState.php:95-112` hardcodes `'name'`/`'domain'` while
    `stepProviding()` derives its step from `tenantIdentityStateKeys()`. A
    replacement identity step with different keys breaks the hardcoded half.

---

## Tier 2 — Seams that cannot do what they claim

Dead abstraction and advertised extension points that do not extend.

12. **The billable resolver's two-shape discrimination does not exist.**
    `src/Services/Billing/TenantOrUserBillableResolver.php:27` —
    `$user instanceof BillableUser || $user instanceof Subscribable`, but
    `BillableUser extends CentralUserModel, Subscribable`
    (`src/Contracts/Billing/BillableUser.php:24`), so arm one is unreachable.
    `Contracts/Billing/BillableResolver.php:15` mirrors it in the return type
    with a docblock describing a distinction the type cannot make. **D4 open.**

13. **The checkout-region surface is an undocumented extension point.**
    `src/Actions/Billing/Checkout/ResolveCheckoutRegion.php:24` is a stub whose
    `handle()` returns `null` unconditionally — its own docblock says "No
    region lookup is wired in" — so everything downstream always takes the
    default path: `Checkout::$detectedCountry`, `$paymentMethodOrder`,
    `resolvePaymentMethodOrder()` (`src/Livewire/Billing/Checkout.php:109,313`),
    the `billing.payment_methods.{default_order,regions}` config block, the
    `resources/lang/en/billing.php` strings and the
    `resources/js/stripe-checkout.js` branch. **D7 settled: keep it as a host
    extension point and give it the documentation it never had.** That means:
    bind `ResolveCheckoutRegion` through the container so a host can swap it
    (it is currently reached statically via `AsAction`'s `::run()`); document
    the seam in `docs/extending.md` alongside the other swappable behaviours,
    stating that core ships a null implementation on purpose; document the
    `billing.payment_methods` config block per item 39; and add a test binding
    a fake region resolver that proves the order actually changes, so the
    default paths stop being untested. Without that test this stays dead code
    with a docs page.

14. **`Billing::fake()` is the only reason its own boundary test needs an
    exemption.** `src/Facades/Billing.php:37` is a one-line forward to
    `BillingFake::swap()`, and `tests/Feature/PackageBoundariesTest.php:104-113`
    exempts that file from the guard stopping `src/Testing/` reaching
    production code. Delete the forwarder, call `BillingFake::swap()` from
    tests, drop the exemption. Fixes item 26 at the same time.

15. **An un-register path nothing asked for.** `src/Numerosis.php:301-325`
    widened `registerRoutesUsing`/`registerMiddlewareUsing`/
    `registerExceptionsUsing` to `?Closure`; `docs/extending.md:176` documents
    `Closure`. Revert the widening rather than document it, unless a caller
    needs `null`.

16. **`CachedModel` is a final class wrapping one call.**
    `src/Cache/CachedModel.php:24` — its only member wraps `newFromBuilder()`.
    Inline it.

17. **`Tenant::owner()` runs the tenant closure against itself.**
    `src/Models/Central/Tenant.php:229` — `$this->runInTenant($this, …)` passes
    the object it is already on. Give the trait an overrideable `runHere()`, or
    default `runInTenant()` to `$this`.

18. **`Boot\UserModels` is a request-time read in a host-normalization
    directory.** `architecture-conventions.md` scopes `src/Boot/` to classes
    that validate or normalize the host application; `UserModels::current()`
    reads live tenancy state. It is also the only non-`final` class there.

19. **`HostConfig`'s `$applied` log has one consumer.**
    `src/Boot/HostConfig.php:35,58` — only `numerosis:install` reads it.
    Speculative until a second reader exists.

---

## Tier 3 — Guardrails that report green without holding

These are why several tier 1 items survived eighty commits of review.

20. **Nine `src/` rows in the PHPStan baseline.** `4d26b2c` deleted
    `@param array<string, mixed> $input`-style annotations from
    `CreateRegisteredUser`, `Authenticate`, `Subscription` and
    `FakeStripeHttpClient`, then baselined the resulting
    `missingType.iterableValue` errors. `static-analysis.md` calls a new `src/`
    entry "a real defect being papered over"; `general.md` says those
    annotations "MUST stay exactly as they are". **D5 settled: restore the
    annotations, drop the nine rows.** The same treatment applies to the 11
    further `@var` deletions in `src/Boot/HostConfig.php` and
    `src/Console/Commands/InstallNumerosisCommand.php`.

21. **The non-Eloquent plan test proves the opposite of its claim.**
    `tests/Feature/View/Components/PlanCardNonEloquentPlanTest.php` gives its
    stub `public string $description` to satisfy the view — the exact concrete
    coupling the test exists to disprove. D2 removes the need; also add the
    plan-change modal branch, since only `type="selectable"` is rendered today.

22. **A malformed docs table that its own test cannot see.**
    `docs/host-requirements.md:177` — the `livewire.temporary_file_upload.disk`
    row carries a stray leading empty cell, shifting it one column and
    rendering wrong on GitHub. `HostRequirementsTest` misses it because
    `trim($line, "| \t")` strips the empty cell before splitting. Fix the row,
    then assert a uniform column count.

23. **No arch test covers the `Boot/` / `Routing/` / flat-`Services` layout.**
    The plan that deleted `src/Support/` made enforcement its Phase 6; it never
    shipped. **D6 open.**

24. **The `->run(` guard is both too wide and too narrow.**
    `tests/Feature/ArchTest.php:236` holds (verified), but `/->run\(/` matches
    any `->run(` anywhere in `src/` and misses a line-wrapped `$tenant->run(`.
    Narrow to `->run(function` / `$tenant->run(`.

25. **The arch-test exception list grew without its rule.**
    `.ai/rules/architecture-conventions.md:36` says `ArchTest` "names the one
    remaining exception and fails on a second"; the list is now six
    (`PreservingPathTenantResolver` plus three bootstrappers).
    `.ai/rules/overview.md:31` says "Four arch tests hold all of it down"; two
    were merged into one and the Services test changed meaning.

26. **`EloquentPaymentPlanRepository` hardcodes a TTL.** Line 173 —
    `now()->addMinutes(5)` in new code, against `.ai/rules/tenant-caching.md`
    ("TTLs and the store are config, not literals … read through `CacheTtl`").
    Add `CacheTtl::popularPaymentPlanSlug()`.

27. **Two Blade files reach package classes by inline FQCN.**
    `resources/views/components/billing/plan-card.blade.php:15` and
    `order-summary.blade.php:12` carry
    `\Nvade\Numerosis\Contracts\Billing\Plan` in the `@var` after the
    `@use(PaymentPlan)` was removed and not replaced, against
    `.ai/rules/overview.md`.

28. **Concrete-class calls to `flushMemo()`.**
    `src/Actions/Cache/ForgetAvailablePaymentPlans.php:19` and
    `src/NumerosisServiceProvider.php:161` name
    `EloquentPaymentPlanRepository` directly, against
    `architecture-conventions.md`'s "never reference the concrete class
    directly". Resolved by D3 dropping the memo; verify nothing else calls it.

---

## Tier 4 — Docs that contradict the code

A host reads these. Each one is a false statement in shipped documentation.

29. **"No `class_exists()` seam is load-bearing."**
    `docs/architecture.md:44` and `docs/extending.md`'s "Optional dependencies
    core still leans on — None, currently." Both false: `composer.json`
    suggests `sentry/sentry-laravel`, `laravel/telescope` and
    `socialiteproviders/zoho`, and two seams are live
    (`src/NumerosisServiceProvider.php:453`,
    `src/Concerns/Tenancy/TagsSentryScopeWithTenant.php:22`).

30. **Directory counts, wrong in four places.** `docs/architecture.md:147-149`
    says `Actions/ 68`, `Contracts/ 27`, `Exceptions/ 20`; actual is 73 / 35 /
    21. `docs/extending.md:94` says "all 32 interfaces"; actual 35. Delete the
    counts rather than correct them — `.ai/rules/overview.md` already settled
    this for rule files ("They were wrong within three commits last time") and
    the docs never adopted it.

31. **The `src/` tree documents a directory deleted 2026-09-11.**
    `docs/architecture.md:145-187` still prints the `Support/` tree and a
    "Seven `Support` classes carry most of the surface area" table, one row per
    `Support\X`. Nothing documents `Boot/`, `Routing/`, `Cache/` or the three
    registrars.

32. **`docs/architecture.md:48`** — "needs a `Support\Compat\*` shim" dangles
    after the same move.

33. **`docs/extending.md:89`** — names `UserModelResolver`, now
    `Boot\UserModels`.

34. **Two role interfaces shipped undocumented.** `HasTenantOwner` and
    `Suspendable` are exactly the host-satisfiable shape
    `docs/extending.md:103-107` describes — `EnsureTenantSubscriptionActive:25`
    and `NotifiesTenantOwner:18` type against them — and appear in neither the
    list nor its count. This is a host-facing capability with no docs.

35. **The cache config block is undocumented.** `numerosis.cache.store`,
    `numerosis.cache.ttl.*` and `NUMEROSIS_CACHE_STORE` appear nowhere outside
    `config/numerosis.php`, though `CLAUDE.md` bills
    `docs/host-requirements.md` as covering every key `HostConfig` normalizes.
    Document them, including that `null` disables a key.

36. **`users.email` is nullable, recorded nowhere.** `User::$email` is
    `string|null` package-wide; a host's model, validation and notification
    routing must tolerate it, and `src/Livewire/Settings/Profile.php:30`
    silently substitutes `''`.

37. **The deep-fill promise has an undocumented exception.**
    `docs/host-requirements.md` says every `numerosis.*` key is filled at every
    depth from package defaults, without noting that `packageRegistered()` now
    skips the fill entirely when `configurationIsCached()` — so new defaults
    appear only after a re-cache.

38. **`numerosis.tenancy.seeder` now has two mechanisms, one documented.**
    `docs/host-requirements.md:157` describes only the projection onto
    `tenancy.seeder_parameters['--class']`; `SeedTenantDatabase` also reads the
    key directly through `resolve()`, with no `Seeder` type check, so a bad
    host value fails as a container error rather than via
    `verifyTenancyModels()`.

39. **Two host-facing surfaces documented nowhere.**
    `Numerosis::routes(withAuth:)` and the
    `billing.payment_methods.{default_order,regions}` config block. Per D7 both
    stay, so both need documenting; the config block's rows belong with the
    `ResolveCheckoutRegion` seam write-up in item 13, since neither makes sense
    without the other.

40. **No removal note for `numerosis.tenancy.provisioning.contributions`.**
    A host that published `config/numerosis.php` keeps a now-ignored key, and
    `.ai/rules/tenant-provisioning.md:63` records the consequence — a
    column-backed contribution no step declares is invisible — while
    `docs/extending.md` does not.

41. **`Tenant::owner()` no longer carries its `Membership` pivot.** It
    round-trips through `FindUserByGlobalId`. No current caller reads
    `->owner()->pivot`, but it is public API on a host-overridable model.

42. **`.ai/rules/identification-modes.md:107`** still describes
    `PreservingPathTenantResolver` as bound over `PathTenantResolver` in
    `TenancyServiceProvider::register()`. It is now a singleton with its own
    `CacheManager`, an alias and a `booting()` hook; the caching fact landed in
    `tenant-caching.md` only, and that file's globs do not cover
    `src/Services/Tenancy/**`.

43. **`.ai/rules/tenant-caching.md` documents `remember()` but not
    `flexible()`** or `CacheTtl::window()`'s `STALE_MULTIPLIER = 4`, now the
    path for both payment-plan keys — a plan edit can serve up to 4× the
    configured TTL stale.

44. **The same file's 0-TTL claim is wrong.** It says several drivers read `0`
    as forever. `Illuminate\Cache\Repository::put()` normalizes `$seconds <= 0`
    to `forget()` before any driver sees it, so a configured `0` never caches.
    Correct the rule; `src/Cache/CacheTtl.php:74` needs no change.

45. **`CHANGELOG.md:14,25`** — 0.1.0 is dated `2026-08-28` as the "first tagged
    release" and lists Filament panels and a "module marketplace", both deleted
    2026-09-03. Date it from the tag and drop the dead features.

---

## Tier 5 — Comments asserting facts that are false

46. **`src/Routing/RouteLoader.php:159-163`** — a shortened docblock preserved
    "the verify leg carries its own limiter", but this method registers only
    `one-time-password.login` and `.login.store`. No verify leg exists here.
    `general.md` requires validating preservation when shortening.

47. **`src/Contracts/Tenancy/ProvisioningStep.php:21`** —
    `{@see ProvisioningStepRecord}` names a class that exists nowhere. Point it
    at `TenantProvision::recordStep()`.

48. **`config/numerosis.php:87`** — the team-invitations comment still cites
    `InvitationResource`, a Filament artifact deleted 2026-09-03, in a file
    hosts publish.

49. **`src/Livewire/Tenant/Registration/Steps/Plan.php:79-92`** —
    `contribute()` was inserted between `cycle()`'s docblock and `cycle()`, so
    two docblocks stack on `contribute()` and the orphaned one describes a
    method it no longer sits on.

---

## Tier 6 — Design smells

Judgement calls. Each is small; none is urgent; all are real.

50. **`WebhookController` carries three separate smells.**
    `localPriceIdFor(mixed $stripeSubscriptionId)` (line 325) re-narrows its
    own parameter and queries `CentralSubscription` entirely — make it
    `?string` and a finder on the model; the Stripe status taxonomy lives twice,
    inline as `in_array($status, [...])` and as `Subscription::isSettledStatus()`;
    and the class is now edited for four unrelated reasons.

51. **`InstallNumerosisCommand`** — reflection into Composer's private
    `$missingClasses`, an inline `\Composer\Autoload\ClassLoader` FQCN where
    every sibling imports, and a class edited for every unrelated config
    concern.

52. **`src/Testing/CleansUpTenancyDatabases.php`** — nested `try/finally` at
    lines 156-162 whose inner step reads `$databases ?? []`, so control flow
    depends on whether a variable got assigned, and a `recordCentralWrites()`
    (line 223) that regex-parses SQL to detect writes.

53. **Three registrars re-declare the same static state.**
    `src/Boot/MiddlewareRegistrar.php:27`, `src/Boot/ExceptionRegistrar.php:27`
    and `src/Routing/RouteLoader.php:26` each declare
    `public static ?Closure $registerCallback` plus a registered flag, and they
    diverge: `RouteLoader::load()` sets the flag before the callback branch
    (line 40), `MiddlewareRegistrar::apply()` after (line 93),
    `ExceptionRegistrar` has none. Extract one trait.

54. **`FindUserByGlobalId.php:30`** duplicates `UserModels::current()`'s body
    verbatim. Put it on `Context` and call it from both.

55. **The three provision queries diverged.**
    `GetTenantProvisionsByGlobalId.php:24` also `keyBy('slug')`, a caller-side
    choice baked into a query whose name promises only a lookup;
    `src/Models/Central/TenantProvision.php:159` became a static model method
    where its two siblings became `Actions/Queries/*`; and its `$slug`
    parameter is passed a domain by its only caller.

56. **`MembershipObserver.php:22,51`** — the `ForgetUserTenants::run` +
    `forgetCache(...)` pair written twice verbatim.

57. **`plan-card.blade.php:24,28`** — `resolve(PaymentPlanRepository::class)`
    twice in one `@php` block. Service location in a view; resolve once.

58. **`ResolveSavedPaymentMethod.php`** — the same
    `__('…saved_payment_method_unavailable')` literal three times.

59. **`Fetch{ReusablePaymentMethods,SavedBillingDetails}.php`** — identical
    docblocks, and an optional `?Customer` whose correctness depends on the
    caller passing the same billable's customer. A customer-scoped reader would
    hold the pair as one type.

60. **`src/Events/Billing/*`** — `(string) $tenant->getTenantKey()` repeated at
    every dispatch site, and `(Tenant $tenant, string $tenantId)` travelling
    together on every billing event. The scalar-beside-model shape is mandated
    by `events-listeners-observers.md`; the repetition is not. Derive it in the
    constructor.

61. **`src/Contracts/Auth/CentralUserModel.php:18`** — untyped
    `notify($instance)`, forced by `Notifiable` but unexplained, unlike the
    sibling case at `BillableUser.php:21-22`. One line of why.

62. **`src/Models/Central/PlanFeature.php:36`** —
    `@property-read PaymentPlanFeature $pivot` is only true when loaded through
    the relation.

63. **`RouteLoader::$authRoutesEnabled`** (line 30) is mutable static state set
    by `load()` and read at route-file require time. Lower severity than the
    review suggested — it is private and now lives with the loader — but a
    second `load()` call in one process still silently reconfigures routes.

64. **`composer.json`** — `phpstan/phpstan-strict-rules` added and phpmd
    dropped without the approval the Boost guidelines require. Ratify in the
    commit message, or revert.

65. **`.claude/plans/luminous-wandering-brook.md`** keeps its harness-generated
    name. Rename to `sqlite-compatibility.md` and update the README table.

---

## Tier 7 — Comment budget

**Settled: separate commit, after tiers 1-6. Never mixed with a behaviour
phase.**

66. `general.md` caps docblocks at 5 prose lines and `//` runs at 3, allows one
    fact per comment, and rules out design history outright ("Why the code came
    to be this shape is not a comment. It belongs in the commit message"). The
    provisioning redesign reintroduced the breach at scale after the comment
    sweep had cleared it. Confirmed over cap:
    `Contracts/Tenancy/ProvisioningStep.php:28` (14 prose lines),
    `ProvisionContribution.php:21` (11), `ConfiguredSteps.php:23` (10),
    `ConsumesContributions.php:19` (9), `ContributesProvisionData.php:19` (9),
    `PersistsToProvisionColumns.php:18` (7), plus `ProvisionTenant.php:28`,
    `RunProvisioningStep.php:28`, `TenantProvisionData.php:21`,
    `TenantProvision.php:57,99`, `ProvisionTenantCommand.php:21`,
    `SeedTenantDatabase.php:27`, `AddTenantOwner.php:28`,
    `FinalizeTenantProvisioning.php:22`, `AttachVatNumber.php:12-22`,
    `EloquentPaymentPlanRepository.php:85-90`,
    `InitializeTenancyByTenantDomain.php:13`, `GlobalCache.php:20-33`,
    `InstallNumerosisCommand.php:41-63`, `CleansUpTenancyDatabases.php:10-58`
    (~45 lines), `MiddlewareRegistrar.php:28-34`, `Numerosis.php:333`,
    `HostConfig.php:32`, `Checkout.php:343`, `HasTransientState.php:18`,
    `CreatesTenant.php:20`. Over-cap `//` runs:
    `MiddlewareRegistrar.php:101-109` (9), `⚡mine.blade.php:67` (8),
    `NumerosisServiceProvider.php:133-138` (6),
    `TenancyServiceProvider.php:304` (5),
    `ResolveSavedPaymentMethod.php:26-30` (5),
    `MembershipObserver.php:22-26` (5), `config/numerosis.php:363,378,390,395`
    (4-5 each), `DefaultUnpaidTenantQuota.php:30` (4),
    `create_tenant_provisions_table.php:22` (4). Route the surplus to commit
    messages or `.ai/rules/`.

67. The same sweep should take the slop cadence `general.md` names — em dashes,
    binary contrast ("Ordering only — Stripe still decides", "Protected rather
    than private"), colon reveal, decorative bold — in the prose it is already
    rewriting. Do not open files solely for this.

---

## Decisions

**D1 — `SwapSubscriptionPlan` on an unknown slug (item 1). Settled 2026-09-15:
throw.** Resolve the plan through `PaymentPlanRepository` and throw a domain
exception when the slug misses, rather than writing `null`. The swap already
runs inside a transaction, so the throw rolls the row back. Follow
`.ai/rules/exception-handling.md`'s `DomainException` / `ShowsMessageToUser`
split when choosing the class.

**D2 — the `Plan` contract and `description` (items 2, 3, 21). Settled
2026-09-15: make the seam real.** Add `description(): ?string` to
`Contracts\Billing\Plan`, implement on `PaymentPlan`, change both card reads to
`$plan->description()`, make `featuresFor()` throw on a non-`PaymentPlan`, and
drop the test stub's `public string $description`. Breaking for host
implementers, which costs nothing today.

**D3 — the two static memos (items 5, 6, 28). Settled 2026-09-15: drop them.**
Delete `$memo`/`flushMemo()` from both classes and rely on the cache layer.
This removes the concrete-FQCN calls in item 28. Check what the cache-audit
commit measured the memos to save, and record it in the commit message.

**D4 — `BillableUser` vs `Subscribable` (item 12). Settled 2026-09-15:
collapse.** Reduce the check to `$user instanceof Subscribable`, the return
type to `?(Model&Subscribable)`, and delete the docblock's two-shape claim. No
behaviour change — the second arm already decided every case. Leave the
`BillableUser` interface itself alone; it still carries the `CentralUserModel`
half that the resolver does not need.

**D5 — the PHPStan baseline rows (item 20). Settled 2026-09-15: restore the
annotations** and drop the nine `src/` rows, plus the 11 `@var` deletions in
`HostConfig` and `InstallNumerosisCommand`. Honors `general.md` and
`static-analysis.md` as written; the baseline returns to holding no `src/`
entries.

**D6 — arch coverage for the `Boot/` / `Routing/` layout (item 23). Settled
2026-09-15: write the test.** Assert that `src/Boot/` holds only
host-normalization classes, `src/Routing/` only route loading, and
`src/Services/` stays flat. It would have caught item 18 (`UserModels` is a
request-time tenancy read sitting in `Boot/`), so expect it to fail on the
current tree — resolve item 18 in the same pass rather than seeding an
exception for it. Fold the assertions into `tests/Feature/ArchTest.php`
alongside item 24's regex narrowing rather than opening a second file.

**D7 — the checkout-region surface (items 13, 39). Settled 2026-09-15: keep it
as a host extension point.** Core keeps shipping a null region resolver; the
work is making it a seam a host can actually use. Four parts: bind
`ResolveCheckoutRegion` through the container so a host can swap it rather than
reaching it statically through `AsAction`; write it up in `docs/extending.md`
with the other swappable behaviours, saying plainly that the null return is
deliberate and means "use the default order"; document the
`billing.payment_methods` config block (item 39); and add a test that binds a
fake resolver and asserts the payment-method order changes. The test is the
part that matters — without it the default paths stay unexercised and this is
dead code with a documentation page attached.

---

## Verified fixed or stale — not work

Raised by the batch agents against older trees; checked against `HEAD` and
closed, with the evidence.

- **`DB::transaction()` on central-connection models** in
  `LoginWithSocialAccount` and `AcceptInvitation` — both now use
  `getConnection()->transaction()`.
- **`MembershipObserver` missing the commit wrap** — now
  `$membership->getConnection()->afterCommit(...)` at line 31.
- **`.ai/rules` `paths:` globs naming the deleted `src/Support/**`** —
  repointed. The remaining `src/Support` strings are deliberate historical
  prose in `overview.md`, `rector.md`, `stancl-tenancy-v4.md` and
  `package-host-bootstrap.md`.
- **`torann/geoip` in `require`** — removed. The stub it left behind is item 13.
- **`PromoteFirstUserToAdmin` calling `$tenant->run()` bare** — now wrapped in
  `$this->runInTenant()`, held down by `tests/Feature/ArchTest.php:236`.
- **Turnstile's `suggest`-to-`require` move** — `docs/architecture.md:41`
  reflects it.
- **`.claude/plans` and `docs/` citations in shipped files** —
  `resources/js`, `resources/css`, `database/seeders` and
  `MiddlewareRegistrar` are clean; the comment sweep cleared them. Only
  `config/numerosis.php:87`'s Filament reference survived, as item 48.
- **Comments naming `Support\Numerosis` / `Support\Features`** after the rename
  — none remain in `routes/`, `src/Facades/` or `packages/ui/src/`.
- **The `numerosis.social.providers` config comment** — gone.
- **`Events\Billing\PaymentSettled` dispatched from nowhere** — now dispatched
  at `WebhookController.php:362`.
- **`resources/views/components/ui/stepper.blade.php` mobile label** — the file
  no longer exists.
- **`delightful-doodling-turing.md` and `drifting-puzzling-flame.md`** — both
  archived in the 2026-09-12 re-audit.
- **`docs/features.md`'s stale Turnstile `suggest` claim** and the
  `domain-events-expansion.md` README row — both corrected.
- **`Numerosis.php`'s ten one-line delegators** — Middle Man by the baseline,
  but `docs/architecture.md` explicitly endorses the shape. Suppressed per the
  review's own rule that a documented repo standard overrides the baseline.
- **`trusted_proxies` and `MembershipsFeature` scope creep** — both landed with
  matching docs and tests.


---

## Not done, and why

Recorded 2026-09-15 at the end of execution.

- **Item 19 (`HostConfig`'s `$applied` log).** Not speculative: `numerosis:install`
  reads it, and `HostConfigTest`/`HostConfigDoctorCoverageTest` assert against it
  in nine places, which is the only coverage of "a preference is written only
  when it would change the key". Removing it would delete that coverage.
- **Item 20's second half (the 11 `@var` deletions in `HostConfig` and
  `InstallNumerosisCommand`).** Those tags were widening PHPStan's already
  correct inferred type, which is why `4d26b2c` dropped them; restoring them
  re-adds the defect. The nine baseline rows the item is really about are gone,
  eight of them by annotating with `array<array-key, mixed>` — the same type as
  a bare `array`, so it satisfies `missingType.iterableValue` without breaking
  contravariance against Fortify, Cashier, Stripe and the wizard. D5's literal
  instruction (`array<string, mixed>`) produces `method.childParameterType`
  instead; verified.
- **Item 51 (`InstallNumerosisCommand`).** The inline
  `\Composer\Autoload\ClassLoader` was already an import, and the reflection
  into `$missingClasses` is the only way to clear that cache. What is left —
  a class edited for every unrelated config concern — is a decomposition this
  plan did not scope.
- **Item 52's second half (`recordCentralWrites()` parses SQL).** The query log
  is a string; there is no structured signal to read instead.
- **Item 63 (`RouteLoader::$authRoutesEnabled`).** Left as the plan's own text
  suggests: private, living with its loader, and reconfigured only by a second
  `load()` in one process, which nothing does.

Three further items were already fixed or stale against `HEAD` and are recorded
here so they are not re-raised: item 26 (`CacheTtl::popularPaymentPlanSlug()`
exists), item 47 (`{@see ProvisioningStepRecord}` names nothing in the tree),
and item 50's status-taxonomy half (the inline `in_array($status, ...)` is an
`Enums\Billing\SubscriptionStatus` lookup now).

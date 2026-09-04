**Status: ✅ Executed.** Turned into `distributed-wiggling-aurora.md` and
implemented from there — findings #1, #7, #8, #9 fixed as described;
findings #2–#5 confirmed no-gap, no work needed; finding #6 mostly done
(4 of 5 Jobs ported), with `SeedTenantDatabase` deliberately excluded — see
that plan file and `.claude/rules/tenant-provisioning.md` for why.

1. Dead capability: LinkSubscriptionToTenant declares ShouldQueue but never queued. app/Actions/Billing/Subscriptions/LinkSubscriptionToTenant.php:19 implements ShouldQueue, yet every call site
   (ProvisionTenant::handle (), WebhookController, tests) uses ::run () — synchronous, direct handle () call. ShouldQueue only matters via ::dispatch (), never invoked here. Grep confirms zero
   LinkSubscriptionToTenant::dispatch (. Interface implies retry/backoff semantics that never apply — misleading. Fix: drop ShouldQueue, or if async retry actually wanted, dispatch it for real and
   drop the inline Cache::lock (cache lock is currently the correctness backbone since queueing never happens).

2. ProvisionTenant is the only action using job lifecycle features (jobTries, jobBackoff, jobUniqueFor, getJobUniqueId, jobFailed, ShouldBeUnique). Good example — rest of codebase doesn't reuse this
   pattern where it'd help (e.g. above).

3. No action uses asListener or asCommand. Checked — not a gap though: existing app/Listeners/* are thin, single-purpose, several deliberately avoid Laravel's handle () auto-discovery (see
   SyncTenantToStripeOnSave comment) — converting to action asListener would fight that guard. Console commands (Prune*, MigrateTenantModule, etc) are single-entrypoint CLI-only utils with real IO ($
   this->confirm, --dry-run) — skill's own guidance says keep those plain, not action candidates.

4. Controllers wired direct to actions where appropriate (routes/auth.php, routes/web.php — LogoutUser, StartSubscriptionCheckout, etc via asController). No thin-wrapper controllers found duplicating
   an action's logic instead of delegating.

5. Fakes already used reasonably — 22 of 34 action-referencing test files use ::fake ()/shouldRun ()/spy () etc. Not a gap.
6. Five plain Jobs (app/Jobs/*) duplicate what AsAction's job trait already gives free — should be Actions. FinalizeTenantProvisioning, SeedTenantDatabase, MigrateModules, RollbackModules,
   SyncTenantToStripe all hand-roll Dispatchable/Queueable/InteractsWithQueue/SerializesModels, own $tries/$backoff props, own failed () — exact shape AsAction+ShouldQueue gives via
   jobTries/jobBackoff/jobFailed (). Two of these (FinalizeTenantProvisioning, SeedTenantDatabase) sit inside the provisioning pipeline right next to ProvisionTenant (which correctly is an
   Action-as-job) and orchestrate other Actions (MarkTenantProvisioned::run (), PromoteFirstUserToAdmin::run ()) — yet aren't Actions themselves. Pipeline is half-Action, half-plain-Job for equivalent
   "single queued business op" role.

Cost of not converting: two parallel test idioms for the same concept — tests/Feature/SyncTenantToStripeTest.php uses Queue::fake () + assertPushed (SyncTenantToStripe::class), while Action-driven
queue tests elsewhere use ::shouldRun ()/::fake (). Also lose Actions' assertPushed/assertNotPushed/dispatchAfterResponse for these five for free.

Fix: move to app/Actions/, use AsAction, keep handle () as body,
rename $tries→jobTries etc, failed ()→jobFailed (). Straight mechanical port, no behavior change — ::dispatch () call sites (dispatch (new X (...)) → X::dispatch (...)) unchanged in effect. SyncTenantToStripeOnSave listener's dispatch (new SyncTenantToStripe ($
tenant)) becomes SyncTenantToStripe::dispatch ($tenant).

7. ProvisionTenant::runProvisioningSteps () bypasses Actions facade inconsistently, same method, two ways. app/Actions/Tenancy/ProvisionTenant.php:75 — first step: resolve
   ($firstStep)->handle (...). Next line, 78: resolve ($stepClass)($tenant, $data) (relies on AsController's __invoke forwarding to handle, not obvious from call site). Both
   equal $stepClass::run (...). Fix: $firstStep::run ($data->registration) / $stepClass::run ($tenant, $data) — uniform, and doesn't read like a bare callable invoke when it's actually an Action call.
8. Six Actions bound as container singleton, contradicting codebase's own documented anti-pattern. app/Providers/AppServiceProvider.php:46-51:
   $this->app->singleton (ResolvesLoginCandidate::class, FindLoginCandidate::class);
   $this->app->singleton (AuthenticatesLoginCandidate::class, AuthenticateLoginCandidate::class);
   $this->app->singleton (ResolvesPostLoginRedirectUrl::class, ResolvePostLoginRedirectUrl::class);
   $this->app->singleton (CreatesRegisteredUser::class, CreateRegisteredUser::class);
   $this->app->singleton (SendsEmailVerificationNotification::class, SendEmailVerificationNotification::class);
   $this->app->singleton (CreatesInvitedUser::class, CreateInvitedUser::class); .claude/rules/auth-guards.md already documents exactly this trap for models: "Do not bind user or tenant models into
   container ... two as singletons. That caches request-scoped identity for container lifetime — fine under FPM, cross-user leak under Octane." Same reasoning applies here — Actions bound singleton
   get built once, container caches that one instance forever (per worker, under Octane). Harmless today only because none of these six Actions (FindLoginCandidate, AuthenticateLoginCandidate,
   ResolvePostLoginRedirectUrl, CreateRegisteredUser, SendEmailVerificationNotification, CreateInvitedUser) currently carry mutable properties — confirmed, none do. But it's live bait: next person
   adding so much as a protected ?User $resolvedUser cache property to any of these six reproduces the exact incident the rule file already warns about, and nothing here stops them.

No reason these need singleton — they're cheap, stateless, constructor-injected logic classes; every other Action in the app is resolved fresh per call via ::run ()/::make () (app (static::class), no
binding at all). Fix: bind () instead of singleton () for all six — matches how every other Action already behaves, and removes the shared-instance risk entirely rather than relying on "currently
stateless" as the safety net.

9. BillingServiceProvider::register () blanket-singletons every contract in config ('billing.implementations') — including a ShouldQueue Action. app/Providers/BillingServiceProvider.php:24-28:
   php foreach ($implementations as $contract => $concrete) {
   $this->app->singleton ($contract, $concrete); } config/billing.php:187: ProvisionsTenant::class => App\Actions\Tenancy\ProvisionTenant::class. So ProvisionTenant — the Action that gates the entire
   tenant-provisioning pipeline, implements ShouldBeUnique + ShouldQueue, has jobTries/jobBackoff/jobUniqueFor — gets container-cached as one instance forever, same as the six auth Actions from
   finding #8. Harmless today only because it's stateless (no constructor at all). But it's resolved via app (ProvisionsTenant::class)->queue ($data) at real call sites (SettleCheckout,
   WebhookController, LocalCheckoutGateway all inject the interface) — this is the most load-bearing Action in the app riding a container pattern nobody chose deliberately for it; it's just what the
   loop does to every entry, including 10 other non-Action services (repositories, resolvers) that may or may not want sharing.

Compare TenancyServiceProvider.php:185's DomainTenantResolver singleton — that one has an 800-word doc comment justifying exactly why singleton is correct there (shared cache manager instance,
CACHE_STORE=array gotcha). Nothing here has that justification; it's a blanket default applied via config-driven loop, and one of the 11 entries happens to be a queued Action.

Fix: either carve ProvisionsTenant::class (and any other Action-backed entry) out of the loop and bind () it explicitly, or add a comment to the loop explaining singleton is intentional for all 11 and
why — right now it reads as "this is just how service-provider binding is done here," which is the same false-safety finding #8 already flags.

Same underlying defect, now confirmed to reach past AppServiceProvider into config-driven bindings too — worth fixing both in one pass since it's the identical change (singleton → bind) in two files.

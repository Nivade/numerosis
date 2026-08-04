# Module Marketplace

- **5 shipped modules (`alerts`, `announcements`, `branding`, `notes`,
  `tasks`, all under `Nvade\*`) first-party example/premium content, not
  core framework — and `config('app-modules.modules_namespace') === 'Nvade'`
  part of same example, not leftover hardcode.** If project ever extracted
  into generic starter-kit/package, consumer renames `modules_namespace` in
  `config/app-modules.php` before adding own modules — `internachi/modular`
  already supports this per-project, no code change needed — may delete
  `app-modules/{alerts,announcements,branding,
  notes,tasks}` wholesale if
  don't want examples. Decided 2026-07-31 during hardcoded-values audit:
  keep as-is rather than rename to neutral namespace or strip from package,
  specifically so starter kit ships with working, realistic example modules
  out of box.

- **`internachi/modular`'s module registry cached in-process, long-running
  queue worker holds stale one.** Adding new `app-modules/*` package
  (`artisan make:module` + `composer update`) invisible to any PHP process
  booted before package existed — `Modules::module($slug)` returns null and
  `tenants:migrate-module` throws `Module [<slug>] not found`, even though
  code on disk and `composer.json` lists it. `artisan modules:clear` clears
  cache file, but `queue:work` process still won't see it until restart
  (Sail's supervisor respawns on `kill`). Surfaced live: purchasing newly
  built Branding module queued `MigrateModules`, failed with exactly this
  error, leaving tenant `modules` row with `enabled=true` and
  `migrated_at=null` and no `branding_settings` table — every later panel
  request then read color off table that didn't exist.
  `ApplyPanelColorMiddleware` catches resulting `QueryException` and falls
  back, so that particular read safe; nothing else that reads new module's
  tenant tables protected same way, so treat "restart queue worker" as
  required step (not nice-to-have) right after adding module to node
  already running one.

- **`modules.migrated_at` existed as column since phase 4, nothing wrote
  it.** `MigrateModules` now stamps on success — only writer. Before fix,
  `enabled=true` meant only "purchase succeeded", not "safe to query this
  module's tables"; two supposed to be simultaneous
  (`MigrateModules::dispatch()` fires synchronously off same
  `PurchaseModule::handle()` call that sets `enabled`) but queue failure —
  see above — desyncs them silently, since nothing before this read
  `migrated_at` to notice.

- **`$tenant->run()` (`Stancl\Tenancy\Database\Concerns\TenantRun`) has no
  `try`/`finally` of own.** If callback throws — including query against
  tenant database that doesn't exist yet, real race for anything triggered
  by webhook (provisioning may not have finished) — revert step
  (`tenancy()->initialize($originalTenant)` or `tenancy()->end()`) skipped,
  app's default DB connection stays pointed at broken tenant database for
  rest of request/process. `.claude/rules/exception-handling.md`'s note
  that "`$tenant->run()` reverts tenancy in its own `finally`" only true in
  sense that *if bootstrapping succeeded*, normal exception-unwind path out
  of `$tenant->run()` in caller like `FinalizeTenantProvisioning` happens
  to look that way from outside — library itself provides no such
  guarantee, `TenantRun::run()`'s source has no `finally` at all. Any new
  code calling `->run()` where callback might throw (rather than
  controlled, already-tested action) should manage tenancy manually with
  own `try/finally`, way `App\Actions\Modules\ReconcileModuleSubscriptionItems`
  does, instead of trusting `->run()` to clean up. Exactly what broke
  `WebhookControllerLifecycleTest` first time reconciliation wired in:
  exception caught, but tenancy context left switched, next operation
  (test's own `RefreshDatabase` rollback in teardown) failed against
  database no longer existed.

- **Every module-billing action takes `Tenant` argument *and* reads ambient
  `tenant()`, so two must be asserted equal — in all of them, not just one
  where noticed.** `PurchaseModule::purchase()` throws `LogicException`
  when `tenant()->getTenantKey() !== $tenant->getTenantKey()`; `CancelModule`
  did not, despite mixing same two sources — `Module::where(
  'name', $slug)`
  and `ModulePolicy`'s owner lookup read ambient tenant, while
  `$tenant->latestSubscription()` reads argument. Mismatch removes price
  from different tenant's subscription than one whose module row was
  authorised and checked. Asymmetry invisible at either call site (both
  invoked from Filament pages that happen to be in tenant context), only
  shows up reading two actions side by side. **When adding third
  module-billing action, copy guard.** Covered by
  `PurchaseModuleTest::test_it_refuses_to_run_outside_the_tenant_it_is_purchasing_for`
  and `CancelModuleTest` mirror.

- **`PurchaseModule` charges Stripe (`addPrice`/`addPriceAndInvoice`/
  `invoicePrice`, all commit immediately) before writing local `Module` row
  via `RecordModulePurchase` — if that write throws, customer charged with
  no local record, nothing reconciles it automatically**
  (`ReconcileModuleSubscriptionItems` only ever disables, never creates).
  `updateOrCreate()` makes manual re-run safe, but already-purchased guard
  wouldn't stop same-user retry from double-charging, since `purchased_at`
  never written. Caught broadly on purpose — whatever throws here means
  same thing — just `report()`s. **Known gap, not fixed.**
  `PurchaseModule::hasBillingAddress()` wraps its
  `Cashier::stripe()->customers->retrieve()` call same way
  `AddVatNumber`/`SyncBillingAddress` do — see
  `.claude/rules/billing-checkout.md`.
- **`ReconcileModuleSubscriptionItems`'s `whereNotIn($currentItemIds)` must
  bail out on empty `$currentItemIds`, or malformed/unrelated webhook
  payload disables every recurring module tenant has** — `whereNotIn([])`
  matches everything, not nothing. Deliberately narrow: only ever disables,
  never re-enables — re-adding price in Stripe's portal doesn't un-cancel
  module, `PurchaseModule` sole writer of `stripe_subscription_item_id`.

- **`Config::set($key)` with no second argument does not remove config key —
  writes `null` into it. Neither does `Config::offsetUnset($key)`, literally
  `set($key, null)`** (`Illuminate\Config\Repository::offsetUnset`).
  `InteractsWithTenantModules::getEnabledModuleNames()` builds throwaway
  `tenant_init_<uniqid>` connection to read module state before tenancy
  bootstraps, and its `finally` used one-argument form under comment saying
  "we don't want to pollute the config with many temporary connections" —
  so every invocation left null connection definition behind, PDO handle
  stayed resolved in `DatabaseManager` for rest of request because nothing
  purged it. Removing config key requires rewriting parent array
  (`$c = Config::array('database.connections'); unset($c[$name]);
  Config::set('database.connections', $c);`), dropping connection requires
  `DB::purge($name)` as well. Both halves, or not cleaned up.

## Suggested better approach

`TenantRun::run()` vendor code, not ours to patch, but risk narrow: only
matters when callback can plausibly throw from database-availability
problem rather than logic bug. Provisioning pipeline code
(`ProvisionTenant` and friends) already runs in contexts where tenant
database guaranteed to exist by time `->run()` called, so fine as-is. Rule
specifically for code reachable from webhooks or other async/external
triggers, where "tenant DB exists yet" not guaranteed — reconciliation-style
code pattern to watch for next time one gets added.
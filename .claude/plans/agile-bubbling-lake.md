# Hardcoded values blocking package extraction

**Status: ✅ Executed.** `nvade.dev` gone from `TenantAdminPanelProvider`
and `bootstrap/app.php` (bare `trustHosts()`), `.env.example` has
`DOMAIN`/`CENTRAL_SUBDOMAIN`, `BillingStatsWidget`'s fake MRR replaced with
a real calculation, `tests/TestCase.php` has the `tenantDomain()` helper.

## Context

Project is heading toward being a package/starter-kit other developers install and
run under their own domain, Stripe account, and pricing. Anything that currently
assumes "this app runs at nvade.dev with these exact plans" will silently break —
or silently lie — for a consumer. Did a direct grep-based audit (no subagents) across
`app/`, `config/`, `bootstrap/`, `.env.example`, `resources/views`, `database/seeders`.
Most of the codebase is already env/config-driven (`config/tenancy.php`,
`config/services.php`, `config/cashier.php`, `config/billing.php`, `config/modules.php`
all pull from `env()`). Four real hardcodes found; two more flagged as decisions
rather than code fixes.

## Findings, ranked by actual risk

1. **`app/Providers/Filament/TenantAdminPanelProvider.php:73`** —
   `->tenantDomain('{tenant}.nvade.dev')` is a literal string, while
   `config/tenancy.php`'s `central_domains` already derives the same domain from
   `env('CENTRAL_SUBDOMAIN')` / `env('DOMAIN')`. Every consumer whose domain isn't
   `nvade.dev` gets a tenant panel whose routes never match their real subdomains —
   this is the single highest-severity item, and it's also the same file just fixed
   in this session for the OAuth-callback bug, so it's fresh context.

2. **`bootstrap/app.php:62-67`** — `trustHosts([...])` hardcodes `'nvade.dev'`,
   `'localhost:5173'` (Vite HMR), and `'172.18.0.4'` (a dev Docker container IP).
   `TrustHosts` fails closed on an untrusted `Host` header, so a consumer deploying
   to any other domain gets requests silently rejected/mismatched host, and ships a
   dev-only container IP that means nothing outside this machine.

3. **`.env.example`** — has no `DOMAIN` or `CENTRAL_SUBDOMAIN` keys, even though
   `config/tenancy.php`'s `central_domains` (`env('CENTRAL_SUBDOMAIN').'.'.env('DOMAIN')`)
   depends on both. A consumer copying `.env.example` to `.env` gets an empty/dot
   central domain with no signal anything is wrong until routing misbehaves.

4. **`app/Filament/Admin/Clusters/Billing/Widgets/BillingStatsWidget.php`** —
   `Estimated MRR` stat hardcodes `$activeSubscriptions * 2900` (assumes every
   subscription is $29/mo) plus a dead placeholder line
   (`Subscription::query()->count(); // Placeholder logic`). For any consumer with
   different pricing this widget reports a fabricated number, not an estimate.

## Fix plan

- **TenantAdminPanelProvider** — DONE. Added `'tenant_domain' => '{tenant}.'.env('DOMAIN')`
  to `config/tenancy.php` next to `central_domains`, and the panel now calls
  `->tenantDomain(Config::string('tenancy.tenant_domain'))` instead of the literal.

  **Surfaced a second, more serious bug while fixing this — the domain pattern
  overlap.** `{tenant}.<DOMAIN>` matches the central subdomain too (e.g. "saasm"
  fits `{tenant}` exactly as well as a real tenant id). `register()` had — and,
  after testing both ways, still has — a gate
  (`! request()->isCentralDomain() || app()->runningInConsole()`) that stops this
  panel from registering at all on a central-domain request, specifically to avoid
  that ambiguity. Removing the gate (tried during this pass, to fix the OAuth
  route-name issue below) let the panel's `/` route win the match over the central
  app's own `/` (`home`) route for the central domain — confirmed live (500 from
  `UpdateUserLastSeenMiddleware`'s `TenancyNotInitializedException` once a defense
  middleware was added, then a 404 once `Stancl\Tenancy\Middleware\
  PreventAccessFromCentralDomains` was added to the panel's own middleware stack —
  the panel matched and got correctly refused, but the *central* homepage never
  got a chance to run at all). Restored the gate; `PreventAccessFromCentralDomains`
  stays in the panel's middleware as belt-and-braces in case the gate is ever
  removed again.

  **This reopens the original bug this session started with** — no
  `filament.tenantAdmin.*` route name exists for a central-domain request, and
  `App\Http\Controllers\Socialite\Login`'s OAuth callback always lands on the
  central domain. Fixed differently this time, without depending on the panel
  being registered: `Login::tenantDashboardUrl()` and
  `handleInvitationIfPresent()` now call `tenant_route($domain, 'home')` instead
  of `tenant_route($domain, 'filament.tenantAdmin.pages.dashboard', [...])` —
  `home` is central-only but shares the panel's `/` path, and `tenant_route()`
  only swaps the host, so the resulting URL is identical without ever needing a
  panel route name to exist on this request.

  Verified live: `curl -H 'Host: saasm.nvade.dev' http://127.0.0.1` inside the
  Sail container returns `200` (homepage) after this fix, versus `500` then `404`
  during the two intermediate broken states above.

- **bootstrap/app.php trustHosts** — DONE. Checked `Illuminate\Http\Middleware\
  TrustHosts` source directly: calling `trustHosts()` with **no argument** makes
  `hosts()` fall back to `allSubdomainsOfApplicationUrl()`, which derives
  `^(.+\.)?{host}$` straight from `config('app.url')` — exactly the central+tenant-
  subdomain shape this app needs, with zero literals. It's also a no-op on `local`
  env and under `runningUnitTests()` (`shouldSpecifyTrustedHosts()`), so the dev
  container IP and Vite's `localhost:5173` never needed to be listed at all — they
  were dead weight, not a requirement. Replaced the whole hardcoded array with a
  bare `$middleware->trustHosts();` call.

- **.env.example**: add `DOMAIN=localhost` and `CENTRAL_SUBDOMAIN=app` (or similar
  sensible local default) near `SESSION_DOMAIN`, so a fresh `cp .env.example .env`
  produces a working central domain out of the box.

- **BillingStatsWidget**: remove the dead placeholder line, and replace the fake MRR
  calculation with one derived from real data — sum each active `Subscription`'s
  actual plan price (join through `payment_plans` on `stripe_price`/`monthly_id`
  /`yearly_id`, whichever the schema uses — check `App\Models\Central\Subscription`
  and `App\Models\Central\PaymentPlan` relations first) via `Cashier::formatAmount()`,
  same as it does now. If no clean relation exists yet between `Subscription` and
  `PaymentPlan`, that's worth a quick look at `LinkSubscriptionToTenant`/
  `RecordSubscription` (`app/Actions/Billing/Subscriptions/`) before assuming one
  needs to be added.

## Decided, folded into this pass

- **Test fixture domain — parameterize now.** 10 files construct a tenant domain as
  `$id.'.nvade.dev'` (or equivalent) directly:
  `tests/Feature/TenantAdminAuthTest.php`, `tests/Feature/BotBlockingAuthTest.php`,
  `tests/Feature/Models/Central/TenantPrimaryDomainCacheTest.php`,
  `tests/Feature/Actions/Queries/FindUserByGlobalIdTest.php`,
  `tests/Feature/Chat/ChannelTest.php`, `tests/Feature/Chat/SendMessageTest.php`,
  `tests/Feature/Chat/StatusTest.php`, `tests/Feature/Livewire/Chat/ChatPanelTest.php`,
  `tests/Feature/Livewire/Chat/ChannelViewTest.php`,
  `tests/Feature/Resolvers/DomainTenantResolverCachingTest.php`. Add one helper —
  a `tenantDomain(string $id): string` method on `Tests\TestCase` (sits next to the
  existing `actingAsTenantPanelUser()` at `tests/TestCase.php:53`) that builds the
  string from `Config::string('tenancy.tenant_domain')` the same way the app code
  now does — then swap each literal for `$this->tenantDomain($id)`. Mechanical,
  one pattern repeated 10 times; do not hand-roll the domain string per file.

- **Module namespace — keep as-is, document as example.** `Nvade\*` across the 5
  app-modules and `config/app-modules.php`'s `'modules_namespace' => 'Nvade'` stay
  untouched. Add a short note to `.claude/rules/module-marketplace.md` (or a new
  `INDEX.md` entry if it grows) stating: these 5 modules are first-party example/
  premium content, not core framework; a consumer renames `modules_namespace` in
  `config/app-modules.php` before adding their *own* modules (already supported by
  `internachi/modular`), and may delete `app-modules/{alerts,announcements,branding,
  notes,tasks}` entirely if they don't want the examples. No code change — this is
  purely so the next person doesn't wonder whether `Nvade\*` is a leftover hardcode
  or intentional example scaffolding.

## Verification

- After the `TenantAdminPanelProvider`/`bootstrap/app.php` changes: run
  `vendor/bin/sail artisan route:list --name=tenantAdmin` and confirm the domain
  segment reflects `config('tenancy.tenant_domain')`, not a literal — DONE.
- `curl -H 'Host: saasm.nvade.dev' http://127.0.0.1` inside the Sail container
  should return `200` (central homepage), not `500`/`404` — DONE, confirmed.
- `vendor/bin/sail artisan test tests/Feature/TenantAdminAuthTest.php
  tests/Feature/Http/Controllers/Socialite` (both touch tenant-domain routing).
- After adding `tenantDomain()` to `Tests\TestCase` and swapping the 10 files: run
  the full set of touched files in one pass — `vendor/bin/sail artisan test
  tests/Feature/TenantAdminAuthTest.php tests/Feature/BotBlockingAuthTest.php
  tests/Feature/Models/Central/TenantPrimaryDomainCacheTest.php
  tests/Feature/Actions/Queries/FindUserByGlobalIdTest.php tests/Feature/Chat
  tests/Feature/Livewire/Chat tests/Feature/Resolvers/DomainTenantResolverCachingTest.php`
  — same behavior expected, just no more literal domain.
- For `BillingStatsWidget`: no existing test covers it (checked — no
  `BillingStatsWidgetTest`); add one asserting MRR against a couple of
  `Subscription`+`PaymentPlan` fixtures with different prices, per
  `.claude/rules/testing.md`'s "every change must be programmatically tested."
- `vendor/bin/sail bin pint --dirty --format agent` after edits.

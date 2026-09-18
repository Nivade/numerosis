# What the host app must own

`nvade/numerosis` normalizes almost everything it needs at boot time
(`Nvade\Numerosis\Boot\HostConfig::apply()`, run from a `booting()`
callback that `NumerosisServiceProvider::packageRegistered()` registers —
deferred to that phase on purpose, so config another package's own
`mergeConfigFrom()` still has to merge into is normalized after it lands
rather than before; see `.ai/rules/package-host-bootstrap.md`) — a host
that sets database credentials, Stripe keys and `APP_URL`, runs `migrate`
and asset publishing, and writes a one-line `bootstrap/app.php` gets a
working multi-tenant SaaS. Everything past that is an override, not a
requirement.

This document is two lists: what you actually have to do, and what the
package does for you (with how to override it, if the default is wrong for
your deployment). `php artisan numerosis:install` is the executable copy of
both — it prints what `HostConfig` configured for you, then runs every
`verify*()` check below and fails loudly, naming the exact key, if
something is still wrong. Run it with `--verify-only` to check without
publishing or seeding anything.

## 0. What you install

`nvade/numerosis` is two Composer packages developed in one repository and
published as read-only splits — collapsed from six by
`.claude/plans/archive/humming-nibbling-flame.md`. Both are effectively required.

| Package | What it is |
|---|---|
| `nvade/numerosis` | Tenancy, Fortify-backed auth, billing, provisioning, the onboarding wizard, every migration and seeder, and the auth/account/onboarding screens that used to be separate packages. |
| `nvade/numerosis-ui` | The shared Blade layer: `<x-numerosis::ui.*>`, the design tokens, `livewire/flux`. Not declinable in practice — core `require`s it, because core's own views render its components and a missing Blade tag renders as literal text rather than failing. |

Auth screens, routes and session handling are `laravel/fortify`'s; core
supplies the tenancy-aware actions Fortify's contracts call and the views at
`numerosis::auth.*`. See [`extending.md`](extending.md) for the
customization seams.

Worth knowing: **installing a feature's code is not the same decision as
switching it on.** `config('numerosis.features')` is the only switch —
turning `RegistrationWizardFeature` off hides `/get-started` even though the
wizard's code is always present; turning `SocialLoginFeature` off hides
OAuth even though `laravel/socialite` is always a dependency.

`.ai/rules/package-boundaries.md` is the full seam map — routes,
features, migration paths, seeders, permission contexts, panels — for anyone
writing a sixth package rather than a host.

## 1. What you must provide

Nothing here can be defaulted — each is a secret, infrastructure, or a
framework hook that has to run before any package code can act.

| Requirement | Required value / shape | Why it can't be automated | Checked by |
|---|---|---|---|
| `APP_URL` | correct, reachable scheme+host | every domain value (`numerosis.domains.*`, `tenancy.central_domains`, `session.domain`) derives from it | — indirectly, via `verifyDomainConfig()` and `verifyCentralDomains()` below; nothing to assert about the raw env value itself |
| `STRIPE_KEY` / `STRIPE_SECRET` | real Stripe publishable/secret keys | secrets; cannot be defaulted | `verifyStripeKeys()` |
| `STRIPE_WEBHOOK_SECRET` | the signing secret Stripe's dashboard gives your webhook endpoint | secret, used to verify inbound Stripe webhooks | — not independently verifiable; a wrong value fails loudly the first time Stripe calls back, not silently, so nothing here would catch it earlier than that |
| DB credentials + `php artisan migrate` | working credentials for MySQL, MariaDB, PostgreSQL or SQLite; migrations run against the `central` connection | the `central` connection has to exist before anything else in this list means anything, and the driver decides how a tenant database is created and dropped. SQLite is accepted with a warning: one writer per file, and the central database is shared by every tenant | `verifyDatabaseConnections()` |
| — same, `failed_jobs` table specifically | present on whichever connection `queue.failed.database` names | migrations create it; nothing else does | `verifyFailedJobsConnection()` |
| `bootstrap/app.php` wiring | `->withRouting(using: Numerosis::routes(...))` plus `Numerosis::middleware()`/`Numerosis::exceptions()` inside your own `withMiddleware()`/`withExceptions()` closures, or `Numerosis::configure(...)` for a greenfield app | `ApplicationBuilder::withRouting()`/`withMiddleware()` run at builder time, before any service provider — nothing inside `packageRegistered()`/`packageBooted()` can substitute for the framework hook itself | — `NumerosisServiceProvider::booted()` self-heals a missing call (registers routes/middleware itself if it detects neither ran) rather than failing the install command; see `.ai/rules/package-host-bootstrap.md` |
| `php artisan vendor:publish --tag=numerosis-public-assets` | run at least once, unless you build `resources/js/numerosis.js` through your own Vite | copies this package's prebuilt JS/CSS to `public/vendor/numerosis/`; `Assets::tags()` links both URLs whether or not they are there, so a missing publish is a 404, not an exception | `verifyPublicAssets()` |
| DNS + a provisioning worker | depends on `numerosis.tenancy.identification.mode` — `subdomain`: `*.{tenant_pattern}` resolves; `custom_domain`: each tenant points their own domain here; `path`: no DNS change at all. Plus, in every mode, a queue worker on the `provisioning` queue | infrastructure — tenant provisioning is queued there, not on the default worker | — infrastructure, outside anything a boot-time check can observe; `numerosis:install`'s printed manual steps name the right one for your mode |
| Trusted proxies, if you sit behind one | see below | which IPs are allowed to set `X-Forwarded-*` is a network-topology fact the package cannot infer | — no `verify*()`; getting it wrong fails open (spoofable `$request->ip()`), not loud |

**Plus five things a fresh `laravel new` app ships that have to go, or be
added, before the first request works.** All five were found by installing
into a scratch app against this document alone, 2026-09-14; each failed at
boot rather than at install, and `numerosis:install --verify-only` exits 0
with every one of them still wrong. `tests/smoke-host.sh` (`composer
test-host`) is that install, start to finish, against a throwaway app it
deletes afterwards — run it after changing anything a host touches.

| Fresh-app default | What it does | What to do |
|---|---|---|
| `database/migrations/0001_01_01_000000_create_users_table.php` and `…_000002_create_jobs_table.php` | both run *before* the package's `2019_09_01_00000{0,2}_*` copies, which then fail `SQLSTATE[42S01] … Table 'users' already exists`. The filenames differ, so the migrator's dedupe never sees them as the same migration | delete both. The package's copies create `users` and `password_reset_tokens`; they do **not** create `sessions` |
| `SESSION_DRIVER=database` with no `sessions` table | deleting the stock users migration takes the `sessions` table with it, so every central request 500s on `Table 'numerosis_smoke.sessions' doesn't exist` | run the package's central migrations: `2026_09_16_200000_create_sessions_table.php` creates it, and skips a `sessions` table you already have |
| `SESSION_DRIVER=database` on a *tenant* request | tenant databases deliberately have no `sessions` table (`database/migrations/tenant/2026_01_07_200130_remove_redundant_tables.php` drops it — one session spans every subdomain, so it belongs centrally), and the tenancy bootstrapper has already switched the default connection: `Table 'tenantacme.sessions' doesn't exist` | nothing: `HostConfig` pins `session.connection` to the central connection while you have not set it. Set `SESSION_CONNECTION` yourself only to override that |
| `routes/web.php`'s stock `Route::get('/')` | it wins `/` on the central domain and core's `home` route is then never registered at all, so every auth screen 500s with `Route [home] not defined` — its layout links `route('home')` | delete it, or name yours `home` |
| no Vite build | the package's layouts call `@vite(['resources/css/app.css', 'resources/js/app.js'])` on *your* entries, so `vendor:publish --tag=numerosis-public-assets` alone is not enough: `Vite manifest not found at: public/build/manifest.json` | `npm install && npm run build` |

Two Composer flags the path-repository install needs, on top of the
`repositories` block in `README.md`: `minimum-stability: dev` with
`prefer-stable: true` (the splits are `dev-<branch>` only), and `-W` on the
`require` (`laravel/socialite` pins `guzzlehttp/guzzle ^7`, which a fresh app's
lock file has already resolved to 8).

**Plus one conditional**:

- `NUMEROSIS_APEX_DOMAIN` when served at the apex of a multi-part public
  suffix (`example.co.uk`) — the documented limit of
  `Domains::apexFromAppUrl()`'s label-count heuristic (it strips one label
  from `APP_URL`'s host, which is wrong when the registrable domain itself
  has two labels). See the `domains.apex` row below.

Checkout's region-specific payment-method order has no lookup wired in —
`torann/geoip` was dropped (Phase 6 of the scope-reduction plan) — so the
bound `Contracts\Billing\CheckoutRegionResolver` is
`NullCheckoutRegionResolver` and checkout always falls back to
`numerosis.billing.payment_methods.default_order`. Bind your own resolver to
turn the per-region order on; see `docs/extending.md`.

Central data (roles, permissions and the example plans) needs
seeding too, but as of the seeding-by-convention work below that's now a
side effect of a command you already run (`numerosis:install`, or a fresh
host's own `db:seed`), not a separate obligation.

### Trusted proxies

Nobody is trusted by default — `X-Forwarded-*` headers are ignored, and
`$request->ip()`/scheme/host reflect the raw socket. That is Laravel's own
default and the package never overrides it for the documented integration
path: if your `bootstrap/app.php` calls `Numerosis::middleware($middleware)`
yourself, add `$middleware->trustProxies(at: [...])` after that call the same
way you would in a bare Laravel app — nothing here needs to know about it.

If instead you rely on the package's fallback boot (no `withMiddleware()`
wiring at all — the common case for `workbench/`-style quick installs), set
`numerosis.trusted_proxies` (or `TRUSTED_PROXIES` in `.env`) to decide the
same question. The value is a decision about network topology, not code:

- **The app is reachable only through your proxy** — a PaaS edge, a load
  balancer with the app's own port firewalled off from the internet. Nothing
  but the proxy can ever reach the app directly to forge a header, so
  `TRUSTED_PROXIES=*` is safe.
- **The app is also directly reachable** — a typical single-box Nginx or
  Docker Compose setup where the app's port isn't firewalled. Name the
  proxy's actual IP/CIDR instead (`TRUSTED_PROXIES=127.0.0.1` for a local
  Nginx, the load balancer's private IP range for a cloud one). `'*'` here
  lets anyone who reaches the app directly spoof `$request->ip()` — which
  defeats this package's own IP-keyed login/OTP/social rate limiters — and
  forge the scheme/host, which can break HTTPS redirect detection and
  signed-URL generation.

Getting this wrong in either direction fails quietly: too narrow and you
lose real client IPs behind your proxy's; too broad (`'*'` when directly
reachable) and rate limiting silently stops working. There is no boot-time
check for it — it is a fact about your infrastructure, not your config file.

### Security headers and the content security policy

`Http\Middleware\SecurityHeaders` is appended to the `web` group, which the
`tenant` group nests, so every HTML response on both sides of tenancy carries
`Strict-Transport-Security`, `X-Content-Type-Options`, `Referrer-Policy`,
`X-Frame-Options` and `Permissions-Policy`. Every value lives in
`numerosis.security.headers`; an empty one omits that header, and
`numerosis.security.headers.enabled` turns the whole middleware off.

`numerosis.security.headers.except` holds path patterns that share the group
but serve no browser. Stripe's webhook is on it, because Cashier answers it
with a `text/html` response a content-type check cannot tell from a page.

The CSP ships **report-only**, with the Stripe, Turnstile and Bunny Fonts
origins already allowed, plus the `'unsafe-inline'`/`'unsafe-eval'` that
Livewire's injected script and Alpine's expressions need. To go enforcing:

1. Point `NUMEROSIS_CSP_REPORT_URI` at a collector, or watch the browser
   console, for as long as it takes to exercise checkout, the registration
   wizard and your own screens.
2. Add the origins your own assets and analytics load from to
   `numerosis.security.headers.content_security_policy.directives`.
3. Set `NUMEROSIS_CSP_REPORT_ONLY=false`.

Enforcing it before step 1 is what breaks payments: Stripe Elements renders in
an iframe, and a `frame-src` that omits `js.stripe.com` removes the card field
with no error the server ever sees.

### Tenant backups

`tenancy:backup {tenant|--all}` writes one physical snapshot per tenant to
`numerosis.tenancy.backup.disk`, and `tenancy:restore {tenant} --from=<path>`
puts one back. `--into=<other-tenant>` restores into a different tenant and
rewrites references to the source's id, which is how a production tenant is
cloned into staging; without that rewrite the clone keeps pointing at the
tenant it came from.

Four decisions are yours:

- **Which dumper.** The default reads and writes through PDO, so nothing has
  to be installed. It cannot reproduce PostgreSQL's DDL, so a PostgreSQL
  artefact restores into an already-migrated database; `pg_dump` is the answer
  when you need a self-contained one.
- **Where artefacts land.** `numerosis.tenancy.backup.disk` — point it at
  object storage rather than a local volume.
- **Encryption.** On by default, streamed a block at a time with the app key.
  An artefact holds everything a tenant has, so turning this off makes the
  disk as sensitive as the database.
- **Retention.** `numerosis:prune-tenant-backups` keeps
  `numerosis.tenancy.backup.keep_days` (30) and runs daily behind
  `numerosis.schedule.prune_tenant_backups`.

Purging a closed tenant takes one final backup first and refuses to drop the
database when it fails (`numerosis.tenancy.backup.before_purge`). That
interlock is what makes the closure recovery window mean something, so turn it
off only if you back up outside the package.

`Contracts\Tenancy\ExportsTenantData` is the other half: a zip of JSON Lines
per table plus the tenant's files, for "send me my data" rather than for
restoring.

### Entitlements: what a plan actually allows

`Contracts\Billing\Entitlements` answers two questions over the plan a tenant
is on: whether a capability is included (a `features.slug` marked available on
the plan's pivot) and how much of an allowance is left.

```php
Entitlements::allows('custom-branding');
Entitlements::remaining('seats');
Entitlements::consume('api-calls');     // throws EntitlementDenied when exhausted
```

Limits live in plan metadata under `options.limits.<capability>`, with
`options.max_users` still meaning seats. **An absent or non-numeric limit is
uncapped**, deliberately: a malformed entry must not lock a customer out of
what they are paying for, which is why `numerosis:install --verify-only`
reports one instead.

A tenant with no active subscription gets `numerosis.billing.free_tier`, which
ships empty — the behaviour that predates entitlements. Put `'seats' => 1`
there to cap a workspace nobody is paying for.

### Notifications

Every notification the package sends goes to mail *and* to the in-app bell,
unless the recipient says otherwise. Defaults live on
`Enums\Notifications\NotificationType`; `notification_preferences` holds
overrides only, keyed by `global_id` so a preference follows the person across
workspaces and survives their removal from any one of them.

**`NotificationType::mayDisableMail()` is the spine.** A user who has muted
everything still gets the mail that says their card failed, their workspace was
suspended, or their security settings changed. The rule is on the enum rather
than on the preferences screen, so a notification added later cannot quietly
become optional — and the one-click unsubscribe link honours it too, answering
without changing anything.

| Key | What it does |
|---|---|
| `numerosis.notifications.operator` | Where platform-level alerts nobody owns are mailed. Null sends nothing rather than throwing |
| `numerosis.notifications.keep_days` | Retention for **read** in-app notifications, swept by `numerosis:prune-notifications` (`SCHEDULE_PRUNE_NOTIFICATIONS`, on by default). Unread ones are never pruned |

The bell is one Livewire component on both sides of tenancy: inside a workspace
it shows that workspace's notifications plus anything belonging to none, and on
the central domain it shows everything. Notifications are central rows read from
a tenant screen, so the scope is explicit rather than inherited from whichever
connection happens to be open.

### Custom domains and TLS

Under `IdentificationMode::CustomDomain` a tenant proves it controls a hostname
before that hostname is served: a `TXT` record at
`_numerosis-challenge.<domain>` carrying the domain's token, plus a `CNAME`
(or an `A` record, for an apex zone that cannot carry one) pointing traffic
here. Both halves are checked and reported separately, because a domain that
verified fine and was never pointed still 404s.

`verified` means ownership is proven. `active` means it is proven *and* pointed
here. They are separate states on purpose — the gap between them is what support
is asked about — and the tenant screen at `domain` states the difference in
words.

**The package issues no certificates.** It proves ownership and publishes the
verified set; how a certificate appears is the deployment's business:

| Deployment | How it gets certificates | What to switch on |
|---|---|---|
| Caddy (the shipped default) | On-demand TLS | `NUMEROSIS_TLS_ASK_ENDPOINT=1`. Caddy's `on_demand_tls.ask` points at `numerosis/tls/ask`, which answers 200 for a servable hostname and 404 otherwise |
| Traefik | ACME per router | `NUMEROSIS_TLS_ROUTERS_ENDPOINT=1`. Point Traefik's HTTP provider at `numerosis/tls/routers`, and set `NUMEROSIS_TLS_TRAEFIK_SERVICE` / `NUMEROSIS_TLS_CERT_RESOLVER` to match your static config |
| Cloudflare for SaaS | Their API | Neither endpoint. Listen for `Events\Tenancy\DomainVerified` and call Cloudflare from your own listener |
| Anything else | Your own automation | The same event, plus `Actions\Queries\GetServableDomains` |

Both endpoints are public by necessity — a proxy calls them before any
certificate exists — so both are rate limited, cached for
`NUMEROSIS_TLS_CACHE_SECONDS`, and answer nothing beyond whether a hostname is
ours. The query behind them filters on tenant state as well as domain state: a
suspended or closed tenant's hostname stops being served.

Run `numerosis:verify-domains` on a schedule
(`SCHEDULE_VERIFY_DOMAINS=true`). It re-checks claims waiting to verify *and*
domains already serving, because DNS pulled from under a live domain has to stop
being served and nothing else would notice.

### Discounts

Coupons and promotion codes are Stripe objects. This package applies, validates,
displays and records them, and computes no discounted amount itself — a locally
derived figure eventually disagrees with the invoice.

- The checkout screen carries a code field, in both the standalone checkout and
  the registration wizard (the wizard embeds the same component). `?promo=CODE`
  only pre-fills it; Stripe still decides.
- Each refusal has its own message — unknown, expired, at its redemption limit,
  pinned to another customer, first-purchase-only, product-restricted, below a
  minimum order — because "invalid code" for all seven is a support ticket.
- The code is re-validated when the subscription is created, since a redemption
  limit moves in between. A code that no longer validates is dropped and the
  plan is charged undiscounted rather than the sale being refused.
- `applied_promotions` is the audit trail of redemptions. Whether a discount is
  still live is Stripe's answer, read through `Actions\Queries\GetTenantDiscount`.
- `numerosis.billing.promotions.retention_code` (`BILLING_RETENTION_CODE`, null
  by default) offers one code to an owner on the close-workspace screen. A code
  Stripe refuses is not shown at all, so a retired coupon degrades to the plain
  close flow.

### Usage-based billing

A plan bills a capability by the unit by declaring a meter beside its limits:

```php
'options' => [
    'meters' => [
        [
            'key' => 'api-calls',          // the counter key Entitlements::consume() uses
            'event_name' => 'api_calls',   // the Stripe billing meter's event name
            'meter_id' => 'mtr_123',       // needed by billing:reconcile-usage only
            'price' => 'price_metered',    // optional: which subscription item it bills through
            'included' => 10_000,          // optional allowance before the overage starts
        ],
    ],
],
```

A metered key counts per billing period rather than for the life of the tenant,
and `consume()` never refuses it once the allowance is gone: the overage is what
the plan sells. Reporting is `billing:report-usage`
(`numerosis.schedule.report_usage`), checking is `billing:reconcile-usage`
(`numerosis.schedule.reconcile_usage`), both off by default. The screen showing
a customer their current period is `UsageMeteringFeature`, and is separate from
both — a host may bill usage without showing the breakdown.

Three enforcement seams, and only one of them enforces:

| Seam | What it is for |
|---|---|
| `entitlement:<capability>` route middleware | Keeping a screen out of reach |
| `@entitled('<capability>')` Blade directive | Hiding what is not sold |
| `Entitlements::assertAllowed()` / `consume()` in the action | The actual gate — a queue job, a webhook and an API token reach the action and never the other two |

A downgrade to a plan whose limits the tenant already exceeds **is allowed**,
and blocks the next addition rather than the swap. Refusing would trap a
customer trying to spend less.

Usage is counted in the central `tenant_usage` table through
`Contracts\Billing\UsageCounter`, not in the cache: a counter that loses
writes on a cache flush is a counter that under-bills. Seats are counted from
the membership rows instead, since they are state rather than events.

### Subject access requests and erasure

`settings/data` is where a person asks for a copy of everything this account
holds. Generation is queued, because it reads every tenant database they
belong to, and the archive arrives as a **signed, single-use, short-lived**
link by mail rather than on the screen. The owner of a workspace can export
the whole workspace from the team screen, and staff can start either on
somebody's behalf from the users screen (`exportData users` and
`eraseData users` permissions, seeded with the admin role).

Erasure is **anonymize in place**, not a delete, and
`Actions\Auth\DeleteUserAccount` is the entry point:

| Data | On erasure |
|---|---|
| Central identity, social accounts, sessions | Removed |
| Membership rows | Deleted |
| Tenant-side user row | Name and address replaced, primary key kept — the workspace's own content hangs off it |
| Content authored inside a workspace | Retained, attributed to the anonymized user |
| Consent records | Retained; they hold a global id and no personal data |
| Billing records | Retained. Financial records carry their own statutory retention |

It is not atomic across N databases and cannot be — each tenant commits on its
own connection. Every step is keyed on `anonymized_at` being null, so
re-running finishes what a failure left. An owner is still refused: transfer
the workspace or close it first.

`consents` records what was agreed and which `numerosis.privacy.terms_version`
was in force. Bump that key when your terms change; the next registration
records the new one, and the old rows stay as they were.

### Retention windows, in one place

Every number here is yours to set. The package ships a default and a command;
your counsel decides the period.

| What | Key | Default | Swept by |
|---|---|---|---|
| Central activity log | `activitylog.clean_after_days` | 365 days | `numerosis:prune-activity-log` |
| Tenant backup artefacts | `numerosis.tenancy.backup.keep_days` | 30 days | `numerosis:prune-tenant-backups` |
| Export artefacts | `numerosis.privacy.keep_days` | 7 days | `numerosis:prune-data-exports` |
| Closed tenants | `numerosis.tenancy.closure.grace_days` | 30 days | `tenancy:prune-orphaned-databases` (only while `purge_closed` is on) |
| Accepted or expired invitations | — | 30 days | `model:prune` |

Each sweep has its own `numerosis.schedule.*` switch. The export request row
outlives its artefact deliberately: it is the record that a request was
answered, and it holds nothing but a global id.

### Breached-password checking

`numerosis.auth.check_compromised_passwords` (default on) adds Laravel's
`uncompromised()` rule to `Password::defaults()`, so registration, reset and
the password settings screen all refuse a password found in a public breach
corpus. Only five characters of the password's SHA-1 leave the process. The
rule fails **open**: an unreachable API reports the exception and accepts the
password rather than blocking a signup.

Turn it off (`NUMEROSIS_CHECK_COMPROMISED_PASSWORDS=false`) for an air-gapped
deployment, or when you set `Password::defaults()` yourself — the package
writes that static during boot and would otherwise overwrite your rule set.

Existing accounts are never re-checked. Nothing refuses a login because the
password has since appeared in a breach.

## 2. What the package configures for you, and how to override it

Everything below has a package default. A row exists here because a host
*can* still break it — either by setting the key directly (bypassing the
mechanism that would have normalized it) or by customising something
upstream (a published stub, a Cashier connection name) the normalization
can't see into. `verify*()` methods described as "narrowed" ask *"did the
host break what we set"*, not *"did the host wire this up"* — the common
case (a host that touched none of this) never reaches a failure message at
all. Every normalization is independently covered by
`tests/Feature/HostConfigTest.php` (one "sets the default" case, plus a
"does not override the host" case wherever a host value of that key is a
meaningful thing to hold), and every config key `HostConfig` so much as
names has to appear somewhere in this document —
`tests/Feature/Docs/HostRequirementsTest.php` fails otherwise, which is
what stops the next normalization from shipping undocumented.

| Concern | Set to, by default | Override | Checked by |
|---|---|---|---|
| `tenancy.tenant_model` / `tenancy.domain_model` / `tenancy.central_user_model` / `tenancy.tenant_user_model` | `Numerosis::model(...)` for each — the package's own concrete class, or a host subclass at the conventional `App\Models\<suffix>` path, picked up automatically (see the `models.*` row below) | publish a stub at that path, or set `numerosis.models.<FQCN>` directly for a non-conventional location | `verifyTenancyModels()` (narrowed — HostConfig sets this; only fires for a key set directly, bypassing `Numerosis::model()`) |
| `tenancy.seeder_parameters['--class']` | `numerosis.tenancy.seeder`, always — a preference, not a correction: it projects onto this key unconditionally | set `numerosis.tenancy.seeder`, not `tenancy.seeder_parameters` directly | `verifyTenancyModels()` |
| `numerosis.tenancy.seeder` (read a second time) | the same class name is also `resolve()`d directly by `Actions\Tenancy\SeedTenantDatabase`, which does not check that it is a `Seeder` — a value naming a class the container cannot build fails as a container error inside the provisioning chain, not through the install check | set it to a seeder class that exists | `verifyTenancyModels()` (covers the key, not the second read) |
| `tenancy.central_domains` | `[numerosis.domains.central]`, derived from `APP_URL` — checked against stancl's own stock default (`['127.0.0.1', 'localhost']`, not `[]`) as well as empty, so an untouched host is corrected too | set the array yourself, or override `numerosis.domains.central` | `verifyCentralDomains()` (narrowed) |
| `tenancy.bootstrappers` | stancl's stock four, plus `SpatiePermissionsBootstrapper`, `AuthGuardBootstrapper` and `PasswordBrokerBootstrapper` (appended, never replaced) | override `tenancy.bootstrappers` to a list that excludes them, on purpose — at which point tenancy-guard/permission behaviour is your own responsibility | `verifyTenancyBootstrappers()` (fires only for a host that removed one after the append: the tenant guard then resolves central users, permission lookups read the central tables, and tenant password resets use the central broker — three silent wrong answers, no error) |
| `tenancy.migration_parameters` | `--path` includes `Numerosis::tenantMigrationPath()` (the vendor directory itself, not a published copy), `--realpath` forced true | append your own extra `--path` entries for host-specific tenant migrations | `verifyTenantMigrationPath()` (narrowed to validating any *extra* paths a host has added — the vendor path itself is unconditionally present after every boot) |
| `tenancy.filesystem.disks` | never contains `livewire` | — not user-facing, nothing to override | — actively stripped on every boot if present, so nothing to fail |
| `tenancy.filesystem.root_override.local` | `'%storage_path%/app/private/'` | set it yourself | `verifyTenantFilesystemRoot()` (checks the `%storage_path%` placeholder survives and that the override ends in the same directory as `filesystems.disks.local.root`; a wrong value otherwise surfaces as the mimetype-rejection failure `tenant-filesystem.md` documents, at upload time) |
| `tenancy.database.central_connection` | `numerosis.tenancy.central_connection`, always — a preference, not a correction | set `numerosis.tenancy.central_connection`, not this key directly | — folded into `verifyDatabaseConnections()`'s general connection checks |
| `database.connections.central` | cloned from `database.connections.{database.default}` when absent | define your own `central` connection | `verifyDatabaseConnections()` |
| `database.connections.*.options` (MySQL only) | mirrors `innodb_lock_wait_timeout` next to any `lock_wait_timeout` found | set both yourself in one `SET SESSION` string, or neither | `verifyLockWaitTimeout()` (narrowed — only fires for a `lock_wait_timeout` string that doesn't match the pattern `HostConfig` recognises) |
| `session.domain` | `'.'.numerosis.domains.apex` | set it yourself | `verifySessionDomain()` (narrowed) |
| `session.connection` | the central connection, whenever `session.driver` is `database` and the key is unset. Sessions are listed and revoked from one table, and the default connection points at the tenant database inside tenancy | set `session.connection` / `SESSION_CONNECTION` yourself | `verifySessionStore()` (fails on a connection that is not in `database.connections`; warns instead, without failing, when the driver cannot be listed at all) |
| `queue.failed.database` | `'central'` | set it yourself | `verifyFailedJobsConnection()` (narrowed for the connection name; the "table actually exists" half stays fully real — that needs a migration to have run) |
| `auth.guards.tenant` / `auth.providers.tenant` | session guard over an eloquent provider on `Numerosis::model(Tenant\User::class)` | set `auth.guards.tenant` / `auth.providers.tenant` yourself | `verifyAuthGuards()` (narrowed) |
| `auth.providers.users.model` | `Numerosis::model(CentralUser::class)`, always — a preference, not a correction: set `numerosis.models.<CentralUser FQCN>`, not this key directly | point `numerosis.models.<CentralUser FQCN>` at your own model, as long as it implements `Nvade\Numerosis\Contracts\Auth\CentralUserModel` | folded into `verifyAuthGuards()` |
| `auth.passwords.<broker>` | a broker over the `users` provider, `password_reset_tokens` table, whenever `auth.defaults.passwords` names a broker with no entry | define the broker yourself | `verifyAuthPasswordBroker()` (narrowed) |
| `auth.passwords.tenant` | a broker over the `tenant` provider, `password_reset_tokens` table (in the tenant database), whenever unset. `PasswordBrokerBootstrapper` points `fortify.passwords` at it for the duration of tenancy — without which a tenant subdomain's forgot/reset request resolves the *central* `users` provider | define `auth.passwords.tenant` yourself, or rename it through `numerosis.auth.password_brokers.tenant` | `verifyTenantAuthProvider()` (the tenant provider's model and this broker's entry; the behaviour itself is covered by `tests/Feature/Auth/PasswordResetBrokerTest.php`, which asserts the notifiable is the tenant user and not its synced central mirror) |
| `numerosis.*` deep-fill (the mechanism, not any one key) | every key under `config/numerosis.php` is filled in at every depth from the package's own defaults, so a host override file only has to name what it's actually changing. **Skipped entirely under `config:cache`**: the cached array is already complete, and the package's own defaults file is 300-odd `env()` calls against an unloaded `$_ENV` in that state. A key added by a package upgrade therefore appears only after `php artisan config:cache` is run again | publish `config/numerosis.php` (or write a smaller override file — any key you omit is filled in, at any depth, not just the top level) | — a mechanism, not a single checkable value; the individual keys it protects each have their own row and check below |
| `numerosis.routes.home_view` | `'numerosis::home'` — a placeholder. Core registers the `home` route unconditionally (OAuth redirects, checkout error paths and the tenant panel all fall back to it), but the page itself is the product's | point this at your own view. Do **not** register a second route on `/` unless you name it `home`: your `routes/web.php` is registered first, so a second unnamed `/` route takes the path *and* suppresses core's registration, leaving `route('home')` undefined for every auth screen | — no `verify*()`; a missing view fails as `View [x] not found` on the first request to `/`, which is loud enough |
| `numerosis.domains.apex` / `.central` / `.tenant_pattern` | derived from `APP_URL` (see the conditional obligation above for the one case this derivation is wrong) | `NUMEROSIS_APEX_DOMAIN` / `NUMEROSIS_CENTRAL_DOMAIN` / `NUMEROSIS_TENANT_DOMAIN` | `verifyDomainConfig()` |
| `numerosis.social.routes.{redirect,callback}.name` | `'social.redirect'` / `'social.callback'` — the package's own route names. Provider metadata (label, icon, which are configured) is `Enums\Auth\SocialProvider`, not config — nothing here to override for that | override if you rename either route | `verifySocialRoutes()` (narrowed) |
| `numerosis.cache.store` / `NUMEROSIS_CACHE_STORE` | unset — every global key goes to `cache.default`. `Cache\GlobalCache` resolves the store once per application and re-resolves when either value changes | name a store from `config/cache.php` to keep package keys off the application's default store | — no `verify*()`; a store name with no `config/cache.php` entry throws `InvalidArgumentException: Cache store [x] is not defined` on the first read |
| `numerosis.cache.prefix` | `'numerosis'` — the prefix on every `Cache\CacheKeys` key. It does not decide which keys are tenant-scoped | set it if two installs share one store | — no `verify*()`; a collision is invisible rather than fatal, which is why the default is namespaced |
| `numerosis.cache.ttl.<key>` | seconds per `CacheKeys` method (an hour for most, a day for `tenant_custom_columns`, five minutes for `popular_payment_plan_slug`). `null` disables caching for that key and reads go straight to the database. `Cache\CacheTtl::window()` serves the two payment-plan keys stale for up to 4× their TTL while refreshing | lower a TTL, or `null` it, per key | — no `verify*()`; every value is a valid configuration, including `null` |
| `numerosis.models.<FQCN>` | unset — `Numerosis::model()` finds a subclass at the conventional `App\Models\<suffix>` path automatically when one exists and extends the package model | publish the stub (`--tag numerosis-models`) and let convention find it, or set this key directly for a non-conventional class location | `verifyModelOverrides()` |
| `livewire.temporary_file_upload.disk` | `'livewire'` — a dedicated disk, same physical root as `local`, deliberately excluded from `tenancy.filesystem.disks` | override, but not to `'local'` or anything else tenant-suffixed | `verifyLivewireUploadDisk()` |
| `livewire.component_namespaces.{numerosis-layouts,numerosis-pages}` | the package's own `resources/views/{layouts,pages}` | override if you publish those views locally — **from your own provider's `register()`, not `boot()`** (see the note below). The generic `layouts`/`pages` keys are yours; core never writes them, because a Livewire namespace maps one prefix to exactly one directory | `verifyLivewireComponentNamespaces()` |
| `cache.serializable_classes` (read, never written) | left exactly as your `config/cache.php` has it; what follows it is `DomainTenantResolver::$shouldCache` and `PreservingPathTenantResolver::$shouldCache`, each only turned on when this value can round-trip a cached tenant model (`null`/`true`, or an allowlist naming `tenancy.tenant_model`) | add your tenant model to the allowlist to keep the resolver cache, or force the decision with `numerosis.tenancy.cache_resolved_tenants` (`true`/`false`) | `verifyTenantResolverCache()` (warns when the cache ended up off, since that costs a central lookup per tenant request; reports on whichever resolver your `numerosis.tenancy.identification.mode` uses; never fails the install) |
| your own `database/migrations/*.php` filenames | must not collide with the package's `database/migrations/central/*.php` | delete yours, or merge what it adds into the package's copy | `verifyCentralMigrationCollisions()` (warns; the migrator dedupes by filename and keeps *yours*, so the package's copy silently never runs) |
| `Database\Seeders\DatabaseSeeder` (container binding, not config) | the package's own seeder, whenever the host hasn't defined that class | write `database/seeders/DatabaseSeeder.php` yourself (the class existing wins outright — nothing here can override it); call `$this->call(\Nvade\Numerosis\Database\Seeders\DatabaseSeeder::class)` from it to combine the two | — the binding itself isn't checked (it can't fail in a way an install-time check would catch); the *data* it seeds is: |
| central `permissions` / `payment_plans` rows | seeded by `numerosis:install` (default; `--no-seed` to skip) or a fresh host's own `db:seed`, via the binding above | run either command | `verifyCentralDataSeeded()` |
| `resources/{css,js}` (published `numerosis-assets`) | not required — `Numerosis::assetTags()` renders the prebuilt `dist/numerosis.js`/`dist/numerosis.css` whenever `resources/js/numerosis.js` hasn't been published | publish + customise (`numerosis.js` imports `stripe-checkout.js`/`stripe-confirm.js` by relative path, both load-bearing for payment — keep the directory together). Once `resources/js/numerosis.js` is also an entry in your `vite.config.js`, your build is used in place of the prebuilt bundle. Override the CSS through `tokens.css`'s custom properties rather than by publishing it | `verifyPublishedAssetsMatchSource()` (warns on drift between a published copy and the vendor original; doesn't fail the install) |
| `payment_plans.metadata.options` (read, never written) | nothing — the limits a plan sells are yours to write | put numeric limits under `options.limits.<capability>`, and `options.max_users` for seats. `Contracts\Billing\Entitlements` reads both | `verifyPlanMetadata()` (fails on a non-numeric limit, which reads as uncapped — the plan is sold as limited and enforces nothing; the same check also runs at boot, on every console command) |
| `payment_plans.metadata.options.meters` (read, never written) | nothing — a plan meters something only if you say so | one entry per metered capability, with `key`, `event_name` and ideally `meter_id`; see the usage-based billing section above | `verifyPlanMetadata()` (fails on a meter missing `key` or `event_name`, which meters nothing and so under-bills; warns when it declares no `meter_id`, which only `billing:reconcile-usage` needs; the same check also runs at boot, on every console command) |
| `numerosis.tenancy.backup.dumpers` (read, never written) | the portable PDO dumper for MySQL, MariaDB and PostgreSQL, and `VACUUM INTO` for SQLite | point a driver at `MysqlBinaryTenantDatabaseDumper` or `PostgresBinaryTenantDatabaseDumper` once a tenant database is too large to read through PDO — those need `mysqldump`/`mysql` or `pg_dump`/`psql` on the machine that runs the backup | `verifyBackupDumper()` (fails when the configured dumper's binary is missing; warns when the artefact carries rows without a schema, which restores into a migrated database only) |
| `activitylog.activity_model` | `Nvade\Numerosis\Models\Activity`, whenever the key still holds Spatie's own model | subclass ours and name yours instead. Spatie's is the one value that fails: it writes a central subject's entry into whichever tenant database happened to be active, against an id from another one | `verifyActivityModel()` (fails on anything that is not that class or a subclass of it) |
| `activitylog.default_except_attributes` | `password`, `remember_token`, `two_factor_secret`, `two_factor_recovery_codes`, whenever the key is still empty | add your own secret-shaped columns to the list; do not shorten it | `verifyActivityLogSecrets()` (fails when any of the four is missing — a logged model otherwise writes the value into the audit log in clear text) |
| `activitylog.table_name` | `'activity_log'`, whenever unset | set it yourself | `verifyActivityLogTable()` (the table has to exist on the central connection; a wrong value otherwise surfaces as `Incorrect table name ''` from `migrate`) |
| `fortify.features` | registration, password reset (following `PasswordResetFeature`), profile updates, password updates, email verification, and two-factor authentication with `confirm` and `confirmPassword` — written whenever `numerosis.auth.manage_fortify_features` is `true` (the default). Passkeys are dropped from that list: no view ships and the column those controllers write does not exist, so leaving them on registers screens that fail only once somebody reaches them | set `numerosis.auth.manage_fortify_features` to `false` and edit `fortify.features` yourself — from that point the list is yours outright, including turning passkeys on (you supply the views and the migration). Toggling password reset alone is better done through `numerosis.features`' `PasswordResetFeature`, so the two configs cannot disagree | `verifyFortifyFeatures()` (refuses passkeys, whose screens have no views or columns here; the backfill itself is covered by `tests/Feature/HostConfigTest.php`) |

### Notes worth keeping in mind

- **`users.email` is nullable, package-wide.** `Models\User::$email` is
  `string|null`, because a social-only account can arrive without one and
  `Actions\Auth\Social\*` links identities on `(provider, provider_id)`
  rather than on email. A host model, its validation rules and anything
  routing notifications by email therefore has to tolerate null —
  `Livewire\Settings\Profile` substitutes `''` for the form field, which is
  the shape to copy.
- **`php artisan route:cache` does not work, and fails loudly.** Fortify's
  route file is loaded once per central domain *and* once inside the tenant
  group (`Numerosis::routes()`), because `guest:<guard>` is baked
  into route middleware at registration time and the two groups need
  different guards. Route *names* are therefore duplicated — `login`,
  `register`, `password.*`, `verification.*` — and Laravel refuses to
  serialize a duplicate name:
  `Unable to prepare route [login] for serialization. Another route has
  already been assigned name [login].` Serving the routes uncached is
  correct: the router matches the domain-scoped copy first on a central
  host, and `route('login')` resolves the domain-less tenant copy, which
  generates a host-relative URL that is right on both. Only the *cache* step
  is unavailable. Leave `route:cache` out of your deploy pipeline;
  `config:cache`, `view:cache` and `event:cache` are unaffected. Removing
  this limitation means one registration behind a context-aware guard (see
  `.ai/rules/auth-login.md`), which is a change to this package, not to your
  app.
- **`numerosis.tenancy.identification.mode` is a deploy-time choice, not a
  runtime toggle.** `subdomain` (default) keeps the original behaviour
  exactly: `{tenant}.{apex}`, a `domains` row per tenant, wildcard DNS.
  `custom_domain` gives each tenant its own fully-qualified host — the
  registration wizard grows a second field, because the tenant's *identifier*
  (which is also its database name) can never be the domain itself. `path`
  serves tenants at `{central_domain}/{tenant}/…` with no DNS and no
  `domains` rows at all. Switching after tenants exist does not migrate the
  ones already provisioned. `path` mode's full request round trip **is**
  covered, by `tests/Browser/PathModeTest` (a real HTTP request into an
  authenticated tenant panel, with stancl's own resolver rebound as the
  negative control). `subdomain` mode's is not, and cannot be from this
  suite — the browser plugin serves Laravel in-process, so
  `runningInConsole()` stays true and the tenant panel's `{tenant}` wildcard
  is always registered; verify that one by hand against a real web server.
  See `.ai/rules/identification-modes.md`.
- **You install no optional packages.** `ryangjchandler/laravel-cloudflare-turnstile`,
  `spatie/laravel-one-time-passwords` and `spatie/laravel-activitylog` are all
  `require`, so `App\Models\User` (or your own subclass) always composes
  `HasOneTimePasswords`/`LogsActivity` directly, with no compat shim involved.
  Turnstile and passwordless (OTP) login are gated by `numerosis.features`'s
  `TurnstileFeature`/`OneTimePasswordFeature`; with Turnstile off,
  `<x-numerosis::turnstile-field />` still resolves and renders nothing, so
  forms need no conditional. Activity logging on `Tenant\User` runs
  unconditionally.
- **Don't name a guard literally `central` alongside `web`.** Two session
  guards over one model meant a user could authenticate under one and not
  the other. `web` *is* the central guard; `numerosis.auth.guards.central`
  is the only indirection needed, and it already defaults to `'web'`.
- **The shared-session trap `session.domain` creates.** Because one session
  spans every subdomain, `SessionGuard` stores nothing but a primary key —
  and tenant user ids are per-database integers. Visiting tenant A (where
  you're id 2) then tenant B (where id 2 is someone else) would authenticate
  the session as that someone else on B, with no error, if nothing else
  intervened. `Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant` is the fix
  (forgets the tenant guard's session key when the session's recorded tenant
  changes) and must stay registered **after** `StartSession` in any stack
  that can reach a tenant route — it already is, inside
  `Numerosis::middleware()`'s `tenant` group; this note is here so a host
  extending that group doesn't reorder it.
- **`config/app.php` needs nothing.** This package reads nothing out of it
  beyond `app.name`/`app.url`/`app.env`, which Laravel defines itself. It
  used to invent five keys there (`app.domain`, `app.host`,
  `app.central.*`) — a package key in a framework file can have no default
  at all, since `mergeConfigFrom()` only ever supplies defaults for a
  package's *own* config file. They live under `numerosis.domains.*` now.
- **`numerosis.models.*` resolves in three steps.** An explicit
  `numerosis.models.<FQCN>` entry wins; failing that, the same class name
  under your app namespace (`App\Models\Central\Tenant` for
  `Nvade\Numerosis\Models\Central\Tenant`) is used when it exists *and*
  extends the package model, so a conventionally-named subclass needs no
  config at all and an unrelated class of that name is ignored; failing both,
  the package's own class. Every model this covers is concrete, so overriding
  is optional, and results are memoized for the lifetime of the process. Read
  this before touching either this table's row or `HostConfig::tenancyModels()`.
- **`#[UseFactory]` is the escape hatch for your own `App\Models\*` classes.**
  `Numerosis::factoryNameFor()` replaces Laravel's *global* factory-name
  resolver (it has to: every factory ships from the package even when the
  model is your subclass), and it answers for any class under a `\Models\`
  namespace — including models of your own that this package knows nothing
  about. `App\Models\User::factory()` therefore resolves to
  `Nvade\Numerosis\Database\Factories\UserFactory`, while `modelNameFor()`
  still instantiates *your* `User` — so the symptom is never a wrong class,
  it's wrong fields (a state method that doesn't exist, a column your
  migrations added that nothing sets). Annotate the model and Laravel's own
  `HasFactory::newFactory()` short-circuits both resolvers:
  `#[UseFactory(\Database\Factories\UserFactory::class)]`.
- **Reclaim `livewire.component_namespaces` from `register()`, not
  `boot()`.** `LivewireServiceProvider::boot()` reads that config once and
  bakes the result into the view finder's hints
  (`app('view')->addNamespace(...)`), so a value set in any provider's
  `boot()` is accepted by the config repository and changes nothing about how
  `numerosis-pages::`/`numerosis-layouts::` actually resolve — you see the right value in
  `config()` and the package's views still win. Laravel registers app
  providers after package providers, so setting it in your own
  `AppServiceProvider::register()` lands after this package's default and
  before Livewire reads it. Confirmed by reflecting the view finder's `hints`,
  not by reading the config back.
- **Test suites need `Nvade\Numerosis\Testing\CleansUpTenancyDatabases`.**
  `RefreshDatabase` transacts the default connection only, so every row
  written through `central` (every `Tenant`, `Domain`, `CentralUser`) survives
  into the next test and collides on a unique key, and every physical tenant
  database a test provisioned outlives the rollback because `CREATE DATABASE`
  is not transactional. Compose the trait into your base test case, call
  `setUpCleansUpTenancyDatabases()` from `setUp()` and `keepDatabaseSchema()`
  after `parent::tearDown()`:

  ```php
  protected function setUp(): void
  {
      $this->setUpCleansUpTenancyDatabases();

      parent::setUp();
  }

  protected function tearDown(): void
  {
      parent::tearDown();

      $this->keepDatabaseSchema();
  }
  ```

  Under Orchestra Testbench the `setUp…()` call is optional, since Testbench
  calls `setUp{TraitName}` for every composed trait itself, and calling it
  anyway is a no-op — so one base class copies between a Testbench harness
  and a plain-Laravel host unchanged. Ordering is handled inside the trait on
  purpose:
  `beforeApplicationDestroyed()` *appends* on
  `Illuminate\Foundation\Testing\TestCase` and `array_unshift`es on
  Testbench's, so the same registration lands on opposite sides of
  `RefreshDatabase`'s rollback depending on the base class — hand-rolled
  copies of this cleanup get that backwards about half the time, and a
  cleanup that runs on the wrong side either blocks on the test's own row
  locks or drops a database the rollback then reconnects to.
- **A published `resources/css/app.css` has to keep every `@source` line it
  came with, the Flux one especially.** Flux's real component templates
  live under `vendor/livewire/flux/stubs/resources/views/flux/**` (loaded
  through `Blade::anonymousComponentPath`, so no ordinary glob reaches
  them) and its own prebuilt `flux.css` does not ship every utility they
  use. Without
  `@source '../../vendor/livewire/flux/stubs/resources/views/**/*.blade.php';`
  a class like `dark:bg-white/10` compiles to nothing at all, and an input
  falls back to opaque white while its text stays light — near-white on
  white, worst in dark mode, with no error anywhere. A copy published
  before 2026-08-12 predates that line; `verifyPublishedAssetsMatchSource()`
  reports the drift, but only as a warning, since editing these files is
  the point of publishing them.
- **`config/cashier.php`, `config/permission.php` and `config/queue.php`**
  are published as-is by their own packages rather than being package
  invariants — nothing to normalize or verify beyond what's already listed
  above.
- **The deep-fill has one blind spot: a key your file still names, in an
  outdated shape.** It only ever backfills a key that's entirely *missing*
  from your file — a top-level key renamed or restructured since you last
  looked at this file is invisible to it, since your old key is still
  present, just wrong. There is no version guard against this; compare your
  file against the package's own `config/numerosis.php` after an upgrade.

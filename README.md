# Numerosis

A multi-tenant SaaS foundation for Laravel 13, shipped as a Composer package.
A host app installs it, changes one line of `bootstrap/app.php`, and gets
tenant signup and provisioning, authentication, team management and Stripe
billing. The host writes only its own product.

Each tenant gets its own database (`stancl/tenancy` v3). A central database
holds tenants, domains, subscriptions and the global user identity.

## What it does

**Tenancy**
- Database per tenant, provisioned on a dedicated queue by a configurable list
  of steps. Each step is recorded on the provision row, so a failed run resumes
  from the first unfinished step and steps need not be idempotent. A
  conditional `UPDATE` on that row is the lock, so no atomic-lock cache driver
  is required.
- Three identification modes: subdomain (default), custom domain with DNS
  rechecks, and path. Each mode has its own browser test suite.
- One session spans every subdomain, and Laravel's session guard stores only a
  user id. Without `EnsureSessionMatchesTenant`, user 2 on tenant A would be
  signed in as whoever is user 2 on tenant B. The middleware closes that.
- Tenant backups, closing a tenant, ownership transfer, and a sweep for
  orphaned tenant databases.

**Auth** (on `laravel/fortify`)
- Registration wizard, email verification, password reset, two-factor auth,
  emailed one-time-code login, social login via Socialite, Cloudflare Turnstile.
- Invitations, member roles, an activity log, and GDPR data export and
  account anonymisation.

**Billing** (on `laravel/cashier` / Stripe)
- Inline Stripe Elements and redirect checkout, saved payment methods, VAT
  numbers, plan swaps, promotion codes and retention offers.
- Seat counts and metered usage, with a reconcile command that checks reported
  usage against Stripe.

**Operations**
- A staff panel (tenants, provisions, queue, subscriptions, users),
  impersonation with an audit row and a 60-minute cap, and a health endpoint
  for uptime monitors.
- An in-app notification centre with per-user channel preferences and
  unsubscribe links.
- A read-only `/api/v1` over Sanctum tokens.

Every optional capability is a `Feature` class listed in config. Removing one
registers no routes, no UI and no notifications, and never deletes data. See
[`docs/features.md`](docs/features.md).

## Engineering

- **PHPStan level 9**, Pint, Rector, and Pest through Orchestra Testbench:
  over 1,100 tests, including Playwright browser tests for each identification
  mode.
- **CI matrix** on GitHub Actions: PHP 8.4 and 8.5 against MySQL, PostgreSQL
  and SQLite, plus a gitleaks secret scan and a check that the prebuilt `dist/`
  bundle matches its sources.
- **Architecture tests** hold the structure: `nvade/numerosis-ui` may not
  depend on core, nothing runs code inside a tenant outside one helper, boot
  code never reads live tenancy state, and Blade class references are checked
  where PHPStan cannot see.
- **One repo, two packages.** Core lives at the root; `packages/ui` (Blade
  components and design tokens) is published as a read-only split on every tag.

Some decisions, and what they replaced:

- The package had grown into six Composer packages, a Filament admin suite and
  a per-tenant module system, and its auth screens were a fork of a Laravel
  starter kit that no longer got upstream fixes. It was cut back to two
  packages around four pillars (tenancy, auth, billing, onboarding), with auth
  moved onto Fortify's own extension points.
- Support for the unreleased `stancl/tenancy` v4 was built alongside v3:
  27 runtime branches, 427 lines of hand-written PHPStan stubs, two baselines
  and a four-job CI matrix. It caught one bug and protected no host, so it was
  deleted and the package is v3-only.
- Non-obvious traps are written down as they are found, in `.ai/rules/`, one
  file per topic (provisioning, session isolation, tenant caching, the boot
  order of package service providers). Three production bugs came from work
  done one boot phase too early or too late; the rules explain each.

## Quick start

Requires PHP 8.4+, Laravel 13, MySQL 8.4+ / MariaDB / PostgreSQL 13+ (SQLite
works for development), a queue worker on the `provisioning` queue, and Stripe
keys.

```php
// bootstrap/app.php
use Nvade\Numerosis\Numerosis;

return Numerosis::configure(
    basePath: dirname(__DIR__),
    commands: __DIR__.'/../routes/console.php',
)->create();
```

```bash
php artisan migrate
php artisan vendor:publish --tag=numerosis-public-assets
php artisan numerosis:install   # publishes stubs, seeds central data, verifies the host
```

`numerosis:install --verify-only` checks the host's configuration without
changing anything and names the exact key that is wrong.

The package is not on Packagist. [`docs/installation.md`](docs/installation.md)
covers installing from a path or VCS repository and adopting the package into
an existing app.

## Documentation

| Document | What it answers |
|---|---|
| [`docs/installation.md`](docs/installation.md) | Requirements, Composer setup, adopting into an existing app |
| [`docs/architecture.md`](docs/architecture.md) | How the package boots, what happens on a central vs. tenant request, where code lives |
| [`docs/features.md`](docs/features.md) | Every feature class and what turning it off costs |
| [`docs/extending.md`](docs/extending.md) | How a host adds routes, migrations, seeders and permissions, and customises auth |
| [`docs/host-requirements.md`](docs/host-requirements.md) | What a host must provide, and every config key the package normalises |
| [`docs/api.md`](docs/api.md) | The read-only `/api/v1` surface |
| [`CHANGELOG.md`](CHANGELOG.md) / [`UPGRADING.md`](UPGRADING.md) | Release notes and breaking changes |

## Repo layout

```
src/                    core: tenancy, auth, billing, provisioning
packages/ui/            Blade components + design tokens, split on tag
config/numerosis/       every setting, one file per top-level key
routes/                 web.php (central) · tenant.php
database/migrations/{central,tenant}/
resources/              views, translations, JS/CSS sources
dist/                   prebuilt JS/CSS, so a host need not build
stubs/                  publishable host model subclasses
workbench/              Testbench dev harness
tests/                  Pest: Feature, Unit, Browser
```

## Development

```bash
composer test       # Pest, via Testbench
composer analyse    # PHPStan level 9
composer format     # Pint
composer lint       # both
composer serve      # boot the workbench app
```

No Sail and no `.env`: this repo is a package. Browser tests need
`npx playwright install chromium` once.

## License

Proprietary. The source is visible for review; see [`LICENSE.md`](LICENSE.md).

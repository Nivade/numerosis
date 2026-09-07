# Numerosis

Multi-tenant SaaS foundation for Laravel. Tenancy, Fortify-backed auth and
billing are the required core; invitations and the registration wizard are
feature-gated but always installed.

**Proprietary — not published to Packagist.** Install from a path or VCS repo.

## Documentation

| Document | What it answers |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | How the package boots, what happens on a central vs. tenant request, where code lives |
| [`docs/features.md`](docs/features.md) | Every feature class, which package owns it, what turning it off costs |
| [`docs/extending.md`](docs/extending.md) | How a host contributes routes, migrations, seeders, permissions, and customizes auth through Fortify |
| [`docs/host-requirements.md`](docs/host-requirements.md) | The things a host must provide, and every config key the package normalizes for you |

`.ai/rules/` holds non-obvious traps and invariants by topic (start at
`.ai/rules/INDEX.md`). `.claude/plans/` is historical — nothing there is
authoritative about current behaviour.

## What you install

Two Composer packages, developed in this one repository, published as
read-only splits on tag — collapsed from six (`.claude/plans/archive/humming-nibbling-flame.md`).
Both are effectively required.

| Package | What it is |
|---|---|
| `nvade/numerosis` | Tenancy, Fortify-backed auth, billing, provisioning, the onboarding wizard, every migration and seeder |
| `nvade/numerosis-ui` | Shared Blade layer (`<x-numerosis::ui.*>`), design tokens, `livewire/flux`. Core requires it |

Auth screens, routes and session handling are `laravel/fortify`'s — core
supplies the tenancy-aware actions and views. Admin/tenant Filament panels
were deleted outright; `filament/filament` is not a dependency of anything
here. Core ships the tenant landing page and the account screens; a host
wanting an admin UI builds its own against the package's models and
policies.

See [`docs/host-requirements.md`](docs/host-requirements.md) §0 for detail.

## Requirements

- PHP 8.4+
- Laravel 13
- MySQL — tenancy needs `CREATE DATABASE`; SQLite cannot host it
- A queue worker on the `provisioning` queue
- Stripe keys, and DNS matching your identification mode (`subdomain` by
  default — see `.ai/rules/identification-modes.md`)

## Install

```jsonc
// your app's composer.json
"repositories": [
    { "type": "path", "url": "../numerosis",            "options": { "symlink": true } },
    { "type": "path", "url": "../numerosis/packages/*", "options": { "symlink": true } }
],
"require": { "nvade/numerosis": "@dev" }
```

Composer path repositories are not transitive, which is why a host names
`packages/*` itself rather than inheriting it from core — `packages/ui` is
what that glob resolves to today.

Adopting numerosis into an app you already have changes one line of
`bootstrap/app.php` — `web:` becomes `using:`, because the package needs
per-central-domain route groups and that is the one thing `web:` cannot
express. Your `routes/web.php`, `routes/tenant.php` and `routes/api.php` all
still load, and whatever you configure after `Numerosis::middleware()` wins:

```php
// bootstrap/app.php
use Nvade\Numerosis\Numerosis;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(using: Numerosis::routes(...), commands: __DIR__.'/../routes/console.php')
    ->withMiddleware(function (Middleware $middleware) {
        Numerosis::middleware($middleware);
        // yours, unchanged
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Numerosis::exceptions($exceptions);
        // yours, unchanged
    })
    ->create();
```

For a greenfield app, `Numerosis::configure()` is the same three calls in one
line:

```php
return Numerosis::configure(
    basePath: dirname(__DIR__),
    commands: __DIR__.'/../routes/console.php',
)->create();
```

Write the chain out to reach `health:` or `then:`. Never pass a callable
`then:` alongside `using:` — `withRouting()`'s guard overwrites `$using` with
its own callback and discards the package's routing entirely.

Set `APP_URL`, `STRIPE_KEY` / `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET` and
MySQL credentials in `.env`, then:

```bash
php artisan migrate
php artisan vendor:publish --tag=numerosis-public-assets   # prebuilt CSS/JS
php artisan numerosis:install   # publishes stubs, seeds central data, verifies the rest
```

`numerosis:install --verify-only` re-runs every check without publishing or
seeding. It is the executable copy of `docs/host-requirements.md`.

## Repo layout

```
src/                    core: tenancy, Fortify-backed auth, billing
packages/ui/            the one other split — shared Blade + design tokens
config/numerosis/       every knob, one file per top-level key
config/numerosis.php    assembles those partials — never published
config/stubs/           the small override file a host publishes instead
routes/                 web.php (central) · tenant.php
database/migrations/{central,tenant}/
resources/              views, translations, JS/CSS sources
dist/                   prebuilt JS/CSS, shipped so a host need not build
stubs/                  publishable host model subclasses
workbench/              Testbench dev harness — not a deploy target
tests/                  Pest: Feature, Unit, Browser
```

The deployable host is a separate repo, `numerosis-thin-app`, checked out as a
sibling directory. `saas-m` is the frozen monolith this was extracted from.

## Development

```bash
composer test       # Pest, via Testbench
composer analyse    # PHPStan level 9
composer format     # Pint
composer lint       # both
composer serve      # boot the workbench app
```

No Sail, no `.env`, no `app/` — this repo is a package. Browser tests need
`npx playwright install chromium` once.

# Numerosis

Multi-tenant SaaS foundation for Laravel. Tenancy and billing are the required
core; auth screens, invitations, Filament panels, the registration wizard and
the module system are opt-in.

**Proprietary — not published to Packagist.** Install from a path or VCS repo.

## Documentation

| Document | What it answers |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | How the package boots, what happens on a central vs. tenant request, where code lives |
| [`docs/features.md`](docs/features.md) | Every feature class, which package owns it, what turning it off costs |
| [`docs/extending.md`](docs/extending.md) | How a host or a satellite package contributes routes, migrations, seeders, permissions, panels |
| [`docs/host-requirements.md`](docs/host-requirements.md) | The things a host must provide, and every config key the package normalizes for you |

`.claude/rules/` holds non-obvious traps and invariants by topic (start at
`.claude/rules/INDEX.md`). `.claude/plans/` is historical — nothing there is
authoritative about current behaviour.

## What you install

Five Composer packages, developed in this one repository, published as
read-only splits on tag. Core is the only one you must have; declining any of
the other four is a supported state, not a degraded one.

| Package | What it is |
|---|---|
| `nvade/numerosis` | Tenancy, billing, provisioning, auth mechanics, the module system, every migration and seeder |
| `nvade/numerosis-ui` | Shared Blade layer (`<x-numerosis::ui.*>`), design tokens, `livewire/flux`. Core requires it |
| `nvade/numerosis-filament` | Admin + tenant Filament panels, resources, module marketplace. Pulls in `filament/filament` |
| `nvade/numerosis-auth-ui` | Login / register / password-reset / OAuth screens, `laravel/socialite` |
| `nvade/numerosis-onboarding` | Self-serve registration wizard at `/get-started` |

What declining each one costs is listed per package in
[`docs/host-requirements.md`](docs/host-requirements.md) §0.

## Requirements

- PHP 8.4+
- Laravel 13
- MySQL — tenancy needs `CREATE DATABASE`; SQLite cannot host it
- A queue worker on the `provisioning` queue
- Stripe keys, and DNS matching your identification mode (`subdomain` by
  default — see `.claude/rules/identification-modes.md`)

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
`packages/*` itself rather than inheriting it from core.

```php
// bootstrap/app.php
use Nvade\Numerosis\Support\Numerosis;

return Numerosis::configure(basePath: dirname(__DIR__))->create();
```

Set `APP_URL`, `STRIPE_KEY` / `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET` and
MySQL credentials in `.env`, then:

```bash
php artisan migrate
php artisan filament:assets     # only if you kept nvade/numerosis-filament
php artisan numerosis:install   # publishes stubs, seeds central data, verifies the rest
```

`numerosis:install --verify-only` re-runs every check without publishing or
seeding. It is the executable copy of `docs/host-requirements.md`.

## Repo layout

```
src/                    core: tenancy, billing, auth mechanics, modules
packages/{ui,auth-ui,filament,onboarding}/   the four satellite splits
config/numerosis.php    every knob, one file (map in docs/architecture.md)
routes/                 web.php (central) · tenant.php · channels.php
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

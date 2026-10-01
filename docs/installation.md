# Installing numerosis

Full install and adoption guide. What a host must provide once installed is in [`host-requirements.md`](host-requirements.md).

## Requirements

- PHP 8.4+
- Laravel 13
- MySQL 8.4+, MariaDB, or PostgreSQL 13+. SQLite runs too, for development
  and small deploys: it allows one writer per database file, and the central
  database is shared by every tenant
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

A host that is not a sibling checkout installs tagged releases straight from
git. GitHub has no Composer registry, so both repositories are named as `vcs`
repositories; `numerosis-ui` is separate because Composer does not resolve a
dependency through another package's `repositories` block:

```jsonc
"repositories": [
    { "type": "vcs", "url": "git@github.com:Nivade/numerosis.git" },
    { "type": "vcs", "url": "git@github.com:Nivade/numerosis-ui.git" }
],
"require": { "nvade/numerosis": "^0.2" }
```

Both repositories are private, so Composer needs credentials. An SSH key with
read access to both is enough for a developer machine. A deploy host is better
served by a token, which belongs to the project rather than to a person:

```bash
composer config --global --auth github-oauth.github.com <token>
```

A fine-grained token needs `Contents: read` on both repositories. With a token
in place Composer fetches dist zips through the GitHub API rather than cloning,
which is the faster of the two.

`nvade/numerosis-ui` resolves against the mirror the `split-ui` workflow
pushes, so a tag is only installable once that workflow has carried it across.

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
your database credentials in `.env`, then:

```bash
php artisan migrate
php artisan vendor:publish --tag=numerosis-public-assets   # prebuilt CSS/JS
php artisan numerosis:install   # publishes stubs, seeds central data, verifies the rest
```

`numerosis:install --verify-only` re-runs every check without publishing or
seeding. It is the executable copy of `host-requirements.md`.


# Numerosis

Multi-tenant SaaS foundation for Laravel. Tenancy + billing form the required
core; auth flows, invitations, Filament panels, registration wizard and the
module system are opt-in through feature classes.

**Proprietary — not published to Packagist.** Install from a path or VCS repo.

## Status

Extracted from the `saas-m` monolith. See
`.claude/plans/post-extraction-review.md` (this repo) for remaining work and
the repo layout:

| Repo | Role |
|---|---|
| `numerosis` | this package |
| `thin-app` | the deployable app that requires it |
| `saas-m` | frozen, pending archive; source of the extraction |

## Requirements

- PHP 8.4+
- Laravel 13
- MySQL (tenancy needs `CREATE DATABASE`; sqlite cannot host it)

## Install (development)

```jsonc
// thin-app/composer.json
"repositories": [
    { "type": "path", "url": "../numerosis", "options": { "symlink": true } }
],
"require": { "nvade/numerosis": "@dev" }
```

## Development

```bash
composer test      # Pest, via Testbench
composer analyse   # PHPStan level 9
composer format    # Pint
composer serve     # boot the workbench app
```

The workbench app under `workbench/` is a dev harness, not a deploy target —
`thin-app` is what gets deployed.

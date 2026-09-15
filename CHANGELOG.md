# Changelog

All notable changes to `numerosis` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions before `0.1.0` were never tagged — every host up to and including
this release consumed the package via a `dev-main` path repo, so there is no
prior tag to diff against. See `UPGRADING.md` for the one breaking change
that predates this tag and still matters to a host updating from an
untagged checkout.

## [Unreleased]

### Changed

- `domains.created_at` and `updated_at` carry microseconds, and
  `Models\Central\Domain` writes them with matching precision.
  `Tenant::primaryDomain()` returns the most recently added domain, which two
  domains added in the same second could not decide before — the answer came
  back in whatever order the driver chose.
- `Permission::$ability` and `Permission::$context` are accessors splitting
  `name` rather than generated columns, and
  `2025_12_17_035929_add_ability_and_context_virtual_columns_to_permissions`
  is deleted. **Breaking for a host that queries either column**, since they
  no longer exist in the database and cannot appear in a `where`, `orderBy`
  or `groupBy`. Reading them off a model is unchanged, and they now resolve
  on the central connection too, where they had always been null.

### Removed

- Filament (both panels, `packages/filament`, `filament/filament` itself),
  the module system and its marketplace (`internachi/modular`,
  `Models\Central\ModuleOffering`, the three `tenants:*-module` commands),
  and tenant-user impersonation. 0.1.0 shipped all three; none is coming
  back, and `tests/Feature/PackageBoundariesTest.php` fails on a `Filament\`
  symbol anywhere in the tree.

## [0.1.0] - 2026-08-28

First tagged release. Extracted from the `saas-m` monolith
(`.claude/plans/archive/package-extraction.md`) and hardened against a second real
consumer, `tabellio` (`.ai/rules/host-integration-quickstart.md`).
Baseline for future entries in this file — not a reconstructed history of
every change since extraction began; consult `git log` for that detail.

Notable since extraction:

- Multi-tenant foundation (stancl/tenancy) with a shared auth guard,
  Filament admin + tenant panels, Cashier billing, module marketplace,
  passwordless login, and an opt-in feature-flag system
  (`config('numerosis.features')`).
- `HostConfig::apply()` normalizes tenancy/auth/filesystem/cache config for
  a host, with a narrowed `verify*()` check per normalization surfaced
  through `numerosis:install --check`.
- `docs/host-requirements.md` documents every config key `HostConfig`
  touches; `tests/Feature/Docs/HostRequirementsTest.php` fails the suite if
  a key is added there without a matching doc row.
- `Nvade\Numerosis\Testing\CleansUpTenancyDatabases` ships the
  central-write/tenant-database test teardown a host needs under
  `RefreshDatabase`, handling the `beforeApplicationDestroyed()` ordering
  difference between plain Laravel and Testbench internally.
- `Numerosis::routes(withAuth: false)` lets a host keep the package's
  billing/tenancy/checkout routes while running its own auth system,
  without a routes/auth.php name collision.

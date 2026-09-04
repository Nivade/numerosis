# Changelog

All notable changes to `numerosis` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions before `0.1.0` were never tagged — every host up to and including
this release consumed the package via a `dev-main` path repo, so there is no
prior tag to diff against. See `UPGRADING.md` for the one breaking change
that predates this tag and still matters to a host updating from an
untagged checkout.

## [Unreleased]

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

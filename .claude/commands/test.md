---
description: Run tests related to current changes
---

# Test — Run Related Tests

This is a package, not an app — no `artisan`, no `app/`. Tests run through
Pest + Orchestra Testbench against `workbench/`.

## Usage

```
/test [path] [--all]
```

## Commands

```bash
composer test                              # full suite, parallel
composer test-serial                       # full suite, serial (debugging flaky/order-dependent failures)
composer test:impact                       # only tests touching uncommitted changes (pest --dirty)
composer test-browser                      # Browser suite only — needs Playwright, see .ai/rules/testing.md
vendor/bin/pest tests/Feature/FooTest.php  # specific file
vendor/bin/pest --filter=testCreatesUser   # filtered
```

Run the narrowest set that covers the change. Source is under `src/` and
`packages/*/src/`, not `app/` — map `src/Actions/Foo/Bar.php` to
`tests/Feature/Actions/Foo/BarTest.php`, falling back to `tests/Unit/`; a
change under `packages/filament/src/**` maps to
`packages/filament/tests/**` instead.

## Workflow

1. `git diff --name-only HEAD` for changed files.
2. Map to test paths per above, or reach for `composer test:impact` directly.
3. Re-run after each fix.

## Preconditions

Browser tests need `npx playwright install chromium` first
(`.ai/rules/testing.md`) — only relevant for `composer test-browser` or
`--testsuite=Browser`, not the default suites.

## No tests found

Suggest a new test and offer to write it, following an existing sibling
test's structure. Do not delegate to a subagent (`.ai/rules/subagents.md`).

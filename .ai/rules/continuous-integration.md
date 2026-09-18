---
paths:
  - '.github/**'
---
# Continuous Integration

## `A && '' || B` in a workflow expression always yields B (found 2026-09-18)

GitHub Actions `&&` returns its right operand only when that operand is
truthy, and the empty string is falsy. So this:

```yaml
run: vendor/bin/pest --ci ${{ matrix.database == 'sqlite' && '' || '--parallel' }}
```

evaluates to `--parallel` for **every** cell including SQLite, because
`true && ''` is `''` and `'' || '--parallel'` then falls through. The guard
reads correctly and does nothing.

It shipped that way from the port to GitHub Actions and ran the SQLite cell
in parallel for its whole life. The symptom was not a CI error, it was test
flakiness: eight processes over one directory of SQLite files, failing in a
different place each run (`no such table: permissions` in
`TenantClosureTest`, `no such table: users` in `ApiTokenTest`, both on the
tenant connection), none of it reproducible locally, including under the
failing run's own random-order seed.

The fix is to put the non-empty value on the truthy side:

```yaml
run: vendor/bin/pest --ci ${{ matrix.database != 'sqlite' && '--parallel' || '' }}
```

The same trap applies to any expression-valued matrix key. `.github/workflows/tests.yml`
selects a smaller matrix on pull requests with `fromJSON`, and both branches
there return non-empty arrays deliberately: a `fromJSON('[]')` branch would
be falsy and collapse the same way.

Related: an expression-valued `include:` has to inline JSON, and the colons
in `[{"php": "8.5"}]` are not parseable as YAML. Express the extra cell as a
static `exclude:` block against a wider list instead.

### Suggested better approach

Nothing in a workflow expression is type-checked, and a wrong one degrades to
a plausible string rather than failing. Two cheap structural fixes, in order
of value:

1. Move the decision into the shell, where it is testable:
   `[ "$DRIVER" = sqlite ] || PARALLEL=--parallel`. A bash conditional can be
   run locally against both values in a second; a GitHub expression can only
   be tested by pushing.
2. Assert the resolved command. Echoing the final `pest` invocation into the
   job log costs nothing and makes this class of bug visible on the first run
   instead of reading as flakiness for months.

The general rule: prefer a shell conditional over a `${{ }}` ternary whenever
either branch can be empty.

## CI here is advisory, by decision (2026-09-18)

The repository is private on the GitHub Free plan, where branch protection is
unavailable (`gh api repos/.../branches/main/protection` returns `403 Upgrade
to GitHub Pro`). There are no required status checks and no rulesets, so a
red `main` is not blocked and `split-ui` will publish the mirror from it
regardless.

This is settled, not a gap waiting to be filled: staying private is the
decision, and Pro has been considered and declined. Do not propose branch
protection or required checks again. If publishing off a red `main` becomes
the problem worth solving, gate `split-ui` on a `workflow_run` conclusion,
which needs no plan upgrade.

The practical consequence for anyone pushing here: the workflows tell you
what broke, they do not stop you merging it, so read them.

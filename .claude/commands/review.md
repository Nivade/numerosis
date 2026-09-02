---
description: Review current changes for code quality and issues
---

# Review — Code Quality Check

## Usage

```
/review [path]
```

No path → all uncommitted changes: `git diff && git status --short`.

## Automated checks

```bash
composer lint          # Pint (fixes in place) + PHPStan level 9 + baseline
composer refactor:check # Rector, dry-run only — never run plain `refactor` unsupervised
composer test           # Pest, parallel
```

`refactor:check` will flag real Rector debt (57 files as of 2026-09-02, tracked
separately) — that is expected noise, not something to fix as part of this
review. Only flag a refactor finding if it's directly relevant to the diff
being reviewed. **`src/Support/Domains.php` is permanently excluded** —
`rector.php` skips it because a past Rector pass rewrote its deliberate raw
superglobal reads into a facade call and crash-looped the app at boot, before
facades are registered (`.ai/rules/package-host-bootstrap.md`). If a diff
touches that file, review it by hand — Rector has nothing useful to say there.

`composer lint`'s PHPStan step is **currently red for a pre-existing reason,
not because of your diff**: as of 2026-09-02 it reports 57 errors — 34 are
`ignore.unmatched`/`ignore.count` (stale baseline entries), the other 23 are
the same `Cannot cast array|bool|float|int|string|null to string` message,
which matches the documented Larastan nullable-narrowing false-positive
family (`.ai/rules/static-analysis.md`) but was not confirmed as such (needs
the worktree/previous-commit bisection that file describes). **Do not
regenerate the baseline to make this pass** — the rule file is explicit that
doing so buries whatever this actually is. Until triaged, treat a `composer
lint`/`composer test` failure as inconclusive on its own — check whether the
same 57 appear on a clean checkout before attributing any of it to the diff
under review.

## Manual checks

**Placement first.** Is the change genuinely package-side, or should it live
in a satellite package under `packages/*` (`.ai/rules/package-boundaries.md`)?

- **Credentials** — hardcoded `password =`, `api_key`/`secret`/`token` literals.
- **N+1** — relationship access inside a loop without `with()`.
- **Authorization** — `authorize()` before mutations, gates on sensitive routes.
- **Tenancy** — cross-tenant leakage via cached models or shared cache keys.
- **Optional dependencies** — an eager `implements`/`use trait` against an
  optional package breaks a host that lacks it
  (`.ai/rules/optional-dependencies.md`).

## Severity

| Level | Examples |
|---|---|
| Critical | SQL injection, missing auth, cross-tenant data leak |
| High | N+1 in a loop, no validation, eager optional dependency |
| Medium | Missing types, unclear naming |
| Low | Style, docs |

**Fail** on any Critical or High.

## Output

One line per finding: `path:line — [SEVERITY] problem. Fix.`
Skip formatting nits pint already handles.

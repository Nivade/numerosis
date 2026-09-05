---
description: Commit changes, push, and create PR
---

# Ship — Commit, Push, Create PR

## Usage

```
/ship [message]
```

Message optional; generate from the diff if omitted (imperative mood, 1–2
sentences).

## Workflow

1. **Safety.** `git branch --show-current`. **Abort if on `main`** — ask for
   a feature branch.

2. **Diff review.** `git diff --stat`. **Abort on sensitive files**: `.env`,
   `.env.*`, `*.pem`, `*.key`, `credentials.json`.

3. **Quality gate.**
   ```bash
   composer lint    # Pint (fixes in place) + PHPStan level 9 + baseline
   composer test    # Pest, parallel
   ```
   On failure: abort. Override only on an explicit "ship anyway". Do not run
   `composer refactor` (apply mode) as part of this gate — `refactor:check`
   currently flags 57 files of pre-existing debt unrelated to any one change;
   applying it here would bundle unrelated rewrites into the commit.

   **`composer lint`'s PHPStan step is currently red for a pre-existing
   reason** (57 errors as of 2026-09-02, 34 stale-baseline noise + 23 matching
   a known Larastan false-positive family — not confirmed new,
   `.ai/rules/static-analysis.md`). **Do not regenerate the baseline to force
   this green** — the rule file is explicit that doing so buries whatever
   this actually is. If the count is unchanged from before your diff, treat
   the gate as inconclusive rather than blocking on it; if your diff changed
   the count, that's real signal — investigate before shipping.

4. **Commit.** Stage deliberately — prefer named paths over `git add .`.
   Commit message ends with:
   ```
   Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
   ```

5. **Push.** `git push -u origin HEAD`.

6. **PR.** `gh pr create`, body ending with:
   ```
   🤖 Generated with [Claude Code](https://claude.com/claude-code)
   ```
   Return the URL.

Committing and pushing are outward-facing — confirm with the user before
step 4 unless they already asked to ship.

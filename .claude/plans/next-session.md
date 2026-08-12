# Next session — pick up here

**Overwrite this file at the end of every session; don't append.** It's a
dispatch note, not a plan — the actual plans live in their own files.

## State at handoff, 2026-08-12

- `numerosis` — dirty, uncommitted: `.claude/rules/filament-tenancy.md`
  (new bullet) and `.claude/plans/post-extraction-review.md` (root-cause
  writeup for the central-domain routing finding). Working tree otherwise
  matches `main`, which is clean and pushed.
- `thin-app` — dirty: `tests/Feature/ExampleTest.php`'s docblock rewritten
  to match the same root cause (was speculating "worth a real fix later";
  now correctly says it's by-design, not a bug). Also has unrelated,
  not-mine changes from the user installing `laravel/boost` this session
  (`composer.json`/`composer.lock`, `CLAUDE.md`, `.claude/skills/laravel-best-practices/rules/*`,
  new `.mcp.json`/`boost.json`/`.claude/skills/infer-conventions/`) — don't
  bundle those into any commit for the routing finding, they're the user's
  own, separate work.

## What this session did

Root-caused the open finding from the 2026-08-11 handoff: "central-domain
HTTP routes unreachable from any console-dispatched request." **It is not a
bug.** `NumerosisTenantPlugin::shouldRegisterPanel()`
(`src/Filament/NumerosisTenantPlugin.php`) deliberately keeps the tenant
panel registered on a central-domain request whenever
`app()->runningInConsole()` is true — exempting every console entrypoint
(artisan, Pest, tinker) so `route:list`/queue workers/tenant tests still see
the panel. `runningInConsole()` is `PHP_SAPI === 'cli'`; real HTTP
(`php-fpm`, and even PHP's built-in `php -S` server — confirmed both,
`cli-server` SAPI) reports something else, so the plugin correctly skips
itself there and central's own `/` resolves. Confirmed empirically: same
`Request` object, dispatched through literal `public/index.php` code,
resolves to the central route under `php -S` and to the tenant wildcard
under plain `php`/`artisan tinker` — only `PHP_SAPI` differs between them.

Full writeup: `.claude/rules/filament-tenancy.md`'s new bullet. Plan file
(`post-extraction-review.md`) and thin-app's `ExampleTest.php` docblock
updated to match — previously both described this as an unsolved mystery
("root cause not found", "worth a real fix later"); both were wrong about
which side was buggy (assumed console was correct and HTTP was the
anomaly — it's the reverse).

**Consequence for Phase 5.3**: its "central routes bound per
`tenancy.central_domains`" assertion can never be a plain Pest
HTTP-dispatch test — that will always see the tenant panel registered
(console SAPI) and always resolve the wildcard, regardless of what
`tenancy.central_domains` actually contains. Two ways forward, neither
implemented yet:
1. A Pest **browser** test (real request through the actual web server,
   where `runningInConsole()` is genuinely false) — this also happens to be
   exactly the mechanism Phase 5.4 already needs, so worth doing 5.3's
   route assertion as part of that same browser-test investment rather than
   a separate HTTP-dispatch attempt.
2. A narrower unit test against `NumerosisTenantPlugin::shouldRegisterPanel()`
   directly — stub `runningInConsole()` false (e.g. via a partial mock or
   by extracting the console-check to something injectable), assert it
   returns `false` for a central-domain request. Faster to write, doesn't
   prove routing end-to-end the way a browser test would.

## Next-step menu

1. **Write Phase 5.3** using one of the two approaches above — no longer
   blocked, just needs the test written. Recommend browser-test route
   (option 1) since it doubles as groundwork for 5.4.
2. **Phase 5.4** — Pest browser test for the provisioning gate (register →
   provisioning chain → `provisioned_at` set → tenant panel login). Needs a
   live worker on the `provisioning` queue.
3. **`design-system-unification.md` Phase 7`** — keyboard-nav + mobile-width,
   needs a real browser pass. Independent of 1/2.

Commit the two rules/plan-file updates from this session (numerosis) before
starting new work — they're small, documentation-only, and unrelated to
whatever's picked up next.

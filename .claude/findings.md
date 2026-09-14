# Findings

- 2026-09-14: tests/Feature/View/Components/PlanCardTest.php failed once under `composer test` (--parallel) — risk — not reproduced in 30 consecutive runs; popular-slug cache read ruled out, stray central rows and cross-worker factory collisions still open (`.ai/rules/testing.md`); capture the output next time
- 2026-09-14: all 9 browser tests failed together in 1 of 30 `composer test` runs on `file_get_contents(vendor/pestphp/pest-plugin-browser/.temp/playwright-server.json)` — risk — reproduced once, cause (write/read race on the plugin's per-run temp file) not confirmed
- 2026-09-14: no second-consumer smoke test exists (`laravel new` → path repo → `numerosis:install --verify-only`, by docs alone) — risk — last open item of the archived post-extraction-review.md; thin-app's smoke-test.yml boots a hand-configured host, so the documented path is unproven

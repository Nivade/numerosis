# Findings

- 2026-09-14: tests/Feature/View/Components/PlanCardTest.php failed once under `composer test` (--parallel) and passed on rerun and in isolation — risk — order/parallel-dependent flake, not reproduced or investigated
- 2026-09-14: no second-consumer smoke test exists (`laravel new` → path repo → `numerosis:install --verify-only`, by docs alone) — risk — last open item of the archived post-extraction-review.md; thin-app's smoke-test.yml boots a hand-configured host, so the documented path is unproven

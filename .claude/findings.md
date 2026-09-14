# Findings

- 2026-09-14: numerosis:install tests publish package assets into the Testbench skeleton under vendor/ and never clean up (tests/Feature/Console/Commands/InstallNumerosisCommandTest.php:397), so any later edit to resources/js/* or resources/css/* fails verifyPublishedAssetsMatchSource with a message blaming published-asset drift rather than stale vendor residue — bug — worked around on feat/cache-audit by deleting the stale stripe-*.js copies; the real fix (publish to a temp target, or clean up in a finally) is outside that branch.

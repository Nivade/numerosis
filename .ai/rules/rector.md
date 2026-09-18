---
paths:
  - 'rector.php'
---
# Rector

- **A path-string guard goes vacuous, not red, when the path moves.** A skip entry naming a rule class is checked by PHP — rename the class and the `use` statement fatals. A skip entry naming a path is checked by nothing: rector globs it against the files it scanned, and zero matches is a normal, silent outcome.

  `src/Support/Domains.php` was skipped to stop `ServerVariableToRequestFacadeRector` re-applying the superglobal-to-facade rewrite that crash-looped every worker on 2026-08-06 (`package-host-bootstrap.md`, first bullet). `src/Support/` was deleted 2026-09-11 and the file moved to `src/Boot/`; the skip entry kept naming the old path, so the guard sat vacuous for three days while rector ran green. Nothing reported it — it surfaced only because someone read the config.

  **Use `withSkipPath()` for a path, never a raw path inside `withSkip()`.** It runs `Assert::fileExists`, so a moved file fails the rector run itself with `The path "…" does not exist`. `tests/Feature/RectorSkipPathsTest.php` loads the config (firing those assertions) and separately walks the skip list for a path re-added to `withSkip()`, which does not assert. Both halves were confirmed to fail when broken.

  Same failure family as the vacuous directory-scanning tests in `testing.md` and `package-split.md`, in a non-test file that neither of their globs covers.

- **Eleven rules are skipped for producing wrong or uncompilable output on this codebase**, each with its reason inline in `rector.php`. Verify against the named symptom before deleting one. The two that caused real breakage rather than a static-analysis complaint:
  - `ArrayDimFetchToMethodCallRector` rewrites `$app['env'] = 'local'` to `bind('env', 'local')`. `Container::offsetSet()` wraps a non-closure value in a closure; `bind()` does not, so the value is resolved as a class name — `Target class [local] does not exist`.
  - `TriesPropertyToTriesAttributeRector` / `BackoffPropertyToBackoffAttributeRector` delete the `$tries`/`$backoff` declarations that `RunProvisioningStep::handle()` assigns at runtime for `ControlsItsOwnRetries` steps, silently breaking the per-step override.

- **`withComposerBased(laravel: true)` already version-gates the `LARAVEL_*` upgrade sets**, including Cashier's, against what is actually installed. Listing `LaravelSetList::LARAVEL_130` next to it is redundant and pins a version the composer constraint will outgrow.

- **`withSetProviders()` is deprecated and a no-op** — it takes no arguments now and only raises `E_USER_DEPRECATED`. `RectorLaravel\Set\LaravelSetProvider` no longer exists in `driftingly/rector-laravel` (2.6.2); the config named it for months without failing, because `::class` does not autoload.

- **`LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME` rewrites bindings that have no class alias.** The set turns `make('db.transactions')` into `make(DatabaseTransactionsManager::class)`, but `db.transactions` is a plain singleton with no entry in `registerCoreContainerAliases()`, so the class-name form builds a second manager nothing else reads. The rewrite is silent and the suite can stay green while the object is wrong.

  Resolve such bindings by array access (`$this->app['db.transactions']`) plus an `instanceof` check; `ArrayDimFetchToMethodCallRector` is already skipped, so Rector leaves that form alone.

# Browser tests

```bash
npm install
npx playwright install chromium          # ~115 MB, once per machine
php -d memory_limit=1G vendor/bin/pest --testsuite=Browser
```

## Playwright is a prerequisite for the *whole* suite, not just this directory

Installing `pestphp/pest-plugin-browser` makes `npm install` +
`npx playwright install chromium` mandatory for **every** `vendor/bin/pest`
invocation, including one that runs no browser test at all. That is upstream
behaviour, not a choice made here: `Pest\Browser\Plugin::terminate()` calls
`ServerManager::instance()->playwright()->stop()` unconditionally, and
building that server object is what throws
`PlaywrightNotInstalledException`. The run aborts with **no test output and a
non-zero exit** — measured against a `--filter` that selected only a feature
test touching nothing browser-related.

So there is no "skip when unavailable" guard in these files. One was written
and removed: the plugin aborts before any `beforeEach()` can run, so it was
dead code that read as protection — the failure mode
`.ai/rules/auth-login.md` records for `ensureIsNotRateLimited()`.

`.github/workflows/run-tests.yml` installs both.

## How a request reaches a virtual host

The plugin serves the application **in-process**: an amphp socket in front of
the same booted kernel the test holds. Two consequences that shape every test
here.

- **The database harness works unchanged.** A request the browser makes sees
  `RefreshDatabase`'s open transaction and the tenant databases
  `CloneTenantSchema` just built, because it is the same process and the same
  container. A separate `artisan serve` would see neither. `actingAs()` also
  carries into browser requests for the same reason — `GlobalState::flush()`
  resets only Ziggy, Livewire and Inertia.
- **The server always binds `127.0.0.1`, and `LaravelHttpServer::rewrite()`
  discards the host of an absolute URL**, so `visit('http://acme.example/x')`
  is *not* a request to `acme.example`. The only lever is
  `Playwright::setHost()`, which rewrites the inbound `Host` header per
  request. It is global static state, so each test sets it explicitly rather
  than inheriting it.

## What this suite cannot prove

`PHP_SAPI` is still `cli`, so `app()->runningInConsole()` is **true** inside a
browser request. Anything whose behaviour branches on
`app()->runningInConsole()` therefore behaves here as it does in a console
test, not as it does under FPM — that class of question needs a real
FPM/`php -S` server and stays out of reach in this suite.

## Leftover processes

The plugin leaves its `node ./node_modules/.bin/playwright run-server` process
running, and a killed or interrupted Pest run orphans it. An orphan holds the
inherited stdout pipe open, so a `pest ... | tail` pipeline appears to hang
long after PHP has exited — that, not a slow test, is what a "the browser
suite hung for three minutes" report usually is. Redirect to a file rather
than piping, and clean up with:

```bash
pkill -f "playwright run-server"
```

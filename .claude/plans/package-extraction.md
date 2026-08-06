# Plan: Split saas-m into `nvade/numerosis` (package) + `thin-app` (deployable)

**Audience: an executing agent with no prior context.** Every step states the
repo, the exact command, what "done" looks like, and what to do when it fails.
Do not improvise past a step's text. When a step says STOP, stop and ask.

**This file is canonical and lives in numerosis** (D11). saas-m and thin-app
hold a pointer, not a copy. Dated session notes belong in
`package-extraction-log.md`, never here — this file is overwritten, that one
is appended to.

Read the **Live status** and **Steps to proceed** blocks below, then the
Decisions section. `Plan review` (R1-R10) holds reasoning for decisions
already taken; R1-R4 are **closed** by D8-D13.

---

## Live status

Overwrite this block; never append to it. Fifteen lines, hard limit.

| | |
|---|---|
| Phase | 10 — end-to-end gate items 3-4 passed live against a real tenant. STOP: archive step (Phase 10's last item) needs explicit user go-ahead, not taken. **Correction (2026-08-06):** Phase 8 was recorded here as done but had not been done *as designed* — no `Filament\Contracts\Plugin` class existed, panel definitions were hand-copied and had already drifted. Fixed properly in `.claude/plans/cleanup-package-extraction.md`'s item A; see that file, not this line, for Phase 8's real state |
| numerosis | `84a9a7d`, clean (no package changes this session) |
| thin-app | `395f08a`, clean. Panels boot (Phase 8), assets build (Phase 9), and a real `StartLocalCheckout` provision was run to completion — see below |
| saas-m | frozen at `c66cc72`; untouched this session |
| Package suite | **0 failed / 373 passed / 7 skipped in ~65s** — measured 2026-08-06 (unchanged this session) |
| PHPStan | numerosis: clean, baseline 252 unchanged. thin-app: clean (1 pre-existing Laravel-generated `ExampleTest` finding, unrelated) |
| Exclusions | 14 `#[Group('thin-app')]` across 11 files, all module-package tests (R2 row 1) |
| Resume at | Phase 10 gate: item 1 (package suite) and item 2 (app boots) already green. Item 3 (register → provision → log into tenant panel) run via `StartLocalCheckout::run()` directly in tinker rather than the browser wizard — found and fixed two real bugs (see below) — landed `tenants.provisioned_at` set, owner's `Tenant\User` row created, tenant subdomain `/login` renders 200. Item 4 (`tenants:migrate-module branding`) landed its migration against the real tenant database; `modules.migrated_at` itself is stamped by the `MigrateModules` job during a module *purchase*, not by this raw command — confirmed against `.claude/rules/module-marketplace.md`, not a gap. Test tenant/user/database were deleted after verification, nothing left behind. **Next: ask the user before archiving saas-m** — the only remaining Phase 10 item, and this document requires an explicit stop there |

Phase 6's three exit conditions (R2) are all satisfied as of `fad542e`:
zero failures, baseline growth traceable, every exclusion traceable. The 7
skips are D9's by-intent Stripe integration tests plus one socialite event
test. Step 2 detail (two real bugs found: Livewire component/view
registration, unnamespaced `billing.*` translations; plus an unrelated
`cache.default` test-harness gap) is in the log, 2026-08-06 entry — don't
re-derive, read there first. Still open in step 2: no `numerosis:doctor`
command (findings became fixes + tests instead); policies/factories/
migrations/commands/morph-map/broadcast-channels checked clean.

Suite prerequisites: `docker compose up -d` (numerosis's own `docker-compose.yml`,
step 5 — no longer `saas-m-mysql-1`; harness points at `127.0.0.1:3306`
regardless of which container answers it), and `vendor/bin/pest`
(`memory_limit=1G` now baked into `phpunit.xml.dist`, no `-d` flag needed).
**If the suite fails oddly after a long-idle local DB, drop and recreate
`testing` before assuming your change is at fault** — a stale schema can
silently diverge from current migration files (`RefreshDatabaseState`'s
migrated-pin means it's never forcibly rebuilt); see the log's cache-table
bug for what that looked like. **A genuinely fresh MySQL volume (no
`tenantphpunittemplate` left over from an older session) is a stronger test
than a recreated `testing` alone** — it's what step 5 used to find a real,
previously-invisible production bug in `SeedTenantDatabase`; see
`.claude/rules/tenant-provisioning.md`'s `Commands\Seed` bullet.

---

## Steps to proceed

Done in order. Each is independently committable; commit at every
green-or-better point (R6) — never end a session with an uncommitted tree.

1. **DONE 2026-08-06 (`ef036e6` … `a48c746`) — suite went 32 failed → 0.**
   Kept for the causes, which are all host-seam classes rather than
   one-off test bugs. Each row of the old table is resolved below.

   **Cause, for the record (it is a class, not an instance).** Testbench's
   `beforeApplicationDestroyed()` is `array_unshift`
   (`Orchestra\Testbench\Concerns\ApplicationTestingHooks`), where
   `Illuminate\Foundation\Testing\TestCase`'s is `[] =`. Callbacks therefore
   run **last-registered-first** under Testbench. `Tests\TestCase::setUp()`
   registered its cleanup after `parent::setUp()` — correct on plain Laravel,
   which is where saas-m runs it and where its docblock's reasoning was
   written — so under Testbench that cleanup ran *ahead* of `RefreshDatabase`'s
   rollback: `deleteTenantDatabases()` dropped the tenant database, then
   `RefreshDatabase` called `$connection->getPdo()` on the still-current
   tenant connection (tenancy is still initialized at teardown, so the
   *default* connection is the tenant one) and PDO reconnected to a schema
   that no longer existed. Fix is one move: register before `parent::setUp()`.
   **Anything ported from a saas-m `TestCase` that depends on callback
   ordering has to be re-checked against this inversion.** Two tells: the
   test body's assertions all pass and only teardown throws, and the frame is
   a bare `PDOException` at `parent::tearDown()` with no test-side frame —
   Testbench keeps only the *first* callback exception and swallows the rest.

   **The Flux decision, taken mid-step and worth knowing before writing any
   view test.** `livewire/flux` was `suggest`-only, so `<flux:*>` tags in the
   package's own views rendered as **literal text** — every view assertion
   was vacuous, and `SocialLoginButtonsTest`'s three `assertDontSee` lines
   proved nothing. Moved to `require-dev` (matching socialite, turnstile,
   sentry and livewire-wizard, all suggest-level but dev-installed so their
   tests can run). Installing it turned 1 failure into 5 real ones, all
   previously hidden. **A package whose views use a suggested package cannot
   test those views without it in `require-dev`.**

   | Was | Cause | Resolution |
   |---|---|---|
   | 15 | `Unknown database 'tenantX'` | teardown callback ordering, above (`ef036e6`) |
   | 5 | `Socialite\{Redirect,Login}Test` | harness never set `auth.social.providers` or `services.{google,discord}` — the redirect route reached Socialite with nothing and threw `Missing required configuration keys`, which reads as a routing problem (`24bde2b`) |
   | 4 | `{Migrate,Seed}TenantModuleTest` | the two per file that hardcode the real `alerts` module now carry `#[Group('thin-app')]`; the command resolves the module *before* it reads `--tenants`, so "unknown tenant" never reached its own path either (`2bd051d`) |
   | 2 | `CheckInvitationStatusTest` | built its tenant domain as `{id}.localhost` instead of the harness pattern, **and** `Tests\TestCase`'s process-wide forced root URL made `url('/')` answer the central host for a tenant-host request. Cleared per-file (`2bd051d`) |
   | 2 | `View\Components\PlanCardTest` | asserted the literal `10,00`, a saas-m EUR/nl artifact; separator is `cashier.currency_locale`, i.e. host config. Asserts `formatAmount()`'s own output now (`2bd051d`) |
   | 1 | `SeedTenantDatabaseTest` | `Artisan::shouldReceive()` mocks the *bound* class, `Orchestra\Testbench\Console\Kernel`, which is `final`. Replaced with a stub bound to the facade's accessor interface — hand-written, since `shouldReceive()`'s union return makes `->once()` a level-9 `method.notFound` (`a48c746`) |
   | 1 | `RegistrationCheckoutHandoffTest` | the one live-Stripe straggler D9 missed; `FakeStripeHttpClient` already covered customers and setup intents (`a48c746`) |
   | 1 | `SocialLoginButtonsTest` | **not** the socialite cause — the Flux decision above, plus a missing `auth.social.routes.{redirect,login}.name` (passed to `route()` unguarded, so unset is `Route [] not defined` from a view) (`6bb422d`) |
   | 1 | `Database\FailedJobsTableTest` | `queue.failed` unset, so the provider wrote to Testbench's sqlite stub. Gated by `QUEUE_FAILED_DRIVER`, not `queue.default` (`a48c746`) |
   | +5 | *surfaced by installing Flux* | `HeaderTest` ×3, `StaticPagesTest`, `RegistrationWizardDisabledTest`: `resources/views/flux/icon` ships four Lucide icons Flux does not, but `hasViews()` registers them as `numerosis::`, which is not where Flux looks. saas-m never saw it — its copies sat in the app's own `resource_path('views/flux')`, the first path Flux registers. Now joined from `booted()`, so the host's path and Flux's stubs both still win (`6bb422d`) |
   | +1 | `ArchTest` (risky, not failing) | scanned `base_path('app')`, which under Testbench is the empty skeleton: zero files, zero assertions, so PHPUnit reported risky rather than failing. Vacuous since the code moved into `src/`. 0 assertions → 2582 (`a48c746`) |

   **Three new host-requirements rows came out of this**, each with its
   matching `numerosis:install` check (R9's invariant, enforced by
   `HostRequirementsTest`): `auth.social.routes.{redirect,login}.name`,
   `queue.failed.database`, and — implicitly — the Flux component path,
   which was fixed in the package rather than pushed onto the host.

2. **DONE 2026-08-06 (`b310eae`, `fad542e`, `18045aa`) — audit convention-based
   registration, as an executable artifact.** Every bucket checked: event
   discovery (already fixed), policies (explicit `#[UsePolicy]`, clean),
   Livewire component names (2 real bugs found — see log), Blade view
   namespace/components (clean), factory guessing (already fixed via
   `Numerosis::factoryNameFor()`/`modelNameFor()`), migration paths
   (`discoversMigrations()`, explicit), translations (1 real bug found — see
   log), commands (`hasCommand()`, explicit), broadcast channels (thin-app's
   `withBroadcasting()`, not a package gap), morph map (consistently through
   `Numerosis::model()`, clean). **`numerosis:doctor` command deliberately
   not built** — the audit's findings became direct fixes + regression tests
   + step 3's arch test instead, judged the more valuable form of "land it
   as an executable artifact" than a separately-run doctor command. Detail
   in the log's 2026-08-06 "step 2, convention-registration audit" entry.

3. **DONE 2026-08-06 — arch test for D12's premise.**
   `tests/Feature/Support/ModelResolverBypassTest.php`: AST scan (nikic/
   php-parser, already a transitive dep via PHPStan) over every file in
   `src/` for `StaticCall`/`StaticPropertyFetch`/`New_` nodes whose resolved
   class name (via `NameResolver`) is one of the 9 config-overridable
   models, excluding `Support/Numerosis.php` (the resolver itself) and
   `Models/**` (a model referencing its own statics is not a bypass).
   `X::class` is a `ClassConstFetch`, not flagged — so `Numerosis::model(Tenant::class)`
   and relation/factory declarations pass clean. Ran clean first try (all
   108 call sites from D12 already correct); **verified by mutation**, not
   by going green — temporarily reverted one call site to a bare
   `Tenant::find()`, confirmed the test failed naming the exact file and
   line, then restored it. `pest-plugin-arch` was not used: its `toUse()`
   checks class-level dependencies, not statement-level call sites, so it
   cannot distinguish `Numerosis::model(Tenant::class)` (fine) from
   `Tenant::find()` (a bypass) — both "use" the same class.

4. **DONE 2026-08-06 — Phase 6.6, R8's Phase-5 debt.**
   - **5 contracts rewired and bound.** `InvitationRepository`,
     `SocialAccountRepository`, `NotifiesTenantOwner` were never bound in
     `NumerosisServiceProvider` — decorative, confirming R8's claim. Now
     bound to their `Support\Defaults\*` implementations and consulted at
     every call site their own docblocks name: `Livewire\Invitations\Accept`
     and `Http\Middleware\CheckInvitationStatus` (invitation lookup only —
     `CentralUser`/`TenantUser` lookups in `Accept` stay direct, per
     `.claude/rules/auth-login.md`'s "two must not share contract" bullet,
     which this file already covers this exact pair for), `Http\Controllers\Socialite\Login`
     (social-account lookup and the invitation lookup inside
     `handleInvitationIfPresent()`), and the 3 billing listeners that
     notify a tenant's owner (`SendPaymentConfirmedNotification`,
     `SendPaymentFailedNotification`, `SendTenantSuspendedNotification`  —
     R8 said 4; `SendInvitationNotification` notifies the invitee by email
     route, not the tenant owner, so only 3 qualify). `CreatesInvitedUser`
     was already wired from an earlier session. Each rewired contract got a
     "a consumer can override…" regression test (matching the existing
     `AcceptTest` one for `CreatesInvitedUser`) that swaps the binding and
     proves the override — not the default direct-model path — is what
     actually ran; without one, `pest-plugin-arch`'s `toUse()` couldn't
     have caught this class of bug either, since it checks class-level
     dependencies, not which call site of several actually fires.
   - **`HasGlobalIdentity` trait redone, the other 5 judged obsolete.**
     `CentralUser`/`Tenant\User` both implemented stancl's `Syncable`
     contract's `getGlobalIdentifierKeyName()`/`getGlobalIdentifierKey()`
     with byte-identical bodies (`'global_id'` / `$this->global_id`) — real,
     contract-backed duplication, not incidental. Extracted to
     `src/Concerns/HasGlobalIdentity.php`. The other 5 traits R8 named
     (`IsTenantModel`, `IsCentralUser`, `IsTenantUser`, `BelongsToTenant`,
     a `HasTenants` trait distinct from the existing `Contracts\Tenancy\HasTenants`
     interface) were not redone: they existed to support D8's abstract-model
     design, which D8 itself reversed — nothing in the current concrete-model
     shape has the gap they were built to paper over, and redoing them
     without a live duplication to point at would be exactly the premature
     abstraction this codebase's own conventions warn against.
   - **`Testing\InteractsWithTenantPanel` and the Stripe fake exported to
     `src/Testing/`.** `tests/` is `autoload-dev`-only
     (composer.json) — never shipped to a consumer installing this as a
     dependency, so thin-app's Phase 7.5 tests genuinely could not reach
     `Nvade\Numerosis\Tests\Support\FakeStripeHttpClient` or
     `actingAsTenantPanelUser()` before this. Moved
     `FakeStripeHttpClient`/`FakesStripe` (namespace `Nvade\Numerosis\Testing`)
     and extracted `actingAsTenantPanelUser()` into a new
     `InteractsWithTenantPanel` trait at the same location;
     `tests/TestCase.php` now composes it instead of duplicating it, and all
     10 call sites of `FakesStripe` were repointed at the new namespace.
   - Verified: full suite unchanged at 0 failed / 372 passed / 7 skipped
     throughout; PHPStan clean (baseline grew by 5 traceable entries — the
     same `method.nonObject` on nullable `$this->app` pattern already
     baselined at every other `$this->app->singleton(...)`/`instance(...)`
     call site in this suite, plus 2 `return.unusedType` from a spy
     honouring a nullable interface signature it never actually returns
     null from).

5. **DONE 2026-08-06 — reproducible numbers off this machine.**
   - `docker-compose.yml` added: single `mysql:8.4` service (matching
     saas-m's own image choice), root/root, `MYSQL_DATABASE=testing`,
     named volume — numerosis no longer borrows `saas-m-mysql-1` from the
     repo being archived.
   - `.github/workflows/run-tests.yml` gets a matching `mysql` service
     (same image/credentials/port, health-checked) plus the `pdo_mysql`
     PHP extension, which the extension list never had — CI could not
     have connected to MySQL at all before this, only to sqlite (never
     configured) or nothing.
   - `memory_limit=1G` baked into `phpunit.xml.dist`'s `<php><ini>` block
     and every relevant composer script (`test`, `test-coverage`,
     `analyse`, `lint`) via `@php -d memory_limit=1G`, so `-d
     memory_limit=1G` stops being re-derived by hand every session.
   - **A genuinely fresh MySQL volume surfaced a real, previously-invisible
     production bug**, not just a CI-config gap:
     `Nvade\Numerosis\Jobs\SeedTenantDatabase` called
     `Artisan::call('tenants:seed', ['--tenants' => ...])`, which has
     always thrown `CommandNotFoundException` — `Stancl\Tenancy\Commands\Seed`
     inherits `Illuminate\Database\Console\Seeds\SeedCommand`'s
     `$signature`, which silently overwrites the command's intended name
     back to `db:seed` during construction, and separately never gets its
     `--tenants` option registered either. Every previous "0 failed"
     measurement of this suite ran against a MySQL volume where
     `tenantphpunittemplate` already existed from an older session, so
     `Tests\Support\CloneTenantSchema` never rebuilt it and this path
     never actually ran — meaning **every tenant this package has ever
     provisioned for real would have hit this**, since production's
     `ProvisionTenant` chain uses the same `SeedTenantDatabase` job. Full
     writeup and the fix (seed directly via the container instead of
     through the broken command) in
     `.claude/rules/tenant-provisioning.md`'s new `Commands\Seed` bullet;
     regression tests rewritten in `SeedTenantDatabaseTest` to match (no
     more `Artisan`/`ConsoleKernel` mocking — binds a throwing fake
     `TenantDatabaseSeeder` instead, same idiom as step 4's contract
     override tests).
   - Verified: two consecutive full runs against a MySQL volume dropped
     and recreated from nothing both landed 0 failed / 373 passed / 7
     skipped (~65s each). PHPStan and Pint clean.

Phase 6's exit gate (R2) — zero failures, PHPStan baseline not grown, every
`thin-app`-group exclusion traceable — **is met as of `a48c746`**; see Live
status. Steps 2-5 above are the remaining Phase 6 work, none of it gating.
Then 7.5 → 8 → 9 → 10.

---
## Decisions taken — 2026-08-05 (supersede R1-R4; do not re-open)

Four decisions, made by the user after the review below. They are decisions
**8-11** in the 1.2 "already made" list; the review text they resolve is kept
underneath for its reasoning, not as an open question.

### D8 — Package models become concrete. Abstract is dropped. (resolves R1)

The 9 abstract models revert to concrete classes. A host that wants its own
subclass points **config** at it, exactly as stancl and Cashier already do —
about 9 config reads, instead of 108 `Numerosis::model()` call sites that
nothing enforces. Six distinct instantiation-by-proxy shapes were found by
running code, each after the previous sweep was declared complete; that is the
evidence this decision rests on.

Migration, in this order — it is a mechanical pass, but the order matters:

1. Drop `abstract` from the 9 classes in `src/Models/`. Re-declare nothing:
   `#[UsePolicy]`/`#[UseFactory]` attributes stay where they already are.
2. Delete the 108 `Numerosis::model(X::class)` wrappers, reverting each to a
   plain `X::` call / relation argument / class-string. Grep pattern:
   `Numerosis::model(` across `src`, `resources`, `database`, `tests`.
3. Keep `Numerosis::model()` itself, reduced to **config lookup only**, for
   the ~9 places that genuinely honour a host override (Cashier's three
   `use*Model()` calls, stancl's `tenancy.*_model` keys, `getCentralModelName()`
   /`getTenantModelName()`). It must no longer derive class names by string
   convention — that convention is what silently disagreed with config.
4. Model stubs (`stubs/Models/**`, the `numerosis-models`/`numerosis-stubs`
   publish groups) become **optional convenience**, not a precondition for the
   package booting. `numerosis:install` must stop implying they are required.
5. The ~100 test files whose `use` lines were sed-rewritten from the package
   namespace to `App\Models\…` should keep working (the stubs still extend the
   package classes) — but the Workbench stubs are now one valid host shape
   among several, not the only way the package runs. Add one test that boots
   the package with **no** stubs published at all; that is the case the
   abstract design made impossible and the case a fresh consumer actually hits.
6. `factoryNameFor()`/`modelNameFor()` keep their jobs, but `modelNameFor()`'s
   "package model is abstract, so fall back to the host namespace" branch goes
   away with the abstractness.

Expect this pass to *remove* PHPStan errors (the generic `TModel` narrowing
loss that `Numerosis::model()` introduced) rather than add them.

### D9 — Stripe tests use an HTTP fake plus recorded fixtures (resolves R2's Stripe row)

`Http::preventStrayRequests()` plus recorded response fixtures for the ~29
tests that currently call the live API with a dummy key. Deterministic,
offline, and a stray real call fails loudly instead of silently hitting
Stripe. The ~6 tests that already `markTestSkipped()` on an unset key may keep
that shape — they are integration tests by intent — but nothing new should be
added in that style.

### D10 — thin-app is stood up now, before Phase 6 finishes (resolves R3)

Do 7.1-7.4 next: create the app, add the path repo, copy `docker/` + the
framework configs + `bootstrap/app.php`, run `numerosis:install`. Rationale is
in R3 — every host-seam bug so far was found by having a second consumer, and
the Workbench harness is accumulating stand-ins whose only job is to imitate
thin-app. Amended order of execution:

`… → 5 → 6.1-6.2 → 7.1-7.4 → 6.3-6.6 → 7.5 → 8 → 9 → 10`

### D11 — Plan and rules move to numerosis; saas-m keeps a pointer (resolves R4)

`numerosis/.claude/` becomes canonical for both this plan and `rules/`.
saas-m's copies are replaced by a one-line pointer. Do this **with** the
INDEX line that Phase 10 currently defers: "bare commit hashes in these rules
refer to the archived saas-m repo" — the rules already cite `c66cc72`,
`ddd7c35`, `de06293`, `438f12f`, `549223e`, `6b8c78c` and nothing in numerosis
explains where those live. thin-app gets a pointer too, not a third copy.

**Executed 2026-08-06, with one deviation from the wording above.** The
*plan* became a pointer in both saas-m and thin-app, as written — that file
is what actually drifted for four sessions. The *rules* did not: all 12 files
stay byte-identical in each repo, and each non-canonical `INDEX.md` gained a
banner naming numerosis as canonical, plus the archived-hash line. Reason:
deleting them buys nothing the banner doesn't (divergence is detectable by
`diff -rq`, which is now clean apart from the two INDEX banners) and costs
the archive its self-contained record, plus leaves anyone working in thin-app
without the traps at hand. **If a rule ever needs changing, change
numerosis's copy and re-copy — do not edit a non-canonical copy in place,
and re-run `diff -rq` after.**

### Taken from the rules files, not escalated

- **Module tests** (~16): tag `->group('thin-app')`, exclude the group in
  `phpunit.xml.dist`, and move them once D10's app exists. Not deleted —
  `.claude/rules` and the Pest convention both forbid deleting tests without
  approval, and quarantining is not deleting.
- **Vite** (~15): stub the manifest in the package harness. Phase 9 already
  says the host owns the build, so asserting on real built assets here tests
  something the package does not own.
- **PHPStan** (R7): add `tests` and `workbench` to `phpstan.neon.dist`'s
  `paths`, then generate a baseline to freeze the current 295 errors, so new
  errors fail immediately. `.claude/rules/static-analysis.md` is explicit that
  this is what a baseline is for, and `testing.md` is explicit that excluding
  `tests/` is how rename-drift hides — this extraction proved both again.

### D12 — D8 amended: config-first resolver kept at every call site, not just ~9 (R1 reopened then reclosed, 2026-08-05)

When step 1 below was actually executed, D8's option (b) was presented to
the user as "consumer subclass reaches ~9 Cashier/stancl integration points
but never the package's other ~70 internal call sites" — and the user
paused to reopen R1 rather than accept that loss silently. A third option,
not on the table when D8 was written, was chosen instead:

**Models are concrete** (D8's core fix — no `abstract`, so no
instantiation-by-proxy crash class, no stub-publish precondition), **but
`Numerosis::model()` stays at all ~108 call sites and becomes config-first**
instead of being deleted down to ~9 call sites. Default (no override) costs
one `Config::get()` lookup and returns the package's own class unchanged —
zero behavioural difference from a bare literal reference. A host that sets
`numerosis.models.<FQCN>` redirects every one of those ~108 call sites to
its subclass in one place, not just the framework-integration handful.

Implemented in `numerosis@f665a96`:

- `config/numerosis.php` gained a `'models'` array, one key per of the 9
  models, each `env(...)`-driven, default `null` (⇒ package's own class).
- `Numerosis::model()` rewritten: `Config::get("numerosis.models.{$model}")`,
  returns the override if it's a string, else `$model` unchanged. No more
  `ReflectionClass::isAbstract()` check (nothing is abstract) and no more
  by-convention `app()->getNamespace()` guessing — config or nothing.
- The ~108 call sites were **not touched** — they already call through
  `Numerosis::model()` from prior sessions' work, which is exactly what this
  design keeps. (A same-session detour reverted ~70 of them to literal
  calls while pursuing D8's original option (b), then reverted *that* back
  to the wrapper once the user chose this design — see the model file diffs
  in `f665a96` for the net result: only the 9 model files' `abstract`
  keyword and `config/numerosis.php`/`Numerosis.php` actually changed.)
- `modelNameFor()` (the Factory→Model resolver, a *separate* mechanism from
  `model()` — governs what a package factory builds, not what a package
  call site references) had its host-fallback condition **fixed**, not left
  alone: it used to trigger on "package model is abstract," which can no
  longer ever be true. Retriggering on "does a host/workbench stub class
  exist" was required to keep the ~100 sed-rewritten test files (typed
  against `App\Models\Central\Tenant` etc, per D8 point 5) working — this
  was found by running the suite (104 new-looking failures, all
  `TypeError: Cannot assign … to property … of type App\Models\…`), not by
  reasoning about the change in advance. **If this file is touched again:
  `model()` and `modelNameFor()` solve different problems and must not be
  collapsed into one — `model()` is call-site override, `modelNameFor()` is
  factory-target resolution, and they can legitimately disagree (a factory
  builds the stub even when no config override is set, because the stub
  exists on disk).**

Verified: full suite before/after this file's own change stayed within the
same known-flaky range (89/104/149 across three runs of the *same* code —
matches `testing.md`'s documented lock-wait/order contention, not this
change); grepped explicitly for `Cannot instantiate abstract`/`TypeError`
across all three runs, found none. PHPStan: 295 → 301, but diffed line by
line against the pre-change list — 3 pre-existing `PaymentPlan::$id`
errors were *fixed* (predicted by D8: narrowing improves once the model is
concrete) and the only additions are 9 more instances of an
`env()`-in-config warning this file already carried 5 of for its
pre-existing `schedule`/`domains`/`cache` sections — not a new violation
class. Pint clean.

R1 is closed again by this decision; do not reopen a third time without
new evidence, same as D8's original text asked.

### D13 — One config file. The three-file split (1.7) is reversed. (2026-08-06)

`config/numerosis-billing.php` and `config/numerosis-tenancy.php` are gone;
their contents live in `config/numerosis.php` under nested `'billing'` and
`'tenancy'` keys. Shipped as `numerosis@59f026f`; execution plan kept at
`numerosis/.claude/plans/federated-wandering-wozniak.md`.

**Nested, not flattened, because two keys genuinely collide.** `models` means
per-model class overrides at the core level (D12's mechanism) and Cashier
model bindings in billing; `implementations` exists in both billing and
tenancy. Flat merge would have silently dropped one of each pair — the
config-shaped version of exactly the drift this plan keeps finding elsewhere.

Also removed: `BillingServiceProvider`/`TenancyServiceProvider` each ran their
own `mergeConfigFrom()` plus a `publishes()` under `billing-config` /
`tenancy-config`. Both tags were dead — `numerosis:install` only ever
published `numerosis-config`, the tag `hasConfigFile()` emits — so the
package had three merge paths and one publish path that mattered.
`hasConfigFile()` now names one file and owns both.

**A host holding published copies of the deleted files loses its
customisations silently.** Nothing errors: the package merges its own
defaults under `numerosis.billing.*`, and the host's orphaned
`numerosis-billing.php` is simply never read again. Any host on a pre-`59f026f`
version must delete both files and re-publish `numerosis-config`.
thin-app was repaired this way in `thin-app@41bcd57` (its three files were
byte-identical to package defaults, so nothing had to be re-applied);
**saas-m needs no action — it is frozen and its published copies are
historical**, which is why the earlier "Consequence for saas-m" note below
pointed at the wrong repo.

---

## Plan review — flaws and corrections (2026-08-05)

Written after reading the whole plan plus the actual state of
`~/repos/private/numerosis` (`ab29e89` + 11 uncommitted files). Findings are
ordered by how much later work they invalidate, not by when they appear in the
document. Each states the flaw, then the correction to apply. **R1-R3 are
decisions, not chores — make them before writing more Phase 6 code.**

### R1 — The abstract-model design has no enforcement, and its resolver
### ignores the config it was justified by

**Flaw, part one: two competing model-resolution mechanisms, only one of them
real.** Decision 1.2 #4 says "Config-driven models + contracts. Package ships
abstract bases and contracts; thin-app owns concrete classes; **config points
at them**." That is not what was built. `Numerosis::model()`
(`src/Support/Numerosis.php:234`) resolves purely by *string convention* —
`app()->getNamespace().'\\Models\\'.$suffix` — and never reads config at all.
`config/numerosis.php` and `config/numerosis-tenancy.php` contain **zero**
model keys (verified by grep). So today:

- a host whose stub is `App\Models\Central\Tenant` works by luck of naming;
- a host that satisfies `tenancy.tenant_model` with any other class name
  (`App\Models\Workspace`, a domain-layer namespace, a module) has a
  correctly-configured app that still breaks, because 108 call sites resolve
  the *convention* name instead of the *configured* one;
- if the stub is simply missing, `model()` returns a class-string that does not
  exist and the failure surfaces far from the cause, exactly the failure shape
  `.claude/rules/testing.md` warns about for factory resolution.

**Flaw, part two: "the sweep is complete" has been claimed four times and been
wrong four times.** Instantiation-by-proxy sites found so far, each discovered
by running code after the previous sweep was declared done: (1) static calls,
(2) Eloquent relation definitions, (3) vendor code handed a class-string
(`getTenantModelName()`), (4) Filament `Resource::$model`, (5) validation-rule
strings (`'unique:'.CentralUser::class`), plus a `.blade.php` call site no
`grep … src` could reach. The plan's own notes already concluded twice that
"grep-by-known-pattern is not exhaustive by construction" — and then reached
for a wider grep instead of a mechanism. There is no reason to believe a sixth
class does not exist (queue payloads serialising a model class name, morph
maps, `Relation::enforceMorphMap()`, `Gate::policy(X::class, …)`, Livewire
`#[Locked]` model properties, `Rule::exists()`, config files referencing
models, Filament `RelationManager::$relationship` resolution).

**Correction — pick one of two, do not carry both:**

**(a) Keep abstract, but make completeness a property instead of a memory.**
Add an arch test in numerosis that fails on *any* reference to one of the 9
abstract model classes outside an approved position (a `use` for a type-hint,
a docblock, or an argument to `Numerosis::model()`). Pest's `arch()` can
express this; a PHPStan custom rule is stronger and runs on every analyse.
Without it, every future contributor re-runs this sweep by hand. Also make
`Numerosis::model()` read config first and fall back to the convention:

```php
// config/numerosis.php
'models' => [
    Central\Tenant::class            => env('NUMEROSIS_MODEL_TENANT'),   // null ⇒ convention
    Central\CentralUser::class       => null,
    // … all 9
],
```

and have `model()` throw a named exception (`HostModelMissing`) naming the
abstract class, the resolved class-string, and the config key to set, rather
than returning a class-string that does not exist. Memoize the result — it is
currently a `new ReflectionClass()` per call on relation-resolution paths.

**(b) Drop abstract entirely; ship concrete models, keep the config swap.**
This is what stancl and Cashier themselves do, and it deletes the whole bug
class at once: `Tenant::find()` inside the package just works, no stub publish
is required before the package runs, `numerosis:install` stops being mandatory
for a smoke test, and all 108 `Numerosis::model()` wrappers collapse back to
plain calls except at the few points where a host override genuinely matters
(the tenancy/billing model config keys, which stancl and Cashier already read
themselves). The cost is that a host extending a model must point config at
its subclass *and* the package's own internal calls keep using the package
class unless routed through the resolver — i.e. the same indirection, but
needed at ~9 config-read sites instead of 108 call sites.

**Recommendation: (b), with (a)'s arch test kept for the residual sites.** The
evidence for it is in this document: five distinct proxy-instantiation classes,
~100 test files that had to be sed-rewritten, and a resolver that duplicates
`factoryNameFor()`/`modelNameFor()`'s convention logic a third time. Abstract
bought exactly one thing — forcing the host to own the class — and the plan
never weighed that against the cost, because the cost was not yet visible.
Whichever is chosen, **record the decision in 1.2 as decision #8 with the
reasoning**, so it is not silently re-opened.

### R2 — Phase 6 has no exit criterion, so it cannot end

Four "Phase 6 status" sections, three of them pause notes, and each session
re-triages the same buckets (Stripe, modules, tenant-database teardown, vite).
The plan never says what a *finished* package test suite looks like. Add:

**6.5 — Test-suite scope, decided once (do this before more fixing):**

| Bucket | Decision to make | Recommended |
|---|---|---|
| `tests/Feature/Modules/*` (~24 failures) | These test app-side module packages that structurally cannot exist here (6.3's own table says so) | Tag `->group('thin-app')` and exclude the group in `phpunit.xml.dist`; move them in Phase 7. Not deletion, so the no-delete rule holds |
| Stripe live-API tests (~29) | Real test key / `Cashier::fake()` / HTTP fake / skip guard | `Http::preventStrayRequests()` + recorded fixtures for the deterministic ones, `markTestSkipped()` keyed on a real `STRIPE_SECRET` for the rest. Decide once, apply in bulk |
| Vite manifest | Stub or skip | Stub a manifest in the harness — Phase 9 says the host owns the build, so asserting on real assets here tests nothing the package owns |
| `Unknown database 'tenantX'` teardown (~19) | Genuine bug in the harness or in the Workbench panel provider's second bootstrap cycle | Investigate before Phase 7 — this is the one bucket that may be a *package* bug, not a harness gap |

**Then write a numeric gate into Phase 6:** "Phase 6 is complete when
`vendor/bin/pest --ci` is 0 failed, with every skip/exclusion traceable to a
row in the table above." Phase 7 does not start before that, except as R3
allows.

### R3 — Build thin-app earlier, not after Phase 6 is green

The two highest-value bugs found in the last three sessions were found *only*
because a second consumer existed: `base_path('routes/web.php')` (invisible
while saas-m's own file happened to sit at that path) and the systemic
`numerosis::` view-namespace gap (invisible while views were the app's own).
Both are host-seam bugs, and the Workbench harness is a weak proxy for a host —
it is already accumulating stand-ins (two Workbench panel providers, hand-set
`app.domain`, `app.central.default`, `auth.passwords.users`,
`livewire.component_namespaces`, `URL::forceRootUrl`) whose only purpose is to
imitate what thin-app will really own.

**Correction: move 7.1-7.4 (create app, path repo, copy `docker/`+configs,
`numerosis:install`) ahead of finishing Phase 6.** A booting thin-app resolves
several Phase-6 buckets by construction (module tests get a real home, panel
providers stop being Workbench fiction, host config stops being guessed) and
makes `numerosis:install`'s verification list executable against a real host
instead of hypothetical. Keep Phase 6.1/6.2 (MySQL harness, test-support layer)
where they are — those are prerequisites for anything. Update "Order of
execution" at the end of the document to reflect the split:
`… → 5 → 6.1-6.2 → 7.1-7.4 → 6.3-6.5 → 7.5 → 8 → 9 → 10`.

### R4 — The freeze (4.1) is already being violated, by this file — **CLOSED by D11, executed 2026-08-06**

saas-m is declared read-only from Phase 4.1, yet every session since has
written status notes into `.claude/plans/package-extraction.md` **in saas-m**
(the working tree carries exactly that modification right now), and the last
three sessions' hard-won facts (view-namespace prefixing, instantiation by
proxy, `URL::forceRootUrl` under Testbench, `Config::string()` on an unset key
surfacing one line later) were written *here* rather than into numerosis's
`.claude/rules/`. Since 4.2 copied `.claude/` into numerosis, there are now two
diverging copies and thin-app will make three.

**Correction:**
1. State explicitly in 4.1 that `.claude/**` and this plan are **exempt** from
   the freeze — or, better,
2. Move the plan and the rules to numerosis now, leave a one-line pointer in
   saas-m, and make numerosis's `.claude/rules/` the canonical copy. Everything
   learned since Phase 4 is about the *package*, not about saas-m.
3. Move Phase 10's "add a line to `.claude/rules/INDEX.md` in both repos
   stating that bare commit hashes refer to the archived saas-m" **to the
   moment the rules are copied**, not to archive time. It is already needed:
   the rules in numerosis cite `c66cc72`, `ddd7c35`, `de06293`, `438f12f`,
   `549223e`, `6b8c78c` and nothing in that repo explains where they live.

### R5 — 0.4's "known-good baselines" are stale and actively misleading — **CLOSED: 0.4's numerosis row now points at Live status**

0.4 still says numerosis is "`vendor/bin/pest` 2 passed; `vendor/bin/phpstan
analyse` clean at level 9. These must stay green at every step." Reality:
**105 failed / 286 passed / 8 skipped**, PHPStan **309 findings**. An agent
following 0.4 literally would conclude the repo is catastrophically broken and
start reverting.

**Correction:** replace 0.4's numerosis row with a pointer to a single
**Live status** block (see R6) and forbid frozen numbers anywhere else in the
document. saas-m's row is fine — that repo is frozen, so its numbers cannot
drift.

### R6 — The status prose is append-only and has outgrown the plan — **CLOSED 2026-08-06: log split out, Live status block added**

~430 lines of status precede the first instruction; four Phase 6 status
sections, at least one line in them already annotated as having gone stale
before it was read back ("nothing committed yet" → "by the start of the next
session that commit had in fact landed"). The document's own stated audience —
an agent with no prior context — must now read a session log before reaching
step 1.

**Correction:**
1. Create `.claude/plans/package-extraction-log.md` and move every dated status
   section into it verbatim. The findings have real value (they are the only
   record of five bug classes); the value is archival, not navigational.
2. Keep at the top of this file a **Live status** block of at most 15 lines,
   overwritten rather than appended, with exactly: current phase, last commit
   in each repo, working-tree state, latest measured suite numbers + date, and
   a single "resume here" pointer.
3. Rule for future sessions: **never end a session with an uncommitted numerosis
   tree.** Two sessions have now done so, and the plan itself observes that the
   resulting "nothing committed yet" notes go stale. Commit at every
   green-or-better measurement point; a commit is the status, the prose is the
   commentary.

### R7 — PHPStan in numerosis is configured so it cannot catch the bugs this — **CLOSED: `tests`+`workbench` analysed, 240-entry baseline**
### extraction actually produces

`phpstan.neon.dist` sets `paths: [src, config, database]` — **`tests/` and
`workbench/` are not analysed** — and `phpstan-baseline.neon` is a **0-byte
file** while the run reports 309 findings. Both are backwards for this project:

- `.claude/rules/testing.md` records that including `tests/` in saas-m
  "surfaced 89 errors, 24 real dead references across seven files nobody had
  run", and it is the exact reason rename-drift is caught statically there.
  This extraction is one enormous rename, and it proved the point again: ~100
  test files importing abstract models were found by *running* the suite, not
  statically, because `tests/` is invisible to PHPStan here.
- `.claude/rules/static-analysis.md` says the baseline exists so that **new**
  errors fail immediately. An empty baseline against 309 live findings means
  every run is red, so no new error is distinguishable — the same "red on
  master, signal gone" state that file criticises in saas-m, reproduced
  deliberately in a fresh repo.

**Correction:** add `tests` and `workbench` to `paths`, then
`vendor/bin/phpstan analyse --generate-baseline` **now** to freeze the current
count, and treat any growth as a failure. Note in the step that
`--debug --memory-limit=1G` is required in this environment (broken
`turbo-ext`, default 128M exhausts) so the next agent does not read exit 255 as
a code error.

### R8 — Deferred work from Phase 5 exists only in prose and will be lost

Three deferrals are recorded in status text with good reasons, and appear in no
step, table, or checklist:

1. **Contract call sites never rewired** (5.3): `Livewire\Invitations\Accept`,
   `CheckInvitationStatus`, `Http\Controllers\Socialite\Login`,
   `ProvisionTenant`, and the 4 notification listeners still query models
   directly instead of going through the 5 new contracts. The contracts are
   therefore decorative — a consumer swapping the binding changes nothing.
2. **Six traits written then deleted** (`IsTenantModel`, `IsCentralUser`,
   `IsTenantUser`, `HasGlobalIdentity`, `BelongsToTenant`, `HasTenants`)
   because PHPStan flags an uncomposed trait. The duplication they were
   extracting is still in `CentralUser`/`Tenant\User`.
3. **`Testing\InteractsWithTenantPanel`** (6.2's own requirement): exporting
   `actingAsTenantPanelUser()` for consumers was never reported done, and
   thin-app's Phase 7 tests need it.

**Correction:** add a **Phase 6.6 — Phase 5 debt** section listing all three as
real steps with done-criteria, gated behind R2's green suite (which is the
condition their deferral cited). Deferral with a reason is fine; deferral into
prose is how it becomes never.

### R9 — `numerosis:install` and `docs/host-requirements.md` will drift

5.2 enumerates 6 verifications. Phase 6 then discovered at least 6 more
host-owned keys the hard way (`app.domain`, `app.central.default`,
`auth.social.providers`, `auth.passwords.users`,
`livewire.component_namespaces`, `database.lock_wait_timeout`), each surfacing
as a misleading error — `Route::domain(null)` failing one line later as `Call
to a member function name() on string` is the clearest example. They went into
the doc; the command still checks the original 6.

**Correction:** state the invariant explicitly in 5.2 — **every row in
`docs/host-requirements.md` has a matching assertion in `numerosis:install`,
and every assertion names the failure signature the host would otherwise see.**
Add a test that fails when the two lists diverge (parse the doc's table, assert
each key appears in the command). Otherwise the doc is the real spec and the
command is a partial copy of it, which is the exact drift shape
`.claude/rules/auth-login.md` documents for the two login components.

### R10 — Smaller corrections, apply in place

- **`Numerosis` is becoming a god object**: `addTenantColumns`, `tenantColumns`,
  `routes`, `middleware`, `broadcasting`, `csrfExceptions`, `model`,
  `factoryNameFor`, `modelNameFor`. The three name-resolution methods duplicate
  the same `\\Models\\`-suffix string surgery three times. Extract a
  `Support\ModelResolver` owning all three (and R1's config lookup + memoization);
  leave `Numerosis` as the host-seam facade only.
- **Phase 10's end-to-end gate is manual** (register through the wizard, log
  into the panel). Given that the whole extraction's risk is host-seam wiring,
  make it a Pest browser test in thin-app instead — it is the only check that
  covers routes + panels + provisioning + assets together, and it will be run
  more than once.
- **Phase 4.2's `.claude/` "both repos" row** is what created R4's divergence;
  amend it to "canonical copy in numerosis, pointer in thin-app".
- **Workbench panel providers** (added in Phase 6) duplicate what thin-app's
  Phase 7/8 providers will own. Note in Phase 8 that they must stay minimal
  stand-ins and must not accrue plugin/theme logic — two panel definitions
  drifting is the same failure `.claude/rules/auth-login.md` records for the
  two login components.
- **PHP-version seam**: numerosis is developed on host 8.4.1 while saas-m and
  thin-app run 8.5 in Docker. CI covers both, but state the rule in 0.1 —
  the package's `composer.json` PHP constraint is the floor, and package code
  must not use 8.5-only syntax even though thin-app would accept it.

---

---

## 0. Ground rules — read before touching anything

### 0.1 Which repo, which PHP

Three repos, three different ways to run commands. Mixing them is the single
most likely mistake.

| Repo | Path | How to run PHP | PHP version |
|---|---|---|---|
| saas-m | `~/repos/private/saas-m` | **Always** `vendor/bin/sail …` (Docker) | 8.5 in container |
| numerosis | `~/repos/private/numerosis` | **Never** sail. Plain `php`, `composer`, `vendor/bin/pest` on the host | 8.4.1 host |
| thin-app | `~/repos/private/thin-app` | `vendor/bin/sail …` once docker/ is copied in (Phase 7) | 8.5 in container |

Examples that are correct:

```bash
cd ~/repos/private/saas-m    && vendor/bin/sail artisan test --compact
cd ~/repos/private/numerosis && vendor/bin/pest --ci
cd ~/repos/private/numerosis && vendor/bin/phpstan analyse --no-progress
```

Never run `vendor/bin/sail` inside numerosis (there is no docker-compose there).
Never run bare `php artisan` inside saas-m.

### 0.2 Absolute prohibitions

1. **Never delete `~/repos/private/saas-m`.** It is archived at the very end,
   never deleted. Its commit hashes are cited by `.claude/rules/*`.
2. **Never `git push --force`** in any of the three repos.
3. **Never run destructive DB commands** (`migrate:fresh`, `DROP DATABASE`)
   against anything but the `testing` database. See
   `.claude/rules/testing.md` for the recovery procedure if this happens
   anyway.
4. **Never run two test suites at once.** `.claude/rules/testing.md`: two
   concurrent runs collide on token databases and look like a hang.
5. **Never add `--parallel`** to the saas-m suite. Removed deliberately.
6. **Never squash migrations** as part of this extraction (see 6.4).
7. **Never make numerosis `require` any `nvade/<module>` package** — the 6
   modules stay app-side path repos.
8. **Never edit files in two repos for the same change once Phase 4 starts.**
   After the freeze (4.1), saas-m is read-only.

### 0.3 Reference material that must be read, not guessed

`.claude/rules/` in saas-m holds hard-won facts. Read the file named before
touching its area; they are short:

| Area you are touching | Read first |
|---|---|
| tenant creation, provisioning chain | `tenant-provisioning.md` |
| guards, `web` vs `tenant`, policies | `auth-guards.md` |
| login screens, rate limiting | `auth-login.md` |
| checkout, Stripe, subscriptions | `billing-checkout.md` |
| Filament panels/tests, `{tenant}` param | `filament-tenancy.md` |
| modules, `$tenant->run()` | `module-marketplace.md` |
| caches, `global_cache()` | `tenant-caching.md` |
| uploads, disks | `tenant-filesystem.md` |
| exceptions, queue failures | `exception-handling.md` |
| running tests, teardown, baseline | `testing.md` |
| PHPStan level/baseline | `static-analysis.md` |

### 0.4 Known-good baselines (so you can tell your breakage from existing breakage)

- saas-m full suite: **0 failures, 1 skipped, 431 passed** (updated
  2026-08-05 — see Phase 3 status above for the fixes that got it here from
  the old 22/9-failure baselines). Any failure is yours.
- saas-m PHPStan: **red on master** — ~98 errors outside the 44-entry baseline
  (9 under `app/`, 89 under `tests/`). Compare counts before and after your
  change; do not try to reach zero.
- numerosis: **no frozen number belongs here.** Read the **Live status** block
  at the top of this file, which is overwritten each session; anything written
  into 0.4 goes stale within one session and then reads as a target it never
  was. (This row previously claimed "2 passed, PHPStan clean" against a suite
  that was 105 failed.)

---

## 1. What is being built

### 1.1 Three repos, final state

| Repo | Role | Fate |
|---|---|---|
| `numerosis` | The package: tenancy + billing core required, everything else opt-in via feature classes. Proprietary, private (`git@github.com:Nivade/numerosis.git`). | Survives |
| `thin-app` | The deployable Laravel app. Owns `bootstrap/app.php`, `docker/`, `.env`, vite build, the 6 app-modules, concrete models. Requires `nvade/numerosis`. | Survives, is deployed |
| `saas-m` | Today's monolith (`git@gitlab.com:nvade_/saas-m.git`). | **Archived read-only** at the very end |

A package cannot be deployed and a Testbench workbench app is a dev harness,
not a production target — that is why `thin-app` exists.

### 1.2 Decisions already made (do not re-open)

1. **Content copy, no git-history graft.** Fresh commits in numerosis and
   thin-app. saas-m archived so its history stays readable.
2. **Namespace `Nvade\Numerosis\` in `src/`.** thin-app keeps `App\`.
3. **Feature classes, not booleans.** `App\Contracts\NamedFeature` +
   `App\Features\*` listed in `config('numerosis.features')`. Already built.
4. **Config-driven models + contracts.** ~~Package ships abstract bases and
   contracts; thin-app owns concrete classes; config points at them.~~
   **Amended by D8, 2026-08-05:** the package ships *concrete* models and
   contracts; a host that wants its own subclass points config at it. The
   abstract-base half of this decision was tried, cost six distinct
   instantiation-by-proxy bug shapes, and was reversed — the "config points at
   them" half stands and is now the only mechanism.
5. **Filament panels become plugins.** The package never owns a panel.
6. **Modules stay app-side.** numerosis ships the module *system* only. The 6
   modules (alerts, announcements, branding, **chat**, notes, tasks) move to
   `thin-app/app-modules/*` as symlinked path repos.
7. **Proprietary, private git only.** No Packagist. thin-app requires numerosis
   through a path repo during development.
8. **Package models are concrete** (D8, supersedes the abstract half of #4).
9. **Stripe tests use an HTTP fake plus recorded fixtures** (D9).
10. **thin-app is stood up before Phase 6 finishes** (D10).
11. **Plan and rules live in numerosis; the other two repos hold pointers**
    (D11).
12. **One config file** — `config/numerosis.php`, billing and tenancy nested
    under their own keys; the 1.7 three-file split is reversed (D13).

### 1.3 Already landed inside saas-m (do not redo)

- `App\Contracts\{Feature,NamedFeature}`, `App\Support\Features`, 11
  `App\Features\*` classes, `config/numerosis.php` (feature list + `schedule`
  booleans + `routes.names.{home,tenants_mine}`), `App\Support\Routes\RouteNames`.
- `AppServiceProvider::boot()` bootstraps features via
  `Features::all()` → `$this->app->make($feature)->bootstrap()`.
- `bootstrap/app.php` no longer hardcodes hosts (bare `trustHosts()`).
- `App\Contracts\Auth\{CentralUserModel,TenantUserModel}` +
  `App\Services\Tenancy\UserModelResolver` reading
  `tenancy.central_user_model` / `tenancy.tenant_user_model`.
- Deliberate non-additions, with reasons recorded in code: `TenantModel`
  contract (stancl's `TenantWithDatabase` covers it),
  `SubscriptionModel`/`PaymentPlanModel` (already swappable),
  `HasTenants::tenants()` generic (breaks PHPStan; docblock explains).

---

## Phase 0 — Fix the package skeleton ✅ DONE 2026-08-04

Recorded for provenance; do not repeat.

- `phpstan.neon.dist`: level 5 → **9**, added `treatPhpDocTypesAsCertain: false`.
- `composer.json`: removed `minimum-stability: dev`; pinned
  `illuminate/contracts ^13.0`, `orchestra/testbench ^11.0`;
  `"license": "proprietary"`; homepage → `github.com/Nivade/numerosis`;
  workbench autoload paths; `build`/`serve`/`clear`/`lint` scripts.
- `LICENSE.md` MIT → proprietary. `README.md` rewritten. `FUNDING.yml` removed.
- `vendor/bin/testbench workbench:install` run → `workbench/` + `testbench.yaml`.
- `.gitignore` no longer ignores `testbench.yaml` (still ignores `phpstan.neon`
  and `phpunit.xml`; the committed configs are the `.dist` variants).
- CI `run-tests.yml` matrix narrowed to ubuntu / php 8.4+8.5 / Laravel 13 /
  testbench 11 / prefer-stable.
- Verified: `vendor/bin/pest --ci` 2 passed, `vendor/bin/phpstan analyse` clean.

**Outstanding in numerosis:** the working tree still holds the uncommitted
skeleton-configure diff (the one existing commit is raw generator output). Commit
it before Phase 4 — see step 4.0.

---

## Phase 1 — De-hardcode inside saas-m

All of Phase 1 happens in **saas-m**, with sail, while the app is still whole
and its tests still run. Nothing is copied yet.

After **every** step in this phase:

```bash
cd ~/repos/private/saas-m
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail exec -T laravel.test bash -lc "vendor/bin/phpstan analyse" | tail -5
vendor/bin/sail artisan test --compact --filter=<the tests for what you touched>
```

PHPStan must not report *more* errors than before your change. Tests you touched
must pass.

### 1.1 Create the `Numerosis` manager + facade

**Why:** several later steps need a single object to hold package-level
registrations (`addTenantColumns`, `routes`, `middleware`). It does not exist
yet — `app/Facades/` holds only `Billing.php`.

**Do:**
1. `vendor/bin/sail artisan make:class Support/Numerosis --no-interaction`
   (file: `app/Support/Numerosis.php`, namespace `App\Support`).
2. Give it: `private static array $tenantColumns = []`,
   `public static function addTenantColumns(array $columns): void`,
   `public static function tenantColumns(): array`.
3. `vendor/bin/sail artisan make:class Facades/Numerosis --no-interaction`,
   make it extend `Illuminate\Support\Facades\Facade`, accessor returns
   `App\Support\Numerosis::class`, `@method static` docblock like
   `app/Facades/Billing.php`.
4. Bind it in `AppServiceProvider::register()` the same way `Billing` is bound
   (read `app/Providers/BillingServiceProvider.php` first).

**Done when:** `vendor/bin/sail artisan tinker --execute 'Numerosis::addTenantColumns(["x"]); dump(Numerosis::tenantColumns());'` prints `["x"]`.

### 1.2 `Tenant::getCustomColumns()` reads the manager

**Read first:** `.claude/rules/tenant-provisioning.md`, bullet "`tenants` column
only exists if `Tenant::getCustomColumns()` names it".

**Do:** in `app/Models/Central/Tenant.php`, return the existing static list
merged with `Numerosis::tenantColumns()`. Add a docblock stating the ordering
rule: **`addTenantColumns()` must be called from a service provider's
`register()`, before any tenant model boots or saves.** A late call silently
folds the column into the `data` JSON blob — the exact bug this API prevents.

**Done when:** `vendor/bin/sail artisan test --compact --filter=TenantColumns`
passes (`tests/Feature/Models/Central/TenantColumnsTest.php`, which reads the raw
row — do not weaken it).

### 1.3 Finish route-name indirection

**Current state:** `config('numerosis.routes.names')` holds only `home` and
`tenants_mine`; `App\Support\Routes\RouteNames` reads them.

**Do:** add `invitation_show` (`invitation.show`) and `checkout_subscription`
(`checkout.subscription`) to the config array and to `RouteNames`, then replace
those literals at their call sites:

```bash
cd ~/repos/private/saas-m
grep -rn "'invitation\.show'\|\"invitation\.show\"" app resources routes
grep -rn "'checkout\.subscription'\|\"checkout\.subscription\"" app resources routes
```

Replace each hit with the `RouteNames` accessor. Leave the `route()` calls whose
names are never gated by a feature alone — only these four names matter.

**Done when:** both greps return only the route *definition* sites and
`RouteNames` itself; `vendor/bin/sail artisan test --compact --filter=Invitation`
passes.

### 1.4 Tenant domain pattern out of code

**Do:** in `app/Providers/Filament/TenantAdminPanelProvider.php`, replace
`->tenantDomain('{tenant}.nvade.dev')` with
`->tenantDomain(Config::string('numerosis.domains.tenant_pattern'))`, and add to
`config/numerosis.php`:

```php
'domains' => [
    'tenant_pattern' => env('NUMEROSIS_TENANT_DOMAIN', '{tenant}.'.parse_url((string) env('APP_URL'), PHP_URL_HOST)),
],
```

Add `NUMEROSIS_TENANT_DOMAIN` to `.env.example`.

**Verify no literals remain:**

```bash
grep -rn "nvade\.dev" app config routes bootstrap resources
```

Expect zero hits under `app/`, `config/`, `routes/`, `bootstrap/`.

**Done when:** `vendor/bin/sail artisan test --compact --filter=TenantAdmin`
passes. **If Filament tests fail with `Missing required parameter for [Route:
filament.tenantAdmin…]`,** that is the `{tenant}` trap, not your change — read
`.claude/rules/filament-tenancy.md` and use
`Tests\TestCase::actingAsTenantPanelUser()`.

### 1.5 Livewire wizard views must not use `resource_path()`

**File:** `app/Features/Tenancy/RegistrationWizardFeature.php` (lines ~42-57)
registers four components with `viewPath: resource_path('views/livewire/tenant/registration/…')`.
Those paths do not exist inside a package.

**Do:** register by *view name* instead
(`Livewire::component('tenant.registration.steps.plan', Plan::class)` with the
component rendering `view('numerosis::livewire.tenant.registration.steps.plan')`),
or keep `viewPath` but resolve it from a package-aware base path constant. Do not
hardcode `resource_path()`.

**Trap — read `.claude/rules/billing-checkout.md`:** the `Payment` step's alias
is `tenant.registration.steps.payment`, *not* `payment`, because Cashier's
published view already owns that name. Resolve aliases with
`app('livewire.finder')->normalizeName(Payment::class)`; never hardcode.

**Done when:** `vendor/bin/sail artisan test --compact --filter=Registration`
passes.

### 1.6 Cache-key prefix becomes configurable

**Read first:** `.claude/rules/tenant-caching.md`.

**Do:** in `app/Support/Cache/CacheKeys`, prefix every key with
`Config::string('numerosis.cache.prefix', 'numerosis')`. Do not change which
keys are tenant-scoped (`Cache::`) versus global (`global_cache()`) — that
distinction is the whole point of the class. Do not reintroduce
`rememberForever`.

**Done when:** `vendor/bin/sail artisan test --compact --filter=FindUserByGlobalId`
passes. That test needs `Tests\Concerns\PinsGlobalCache`; without the pin a
cross-tenant cache test passes against broken code.

### 1.7 Config split

**Do:** move keys out of `config/billing.php` into two new files:

- `config/numerosis-tenancy.php` ← `provisioning.steps`, `TenantDomainPolicy`,
  `ProvisionsTenant` binding.
- `config/numerosis-billing.php` ← plans, gateways, implementations (the rest of
  today's `billing.php`).

Keep `config/numerosis.php` for features, models, routes, domains, guards, cache.

Update every reader:

```bash
grep -rn "config('billing\.\|Config::[a-z]*('billing\." app routes database tests | wc -l
grep -rln "billing\." app routes database tests
```

Rewrite each hit to the new key. Leave `config/cashier.php` alone (Cashier owns
it).

**Done when:** `grep -rn "'billing\." app routes database tests` returns zero
hits and `vendor/bin/sail artisan test --compact --filter=Billing` passes.

**Reversed 2026-08-06.** Three-file split undone in the package repo — see
`~/.claude/plans/federated-wandering-wozniak.md` (numerosis repo). All three
merge back into one `config/numerosis.php`, with billing/tenancy content
nested under `'billing'`/`'tenancy'` top-level keys (avoids a real key
collision: `numerosis.php`'s `models` — per-model class overrides — and
`numerosis-billing.php`'s `models` — Cashier model bindings — are two
different shapes sharing the same top-level name once merged flat).
`BillingServiceProvider`/`TenancyServiceProvider` drop their own
`mergeConfigFrom()`/`publishes()` (`billing-config`/`tenancy-config` tags,
already dead — `numerosis:install` only ever called `numerosis-config`,
the tag `hasConfigFile()` emits); the package's own `hasConfigFile()` call
narrows from three names to one.

**Shipped as `numerosis@59f026f`; recorded as D13 above — read that, not
this paragraph, for the consequences.** The original note here told saas-m
to re-publish its own copies; wrong repo. saas-m is frozen and archived, so
its `config/numerosis-*.php` are historical artefacts and need no action.
The host that actually mattered was thin-app, repaired in
`thin-app@41bcd57`.

### 1.8 Dependency triage — produces a document, not code

**Do:** create `~/repos/private/numerosis/DEPENDENCIES.md` listing every package
in saas-m's `composer.json` `require` block with a verdict:

- **require** (package cannot work without it): `stancl/tenancy`,
  `laravel/cashier`, `spatie/laravel-permission`, `spatie/laravel-data`,
  `lorisleiva/laravel-actions`, `spatie/laravel-package-tools`.
- **suggest + `class_exists()` guard** (feature-gated): `filament/filament`,
  `livewire/flux`, `laravel/socialite`, `ryangjchandler/laravel-cloudflare-turnstile`,
  `sentry/sentry-laravel`, `laravel/telescope`, `laravel/reverb`,
  `pusher/pusher-php-server`, `alizharb/filament-activity-log`,
  `openplain/filament-shadcn-theme`, `dompdf/dompdf`,
  `spatie/laravel-livewire-wizard`, `spatie/laravel-one-time-passwords`,
  `socialiteproviders/*`, `internachi/modular`, `mallardduck/blade-lucide-icons`.
- **thin-app only** (never in the package): `laravel/tinker`, `nvade/*` modules.

Every `suggest`ed package needs its guard written **before** its code is copied
in Phase 4, otherwise installing numerosis drags the whole stack in.

**Done when:** the file exists and every entry in saas-m's `require` block
appears exactly once.

---

## Phase 2 — Complete the feature layer (still in saas-m)

### 2.1 Feature classes for the surfaces that are still unconditional

Add, following the shape of `app/Features/Auth/PasswordResetFeature.php`
(a `NAME` const, `featureName()`, `bootstrap()`, and a docblock explaining what
turning it off removes):

| New class | Gates |
|---|---|
| `App\Features\Ui\AdminPanelFeature` | `AdminPanelProvider` registration |
| `App\Features\Ui\TenantPanelFeature` | `TenantAdminPanelProvider` registration |
| `App\Features\Tenancy\MembershipsFeature` | tenant membership UI + routes |

Register each in `config/numerosis.php`'s `features` array with a comment in the
same style as its neighbours. **Chat is not a feature class** — it is an
app-side module, gated by `ModuleSystemFeature` plus the module's own row.

### 2.2 Prove the flag actually gates everything

**Do:** add `tests/Feature/Features/FeatureIsolationTest.php`. For each feature
class: boot the app with that class removed from `Features::forceForTesting()`
(set **before** `parent::setUp()` — see the docblock on `App\Support\Features`),
then assert none of its routes resolve, its Livewire components are not
registered, and its policies are not bound.

**Done when:** the test passes with all features on *and* with each one
individually off.

---

## Phase 3 — Design the host-app seam (still in saas-m)

Everything in `bootstrap/app.php` cannot ship inside a package. Phase 3 builds
the API that thin-app will call; it does not create thin-app yet.

### 3.1 `Numerosis::middleware()` and `Numerosis::routes()`

**Do:** add to `App\Support\Numerosis`:

```php
public static function middleware(\Illuminate\Foundation\Configuration\Middleware $middleware): void
public static function routes(): void
public static function broadcasting(): array   // the channel-route middleware stack
public static function csrfExceptions(): array // ['stripe/*', 'billing/webhook', 'telescope/*']
```

Move the bodies out of `bootstrap/app.php` into these methods verbatim:

- aliases `invitation.status`, `tenancy.identification`, `tenancy.route`,
  `tenancy.session`;
- groups `tenant` (= `web`, `tenancy.identification`, `tenancy.route`,
  `tenancy.session`) and `universal` (empty on purpose);
- the `foreach (Config::array('tenancy.central_domains') …)` route loop and the
  `Route::middleware('tenant')` group.

Then make `bootstrap/app.php` call them. **Behaviour must not change** — this is
a pure move.

**Trap:** `TenancyServiceProvider::makeTenancyMiddlewareHighestPriority()` must
keep running; `.claude/rules/auth-guards.md` and `filament-tenancy.md` both
depend on identification running before everything else.
`EnsureSessionMatchesTenant` must stay registered **after** `StartSession`.

**Done when:** the full suite's failure count is still 9 (`vendor/bin/sail
artisan test --compact`, ~5 minutes; this is one of the few times a full run is
warranted).

### 3.2 Write down the config the package cannot own

**Do:** create `~/repos/private/numerosis/docs/host-requirements.md` listing,
for each file, the exact keys thin-app must provide:

| File | What thin-app must own |
|---|---|
| `config/tenancy.php` | stancl's own filename — publishing its stub silently drops our bootstrappers, because `mergeConfigFrom` merges one level deep. thin-app owns the whole file; the package only documents the required keys: `tenant_model`, `domain_model`, `central_user_model`, `tenant_user_model`, `bootstrappers` (incl. `SpatiePermissionsBootstrapper`, `AuthGuardBootstrapper`), `migration_parameters`, `seeder_parameters`, `central_domains`, `filesystem.disks` (must **not** contain `livewire`) |
| `config/database.php` | `central` + `tenant` connections, `DB_LOCK_WAIT_TIMEOUT` init statement setting **both** `lock_wait_timeout` and `innodb_lock_wait_timeout` |
| `config/auth.php` | `defaults.guards.context.central = 'web'`, `…context.tenant = 'tenant'`, both providers |
| `config/session.php` | `SESSION_DOMAIN` with a leading dot |
| `config/filesystems.php` + `config/livewire.php` | the dedicated `livewire` disk, absent from `tenancy.filesystem.disks` |
| `config/cashier.php`, `config/permission.php`, `config/broadcasting.php` | as published by their own packages |

Copy the *reasons* from `.claude/rules/{tenant-filesystem,auth-guards,testing}.md`
into that doc; a consumer without the reason will "fix" it wrong.

---

## Phase 4 — Freeze, copy, rename

This is the irreversible-feeling phase. Read it fully before starting.

> **Amended by D8 (2026-08-05):** step 4.4 below — "models: abstract base in
> the package, concrete in thin-app" — is **reversed**. Package models are
> concrete; stubs are optional convenience, not a precondition for booting.
> Read D8's migration list before touching anything in `src/Models/`.

### 4.0 Preconditions (all must hold)

```bash
cd ~/repos/private/saas-m    && git status --short           # empty
cd ~/repos/private/saas-m    && vendor/bin/sail artisan test --compact | tail -3   # 9 failures, no more
cd ~/repos/private/numerosis && git status --short           # empty (commit Phase 0 first)
cd ~/repos/private/numerosis && vendor/bin/pest --ci && vendor/bin/phpstan analyse --no-progress
```

If numerosis still shows the uncommitted Phase-0 diff, commit it now:

```bash
cd ~/repos/private/numerosis
git add -A && git commit -m "chore: configure package skeleton for numerosis"
```

### 4.1 Freeze saas-m

From this point saas-m is **read-only**. No feature work, no fixes there. If
something must change, change it in numerosis or thin-app. Announce the freeze
in the commit message of the last saas-m commit.

### 4.2 Copy — exact destinations

Run from `~/repos/private/saas-m`. Create destination directories as needed.

**`app/` → `numerosis/src/` (whole directories, no exceptions except Providers):**

| Source | Destination | Notes |
|---|---|---|
| `app/Actions` (69) | `src/Actions` | |
| `app/Concerns` (8) | `src/Concerns` | |
| `app/Console` (7) | `src/Console` | commands registered by the package provider |
| `app/Contracts` (26) | `src/Contracts` | |
| `app/Data` (10) | `src/Data` | |
| `app/Enums` (5) | `src/Enums` | |
| `app/Events` (10) | `src/Events` | |
| `app/Exceptions` (27) | `src/Exceptions` | |
| `app/Facades` (1) | `src/Facades` | plus the new `Numerosis` facade |
| `app/Features` (11+3) | `src/Features` | |
| `app/Filament` (96) | `src/Filament` | converted to plugins in Phase 8 |
| `app/Http` (15) | `src/Http` | |
| `app/Jobs` (1) | `src/Jobs` | |
| `app/Listeners` (10) | `src/Listeners` | |
| `app/Livewire` (18) | `src/Livewire` | |
| `app/Models` (20) | `src/Models` | become `abstract`; see 4.4 |
| `app/Notifications` (5) | `src/Notifications` | |
| `app/Observers` (9) | `src/Observers` | |
| `app/Policies` (6) | `src/Policies` | |
| `app/Rules` (1) | `src/Rules` | |
| `app/Services` (21) | `src/Services` | |
| `app/Support` (6) | `src/Support` | |
| `app/Testing` (1) | `src/Testing` | ship publicly |

**`app/Providers` (6) — split, do not bulk-copy:**

| File | Goes to |
|---|---|
| `TenancyServiceProvider.php` | `numerosis/src/Providers/` |
| `BillingServiceProvider.php` | `numerosis/src/Providers/` |
| `AppServiceProvider.php` | **thin-app** (`app/Providers/`), keeping only app-specific bindings; the feature-bootstrap loop moves into `NumerosisServiceProvider` |
| `TelescopeServiceProvider.php` | **thin-app** |
| `Filament/AdminPanelProvider.php` | **thin-app** (it will register the package plugin) |
| `Filament/TenantAdminPanelProvider.php` | **thin-app** (same) |

**Everything else:**

| Source | Destination |
|---|---|
| `routes/{web,auth,tenant,channels,console}.php` (206 lines total) | `numerosis/routes/` — loaded conditionally per feature |
| `database/migrations/*.php` (64 files) | `numerosis/database/migrations/central/` |
| `database/migrations/tenant/*` (25) | `numerosis/database/migrations/tenant/` |
| `database/seeders/{DatabaseSeeder,PaymentPlanSeeder,RoleAndPermissionSeeder,TenantDatabaseSeeder}.php`, `database/seeders/Central/`, `database/seeders/Tenant/` | `numerosis/database/seeders/` — namespace `Nvade\Numerosis\Database\Seeders`; thin-app keeps a `Database\Seeders\DatabaseSeeder` that calls them |
| `database/factories/**` | `numerosis/database/factories/` (namespace `Nvade\Numerosis\Database\Factories`) |
| `resources/views/{components,filament,flux,layouts,livewire,pages,partials}` (~105) | `numerosis/resources/views/` |
| `resources/views/errors` (10), `resources/views/vendor` (35) | **thin-app** `resources/views/` |
| `resources/{css,js}` | `numerosis/resources/` — sources only; thin-app owns the vite build (Phase 9) |
| `lang/en`, `lang/vendor` | `numerosis/resources/lang/` (`loadTranslationsFrom`, namespace `numerosis`) |
| `tests/**` (130 files) | `numerosis/tests/` except the ones listed in 6.3 |
| `app-modules/{alerts,announcements,branding,chat,notes,tasks}` | **thin-app** `app-modules/` |
| `docker/`, `docker-compose.yml`, `vite.config.js`, `package.json`, `bootstrap/`, `public/`, `artisan`, `.env.example` | **thin-app** root |
| `.claude/` (rules, plans), `CLAUDE.md`, `AGENTS.md`, `pint.json`, `rector.php`, `boost.json` | **both** numerosis and thin-app |
| `config/*.php` | see 3.2 — package configs to numerosis, framework/vendor configs to thin-app |

### 4.3 Namespace rewrite

After copying into `numerosis/src`:

```bash
cd ~/repos/private/numerosis
grep -rl 'App\\' src tests database routes | xargs sed -i \
  -e 's/namespace App\\/namespace Nvade\\Numerosis\\/g' \
  -e 's/use App\\/use Nvade\\Numerosis\\/g' \
  -e 's/\\App\\\\/\\Nvade\\\\Numerosis\\\\/g'
grep -rn "App\\\\" src | grep -v "Nvade" | head -40      # expect: only genuine app-side references
```

Then, one directory at a time (start with `src/Contracts`, `src/Enums`,
`src/Data` — the leaves), run:

```bash
composer dump-autoload
vendor/bin/phpstan analyse --no-progress | tail -20
```

**Rule:** do not proceed to the next directory while PHPStan reports unresolved
classes in the current one. `Class "…" not found` here almost always means a
missed `use` rewrite, not a broken autoloader — the same pattern
`.claude/rules/testing.md` documents.

**Factories cannot be checked statically.** Laravel resolves
`App\Models\Tenant\User` → `Database\Factories\Tenant\UserFactory` from a
runtime-built string. After the rewrite, `Nvade\Numerosis\Models\Tenant\User`
must find `Nvade\Numerosis\Database\Factories\Tenant\UserFactory`. Set the
resolver explicitly in the package `TestCase` (the skeleton already has a
`Factory::guessFactoryNamesUsing` call — extend it to keep sub-namespaces, not
just `class_basename`).

### 4.4 Models: abstract base in the package, concrete in thin-app

For each of `Tenant`, `Domain`, `CentralUser`, `Tenant\User`, `Subscription`,
`PaymentPlan`, `Invitation`, `Module`, `PendingTenantProvision`:

1. In numerosis, make the class `abstract` and keep all behaviour.
2. Ship a stub under `numerosis/stubs/` that thin-app publishes:
   `class Tenant extends \Nvade\Numerosis\Models\Central\Tenant {}`.
3. Point config at the concrete class (`tenancy.tenant_model`,
   `tenancy.central_user_model`, `tenancy.tenant_user_model`,
   `billing.models.*`).

**Traps that will bite here:**
- `#[UsePolicy]` attributes are **not inherited** — see `.claude/rules/auth-guards.md`.
  Whatever carries the attribute today must still carry it after the split.
- `Tenant` composes `VirtualColumn`; `getCustomColumns()` and `Fillable` must
  travel together (`.claude/rules/tenant-provisioning.md`).
- `CentralUser::guardName()` returns `'web'`, `Tenant\User::guardName()` returns
  `['tenant']`. Do not "fix" these to be context-dependent.

---

## Phase 5 — Wire the package

All in numerosis, host PHP.

### 5.1 `NumerosisServiceProvider`

Replace the skeleton's `configurePackage()` body so it:

- `mergeConfigFrom` for `numerosis` (three files until D13 merged them into
  one, 2026-08-06);
- `loadViewsFrom(__DIR__.'/../resources/views', 'numerosis')`;
- `loadTranslationsFrom(__DIR__.'/../resources/lang', 'numerosis')`;
- `loadMigrationsFrom(__DIR__.'/../database/migrations/central')` — **central
  only**; tenant migrations are run by stancl through
  `tenancy.migration_parameters`, which must use an **absolute** path
  (`--realpath`) because the files now live under `vendor/`;
- registers `App\Support\Features`' boot loop (moved from `AppServiceProvider`);
- conditionally registers `TenancyServiceProvider` and `BillingServiceProvider`;
- declares publish groups: `numerosis-config`, `numerosis-migrations`,
  `numerosis-tenant-migrations`, `numerosis-views`, `numerosis-assets`,
  `numerosis-models`, `numerosis-stubs`.

### 5.2 `numerosis:install`

An artisan command that publishes config + model stubs, appends env keys, and
then **verifies** (fails loudly, does not merely print):

- `central` and `tenant` connections exist in `config('database.connections')`;
- `config('session.domain')` starts with a dot;
- `config('auth.defaults.guards.context.central')` and `…tenant` resolve to real
  guards;
- `'livewire'` is **not** in `config('tenancy.filesystem.disks')`;
- `config('tenancy.migration_parameters')` path exists and is absolute;
- Stripe keys are set;
- prints the manual steps: wildcard DNS, panel plugin registration, and a queue
  worker on the dedicated `provisioning` queue (see
  `docker/8.5/supervisord.conf`'s `[program:queue-provisioning]` in saas-m).

### 5.3 Missing contracts

Add, mirroring the 8 that already exist under `Contracts/Billing`:

| Contract | Replaces |
|---|---|
| `Tenancy\TenantDatabaseManager` | direct stancl job-list coupling |
| `Invitations\InvitationRepository` | direct `Invitation` queries |
| `Modules\ModuleRegistry` | `InteractsWithTenantModules`' inline queries — must serve thin-app's 6 modules without the package knowing their names |
| `Auth\SocialAccountRepository` | `SocialiteLogin` direct use |
| `Notifications\NotifiesTenantOwner` | notification class hardcoding |

Traits to ship: `IsTenantModel`, `IsCentralUser`, `IsTenantUser`,
`HasGlobalIdentity`, `BelongsToTenant`, `HasTenants`, `Billable` (move as-is),
`TagsSentryScopeWithTenant`, `PublishesPackageAssets`.

Do **not** add `Chat\*` contracts — chat is an app-side module.

---

## Phase 6 — Test harness in the package

### 6.1 MySQL, not sqlite

Tenancy needs `CREATE DATABASE`. Point `testbench.yaml` at the same MySQL saas-m
uses, or a local one. Add a MySQL service to `.github/workflows/run-tests.yml`
(the matrix comment already says this is where it lands).

### 6.2 Port the whole test-support layer, not just the fast trick

From saas-m `tests/`, all of these are load-bearing (`.claude/rules/testing.md`):

- `Support/CloneTenantSchema` — the ~0.19s vs ~1.9s per-tenant difference. It
  must implement `ShouldQueue` (chain links must be real jobs) and must **never**
  touch the default connection.
- `TestCase::deleteCentralWrites()` and `deleteTenantDatabases()` — each in its
  **own** `try/finally`; sharing one block leaks tenant databases on the ~9
  lock-timeout tests.
- `TestCase::keepSchema()` pinning `RefreshDatabaseState::$migrated = true`.
- The `TenancyServiceProvider::$tenantCreatedJobs` override, assigned from
  `tests/Pest.php` — it must run before the first app boot; `setUp()` is too late.
- `Concerns/PinsGlobalCache`.
- `TestCase::actingAsTenantPanelUser()` — export it as
  `Testing\InteractsWithTenantPanel` for consumers.
- Session variables `lock_wait_timeout` **and** `innodb_lock_wait_timeout`.

### 6.3 Which tests go where

| Test subject | Repo |
|---|---|
| actions, models, policies, billing, provisioning, Livewire components, Filament resources | numerosis |
| `bootstrap/app.php` wiring, middleware group order, vite/asset presence, module install | thin-app |
| a module's own behaviour | that module's package under `thin-app/app-modules/*` |

### 6.4 Migrations — do not squash

The live deployment's `migrations` table records today's 64+25 filenames. A
squashed set under new package paths re-runs from zero against a populated
database. If squashing is ever wanted it is separate work that also ships a
schema dump plus pre-seeded `migrations` rows.

---

## Phase 7 — Create thin-app

```bash
cd ~/repos/private
laravel new thin-app --no-interaction
cd thin-app && git init && git add -A && git commit -m "chore: laravel new"
```

Then:

1. Add the path repo and require the package:
   ```jsonc
   "repositories": [{ "type": "path", "url": "../numerosis", "options": { "symlink": true } }],
   "require": { "nvade/numerosis": "@dev" }
   ```
   `composer update nvade/numerosis`.
2. Copy from saas-m (per 4.2): `docker/`, `docker-compose.yml`, `.env.example`,
   `vite.config.js`, `package.json`, `bootstrap/app.php`, `public/`,
   `app-modules/*`, `resources/views/{errors,vendor}`, the framework configs, the
   Filament panel providers, `.claude/`, `CLAUDE.md`, `AGENTS.md`.
3. Rewrite `bootstrap/app.php` to call `Numerosis::middleware()`,
   `Numerosis::routes()`, `Numerosis::broadcasting()`,
   `Numerosis::csrfExceptions()` (built in 3.1).
4. `vendor/bin/sail up -d` then `vendor/bin/sail artisan numerosis:install`.
5. Publish and adjust the model stubs (4.4).

**Local-environment trap:** `WWWUSER`/`WWWGROUP` must be set in `.env`, or the
container runs as uid 1337 and every `artisan make:*`, Pest cache write and
Playwright run fails with confusing permission errors
(`.claude/rules/tenant-provisioning.md`, "Local environment").

---

## Phase 8 — Filament as plugins

**Marked done at the time, was not — see `.claude/plans/cleanup-package-extraction.md`
item A.** No `Filament\Contracts\Plugin` class existed; 545 lines of panel
definition were hand-copied across thin-app and the package's Workbench
harness and had already drifted. Fixed 2026-08-06.

- `NumerosisAdminPlugin` and `NumerosisTenantPlugin` implement
  `Filament\Contracts\Plugin`; thin-app's panel providers register them.
- Resource discovery currently uses `app_path('Filament/…')` in both providers —
  becomes `__DIR__`-relative with explicit `for:` namespaces, and each cluster
  individually opt-in via `->resources([...])` rather than directory discovery.
- The plugin must **not** bridge stancl's tenant into `Filament::setTenant()` at
  bootstrap. `.claude/rules/filament-tenancy.md` explains why that cannot work:
  stancl's identification is forced ahead of Filament's panel setup, so there is
  no panel to set a tenant on at that moment.
- Panels must pin their guard explicitly (`->authGuard('tenant')` /
  central guard) — riding `auth.defaults.guard` is wrong now that it moves
  mid-request.

---

## Phase 9 — Assets

Package ships **sources**, thin-app owns the build.

- `resources/css/app.css`, `resources/js/{app,bootstrap,central,tenant,stripe-appearance,stripe-checkout,stripe-confirm}.js`
  publish under `numerosis-assets`.
- `resources/views/partials/styles.blade.php` calls
  `@vite(['resources/css/app.css','resources/js/app.js'])` plus `central.js` and
  (inside tenant context) `tenant.js`. Document these as the exact vite entry
  points thin-app must declare.
- The three `stripe-*.js` files are load-bearing for checkout, not decoration —
  a missing entry point breaks payment, not styling.
- Prerequisites documented for consumers: Tailwind v4, `livewire/flux` (58 views
  use `flux:` components), `openplain/filament-shadcn-theme`.

---

## Phase 10 — Verification gate, then archive

Every item must pass. STOP and ask the user before the archive step — retiring a
repo is confirmed in the moment, never pre-authorised by this document.

```bash
# 1. package
cd ~/repos/private/numerosis
composer install && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress && vendor/bin/pest --ci

# 2. app boots
cd ~/repos/private/thin-app
vendor/bin/sail up -d && vendor/bin/sail artisan numerosis:install && vendor/bin/sail artisan route:list | head

# 3. end-to-end
#    - register a tenant through the wizard (or StartLocalCheckout on local)
#    - confirm the provisioning chain ran on the `provisioning` queue
#    - confirm tenants.provisioned_at is set (NOT just that a tenants row exists)
#    - log into the tenant panel on the tenant subdomain
#
#    NOT REPRODUCIBLE AS WRITTEN: the run recorded in "Resume at" above used
#    `StartLocalCheckout::run()` in tinker, not the browser wizard, and the
#    fixture tenant/user/database were deleted afterward. R10 already asked
#    for this to be a Pest browser test in thin-app — that test does not
#    exist yet. Treat this gate item as manual and re-run it by hand before
#    trusting it again; do not assume a prior "passed" carries forward.

# 4. modules
vendor/bin/sail artisan tenants:migrate-module branding   # then check modules.migrated_at is stamped
```

Then, and only then: archive `git@gitlab.com:nvade_/saas-m.git` read-only on
GitLab, and add to `.claude/rules/INDEX.md` in **both** new repos a line stating
that bare commit hashes in the rules refer to that archived repo.

---

## Traps that will bite, with their tells

| Symptom | Real cause | Where it is written down |
|---|---|---|
| `Missing required parameter for [Route: filament.tenantAdmin…]` | Filament's tenant not set (tests only) | `filament-tenancy.md` |
| 404 from a tenant panel | tenant `id` was generated, not set (`Fillable`) | `tenant-provisioning.md` |
| `Unknown column 'last_seen_at'` on connection `central` | ambient guard moved; tenant-only write hit central | `auth-guards.md` |
| `There is no permission named …` (500, not 403) | permissions not seeded | `auth-guards.md` |
| Upload rejected as wrong mimetype, file exists on disk | Livewire temp disk vs tenant-suffixed `local` root | `tenant-filesystem.md` |
| A user resolves with **zero** `select … from users` queries | cached Eloquent model leaking across tenants | `tenant-caching.md` |
| `Module [x] not found` after adding a module | stale in-process module registry; restart the queue worker | `module-marketplace.md` |
| Job succeeded but did nothing | `Artisan::call()` exit code discarded | `exception-handling.md` |
| Suite hangs forever | stranded MySQL session / two runs at once | `testing.md` |
| `Class "…" not found` after a rename | missed reference, not autoloader | `testing.md` |

## Order of execution

Phase 1 → 2 → 3 (all in saas-m, suite stays green) → 4 (freeze + copy + rename)
→ 5 (wire package) → 6 (harness) → 7 (thin-app) → 8 (Filament plugins) →
9 (assets) → 10 (gate, then archive).

**Amended by R3:** `… → 5 → 6.1-6.2 → 7.1-7.4 → 6.3-6.6 → 7.5 → 8 → 9 → 10`.
thin-app comes up *before* the package suite is finished, because a real host
is the only thing that has ever caught the host-seam bugs (`base_path()` in
`Numerosis::routes()`, the missing `numerosis::` view namespace), and because
several remaining Phase-6 failures are things a host owns.

Phases 1-3 ship value even if the extraction stalls: saas-m ends up
de-hardcoded either way.

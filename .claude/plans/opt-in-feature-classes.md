# Opt-In Feature Surface — executable plan

**Status: ✅ Executed** (correction 2026-08-10 — previous status line was
stale, written when only Turnstile existed). `Nvade\Numerosis\Contracts\NamedFeature`
is the contract; 13 Feature classes implement it across `src/Features/{Turnstile,
Social,Modules,Invitations,Tenancy,Billing,Observability,Ui,Auth}/`, each with
its own `isEnabled()`/`bootstrap()` and a docblock explaining its toggle
semantics (see `TurnstileFeature` for the canonical shape). `config/numerosis.php`'s
`'features' => [...]` class-string array is the single toggle, exactly as
decided below — comment a line out, feature is gone.

**Decided (2026-08-04, by the user): the class-string array in
`config('numerosis.features')` is the toggle.** No boolean feature map, no
second config file per subsystem. `.claude/plans/package-extraction.md` Phase 2
describes a string-keyed map — **that part of Phase 2 is superseded by this
document**; update it when this ships.

```php
// config/numerosis.php — the one array. Commenting a line out disables it.
'features' => [
    TurnstileFeature::class,
],
```

This revision is written to be executed step by step by an agent that has not
read the rest of the codebase. Every phase is independent (except Phase 0,
which everything depends on), states its exact edits, and ends with a
verification command and a test. **Do one phase per session.** Do not batch
them; several touch the same three files (`AppServiceProvider`, the tenant
panel provider, `routes/web.php`) and interleaving them makes a failure
impossible to attribute.

---

## Flaws found in review of *this* revision (all fixed below)

Second review pass, 2026-08-04. Two blocking, verified against the tree:

- **Phase 5.1 was blocked, not conditional.** `app/Models/User.php:56` — the
  shared abstract base implements `MustVerifyEmail`, so the grep the phase
  deferred to already answers positive. Rewritten as ⛔ blocked with the three
  real options.
- **Phase 2.5 gated cancellation on the marketplace switch**, which would have
  left existing subscribers unable to stop being billed when a deployment turns
  the marketplace off. Split into two guards on two switches.

Non-blocking, also fixed: the `forceForTesting` reset leaked whenever
`parent::setUp()` threw (moved into `beforeApplicationDestroyed`); route
caching made every route-absence assertion vacuous (documented in Ground
rules); `Config::boolean()` throws on a `.env` value of `1` (casts added to the
booleans that remain — Phase 2's were removed outright, see below);
Phase 7.2's frequency map was PHPStan-hostile and less capable than the lines
it replaced (booleans instead); `class_exists(Telescope::class)` is always true
because `laravel/telescope` sits in `require` (stated, dependency move raised
as a question); `ConfiguredProviders` tested key presence rather than
credentials, so buttons rendered for unconfigured providers (now
`filled(...client_id)`); Phase 1.7 reproduced the empty-wrapper bug 1.6 fixed;
`Features::enabled()` scanned per call (memoised name map, with the
invalidation trap that creates); the listener gating in Phases 3 and 5.2
contradicted a Ground rule without saying so (now a named exception).

**Phase 2 was then rewritten again, at the user's request (2026-08-04):** the
module system and marketplace are now two `NamedFeature` classes in
`config('numerosis.features')`, not booleans in `config/modules.php`. This
removes the two-places-to-look split the previous revision accepted as a cost,
and drops the `MODULES_ENABLED` / `MODULE_MARKETPLACE_ENABLED` env vars
entirely. `ModuleSwitches` survives as the single holder of the
marketplace-implies-modules rule, now reading `Features::enabled()`. It also
changes how Phase 2 is tested — `config([...])` no longer moves the switch, see
2.6.

**Phase 7.3 converted on the same request**: the activity log is now
`App\Features\Observability\ActivityLogFeature`, not a
`numerosis.activity_log` boolean — same shape and same argument as Phase 2's
pair.

**One deliberate boolean remains**: `numerosis.schedule.*` (7.2). It stays a
boolean because `routes/console.php` is a scheduling surface, not an app
feature — "does this deployment's cron run this command" is an operations
question, and a consumer running their own scheduler wants it in config next to
other operational settings, not in a list of product features. Feature classes
are for surfaces the app *has*; these are for jobs the app *runs*.

Two things this pass verified rather than fixed: the chat status columns *are*
core (`database/migrations/tenant/2026_05_01_000002_…`), and there is an
identical duplicate of that migration inside `app-modules/chat` — folded into
Phase 10.2 step 3.

## Flaws found in the revision before that (all fixed below)

1. **The registry was left as an open question that every later section
   depended on.** Now decided and fully specified in Phase 0.
2. **Line numbers were used as anchors.** They drift. Every edit below anchors
   on a unique code string to match, not a line number.
3. **"Gate the `withBroadcasting()` call in `bootstrap/app.php`" is not
   doable as written.** `bootstrap/app.php` runs before the framework loads
   config *or* `.env`, so neither `config()` nor `env()` is reliable there.
   Realtime gating is downgraded to what actually works and moved to the
   deferred list — see §D3.
4. **The auth-surface table assumed a live password-login surface.** Verified:
   `app/Livewire/Auth/Login.php` and `resources/views/livewire/auth/login.blade.php`
   are **dead code** — no route resolves to them (`routes/auth.php` wires only
   `PasswordlessLogin`; `AdminPanelProvider` calls Filament's own `->login()`
   with no argument). So the "two competing login features need a mutual
   exclusion assertion" design solved a problem that does not exist. Replaced
   by Phase 9, which deletes the dead surface, and the password-reset feature
   in Phase 6, which is real.
5. **The chat coupling fix listed three options and picked none**, while being
   the highest-severity item in the document. Now Phase 10, with one
   recommended mechanism spelled out in code — and flagged **STOP: confirm with
   the user first**, because it changes how a core model resolves relations.
6. **Roles/permissions gating (old §13) was under-specified and dangerous** — a
   missing permission row throws `PermissionDoesNotExist` (a 500, not a 403;
   see `.claude/rules/auth-guards.md`), so a half-disabled state is worse than
   either end. Moved to deferred, §D2.
7. **No test mechanism was given for a feature that gates routes.** Routes are
   registered during app boot, so `config([...])` inside a test body is too
   late. Phase 0 ships `Features::forceForTesting()` plus the exact `setUp()`
   ordering that makes route-absence assertions work.
8. **Filament resource gating named a non-existent hook.** Verified against
   vendor: `canAccess()` lives on
   `Filament\Resources\Resource\Concerns\HasAuthorization`, and
   `shouldRegisterNavigation()` on
   `Filament\Resources\Resource\Concerns\HasNavigation` — both exist and both
   are what to override. Pages use
   `Filament\Pages\Concerns\CanAuthorizeAccess::canAccess()`.

---

## Ground rules for whoever executes this

- **Sail for everything.** `vendor/bin/sail artisan …`, `vendor/bin/sail bin pint …`,
  `vendor/bin/sail exec -T laravel.test bash -lc "vendor/bin/phpstan analyse"`.
- **Run tests once, scoped to what you touched**
  (`vendor/bin/sail artisan test --compact --filter=SomeTest`). Do not run the
  full suite unless asked. The suite has a known-failing baseline of ~9
  lock-wait timeouts — see `.claude/rules/testing.md` before blaming a failure
  on your change.
- **PHPStan is level 9 over `app/` and `tests/` and is currently red on
  `master`** (~98 errors outside the baseline, see
  `.claude/rules/static-analysis.md`). Compare your error count against a
  stash, do not expect zero.
- **Pint after every phase**: `vendor/bin/sail bin pint --dirty --format agent`.
- **Never add a new base directory** and never change composer dependencies.
- **Config must stay cacheable** — the features array holds class-strings only,
  never closures. After toggling anything locally, run
  `vendor/bin/sail artisan config:clear && vendor/bin/sail artisan route:clear`.
- **A disabled feature must load nothing**: no routes, no views, no Livewire
  components, no Filament pages. Assert the absence in a test, not merely that
  nothing crashed.
  **Named exception — event listeners.** Laravel discovers listeners by
  scanning `app/Listeners`; a feature class cannot un-discover them. Phases 3
  and 5.2 therefore gate listeners with an early return inside `handle()`: the
  listener is still registered, still resolved, still invoked, and does
  nothing. That is a deliberate departure from the rule above, not an
  oversight. Do not "fix" it by fighting discovery.
- **Route gating and route caching.** Every `Features::enabled()` call in a
  `routes/*.php` file is evaluated once, at route-registration time, and is
  therefore **baked into `route:cache`**. Two consequences:
  - Production: toggling a feature requires `route:clear` + re-cache, not just
    `config:clear`. Say so in any deployment note.
  - Tests: `Features::forceForTesting()` cannot affect an already-cached route
    table, so every "route is absent when disabled" assertion below is
    **vacuously green** on a cached run. Before writing one, confirm
    `bootstrap/cache/routes-*.php` does not exist; if the suite is ever run
    with cached routes, these tests must be marked skipped rather than left to
    pass for the wrong reason.
- **Migrations are never gated.** Features remove behaviour and UI; dropping
  tables is a separate, destructive decision.
- **Booleans in config get an explicit cast.** `Config::boolean()` is strict:
  `env('SCHEDULE_PRUNE_STALLED_PROVISIONS', true)` returns the *string* `"1"`
  when the `.env` says `SCHEDULE_PRUNE_STALLED_PROVISIONS=1`, and
  `Config::boolean` throws on it. Every boolean key added below is written
  `(bool) env(...)` in the config file. (Only Phase 7.2's two schedule
  switches are booleans at all — every other toggle in this plan is a feature
  class.)

---

## Phase 0 — the registry (do this first, everything depends on it)

**Goal**: let code outside the boot loop ask "is feature X on?" while the
class-string array stays the single source of truth.

### 0.1 New contract — `app/Contracts/NamedFeature.php`

```php
<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A Feature that other code can ask about by name.
 *
 * The toggle is still presence of the class in config('numerosis.features') —
 * this interface only gives that presence a stable name so route files,
 * providers and Blade can check it without importing the class everywhere.
 * Implementers declare `public const NAME = '…'` and return it here.
 *
 * Features::enabled() takes a plain string, so nothing *forces* a call site to
 * pass SomeFeature::NAME — passing a literal that no feature declares is a
 * silently-false check, not an error. Convention only: always pass the
 * constant. The one thing that is enforced is that two features cannot share
 * a name (Features::names() throws on a collision).
 */
interface NamedFeature extends Feature
{
    public static function featureName(): string;
}
```

### 0.2 New registry — `app/Support/Features.php`

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\Feature;
use App\Contracts\NamedFeature;
use Illuminate\Support\Facades\Config;

/**
 * Reads config('numerosis.features') — the one place a feature is switched on
 * or off — and answers questions the boot loop cannot: route files, service
 * providers and Blade all need "is this on?" at moments the loop has already
 * run or has not run yet.
 *
 * $forcedForTesting is a plain static, not container-scoped, so it survives a
 * fresh Application boot within the same PHP process (same trap
 * .claude/rules/testing.md documents for Tenant::unsetEventDispatcher()).
 * A test that forces features must set them BEFORE parent::setUp(), because
 * routes are registered while the application boots — see the reset note in
 * Tests\TestCase::setUp().
 */
final class Features
{
    /** @var list<class-string<Feature>>|null */
    private static ?array $forcedForTesting = null;

    /** @var array<string, class-string<Feature>>|null */
    private static ?array $nameMap = null;

    /**
     * @return list<class-string<Feature>>
     */
    public static function all(): array
    {
        if (self::$forcedForTesting !== null) {
            return self::$forcedForTesting;
        }

        /** @var list<class-string<Feature>> $features */
        $features = Config::array('numerosis.features');

        return $features;
    }

    /**
     * @param  class-string<Feature>  $class
     */
    public static function enabledClass(string $class): bool
    {
        return in_array($class, self::all(), true);
    }

    public static function enabled(string $name): bool
    {
        return isset(self::names()[$name]);
    }

    /**
     * Built once per resolved feature list rather than scanned per call —
     * enabled() is called from inside Blade loops.
     *
     * @return array<string, class-string<Feature>>
     */
    private static function names(): array
    {
        if (self::$nameMap !== null) {
            return self::$nameMap;
        }

        $map = [];

        foreach (self::all() as $class) {
            if (! is_a($class, NamedFeature::class, true)) {
                continue;
            }

            $name = $class::featureName();

            if (isset($map[$name])) {
                throw new LogicException(
                    "Two features claim the name [{$name}]: [{$map[$name]}] and [{$class}].",
                );
            }

            $map[$name] = $class;
        }

        return self::$nameMap = $map;
    }

    /**
     * @param  list<class-string<Feature>>|null  $features  null restores config
     */
    public static function forceForTesting(?array $features): void
    {
        self::$forcedForTesting = $features;
        self::$nameMap = null;
    }
}
```

**The memoised `$nameMap` is why `forceForTesting()` must clear it.** Without
that line the first call wins for the whole process and every later override is
silently ignored — the exact failure that reads as "the toggle does nothing".

### 0.3 Point the boot loop at the registry

In `app/Providers/AppServiceProvider.php`, replace:

```php
        /** @var array<int, class-string<Feature>> $features */
        $features = Config::array('numerosis.features');

        $this->bootstrapFeatures($features);
```

with:

```php
        $this->bootstrapFeatures(Features::all());
```

Add `use App\Support\Features;`. Leave `bootstrapFeatures()` itself alone.
Remove the now-unused `use App\Contracts\Feature;` **only if** nothing else in
the file references it — the `bootstrapFeatures()` docblock still does, so keep
it.

### 0.4 Reset the override — in `setUp()`, not `tearDown()`

**Do not put this in `tearDown()` next to the existing
`TurnstileFeature::forceForTesting(null);`.** The template every route-gating
test below uses sets the override *before* `parent::setUp()`, and
`parent::setUp()` does database work that throws on the suite's known lock-wait
baseline (`.claude/rules/testing.md`). PHPUnit does not call `tearDown()` when
`setUp()` throws, so a forced `[]` would survive into every later test in that
process: routes silently absent, a cascade of failures that look nothing like
their cause.

`Tests\TestCase::setUp()` already registers its cleanup through
`beforeApplicationDestroyed()` precisely because that runs regardless of
outcome. Add the reset to that same closure, as its own `finally` step per the
"each teardown step needs its own finally" rule:

```php
        $this->beforeApplicationDestroyed(function (): void {
            try {
                try {
                    try {
                        $this->deleteCentralWrites();
                    } finally {
                        $this->deleteTenantDatabases();
                    }
                } finally {
                    $this->disconnectAllConnections();
                }
            } finally {
                Features::forceForTesting(null);
            }
        });
```

Add the import. `TurnstileFeature::forceForTesting(null)` stays where it is in
`tearDown()` — it is only ever set from inside a test body, never before
`parent::setUp()`, so it does not have this exposure.

Do **not** touch `TurnstileFeature`'s own statics or its
`isEnabled()` — `tests/Feature/Livewire/Auth/RegisterTest.php` and others call
`TurnstileFeature::forceForTesting(false)` and depend on the current
"was bootstrap() called" semantics. The only change to that class is 0.5.

### 0.5 Give Turnstile a name

`app/Features/Turnstile/TurnstileFeature.php`:

```php
class TurnstileFeature implements NamedFeature
{
    public const NAME = 'turnstile';

    public static function featureName(): string
    {
        return self::NAME;
    }
    // …everything else unchanged…
}
```

Import `App\Contracts\NamedFeature`, drop the now-redundant `App\Contracts\Feature`
import (`NamedFeature extends Feature`, so the class still satisfies the loop).

### 0.6 Test — `tests/Feature/Support/FeaturesTest.php`

```php
<?php

declare(strict_types=1);

use App\Features\Turnstile\TurnstileFeature;
use App\Support\Features;

test('it reports a feature listed in config as enabled', function (): void {
    Features::forceForTesting([TurnstileFeature::class]);

    expect(Features::enabled(TurnstileFeature::NAME))->toBeTrue()
        ->and(Features::enabledClass(TurnstileFeature::class))->toBeTrue();
});

test('it reports an unlisted feature as disabled', function (): void {
    Features::forceForTesting([]);

    expect(Features::enabled(TurnstileFeature::NAME))->toBeFalse()
        ->and(Features::enabledClass(TurnstileFeature::class))->toBeFalse();
});

test('it falls back to config when nothing is forced', function (): void {
    Features::forceForTesting(null);

    expect(Features::all())->toBe(config('numerosis.features'));
});

test('it clears the memoised name map when the override changes', function (): void {
    Features::forceForTesting([TurnstileFeature::class]);
    expect(Features::enabled(TurnstileFeature::NAME))->toBeTrue();

    Features::forceForTesting([]);

    expect(Features::enabled(TurnstileFeature::NAME))->toBeFalse();
});
```

The last test is the one that matters: written against a `Features` whose
`forceForTesting()` forgets `self::$nameMap = null`, it fails. Confirm that
before moving on.

**Done when**: `vendor/bin/sail artisan test --compact --filter=FeaturesTest`
passes, pint clean, PHPStan shows no new errors.

**Do not**: add an `enabled()` overload taking a class-string (that is
`enabledClass()`), and do not make `Features` a container singleton — a static
is deliberate so route files can call it before the container is useful.

---

## Phase 1 — Social login

Two toggles at different altitudes. Both are real; neither replaces the other.

**Verified current shape** (do not re-derive):

- `app/Providers/AppServiceProvider.php` — unconditional
  `Event::listen(function (SocialiteWasCalled $event) { $event->extendSocialite('discord', Provider::class); });`
- `config/auth.php` — `auth.social.routes.*` (route names) and
  `auth.social.providers` (google, github, discord, facebook, gitlab; each
  `label` + `hover`).
- `config/services.php` — credentials for `google` and `discord` only.
- `resources/views/components/auth/buttons/grid.blade.php` — filters with
  `collect(config('auth.social.providers'))->only(array_keys(config('services')))`.
  The old plan's "kill the dead facebook/gitlab buttons" work is already done —
  do not redo it — but **this filter is weaker than it looks and 1.1 fixes
  it**, see below.
- `resources/views/filament/tenant-admin/components/social-accounts-manager.blade.php`
  — **still hardcodes** a google+discord `$providers` array in a `@php` block.
- `routes/auth.php` — `oauth` and `oauth.callback` routes, unconditional.
- Consumers of the grid: `resources/views/livewire/auth/passwordless-login/email-form.blade.php`,
  `resources/views/livewire/invitations/accept.blade.php`, and
  `resources/views/livewire/auth/login.blade.php` (dead — see Phase 9).

### 1.1 Shared provider list — `app/Support/Social/ConfiguredProviders.php`

The `->only(array_keys(config('services')))` derivation must exist once. Two
Blade files deriving it separately is what let the two lists disagree.

```php
<?php

declare(strict_types=1);

namespace App\Support\Social;

use Illuminate\Support\Facades\Config;

/**
 * The social providers that are both described (config/auth.php's
 * auth.social.providers) and actually usable (a client id present in
 * config/services.php). This is the same check
 * App\Http\Controllers\Socialite\Login::ensureProviderIsConfigured() applies
 * on the callback side — a provider with no credentials would render a button
 * that always fails.
 *
 * The test is `filled(config("services.{$key}.client_id"))`, NOT membership in
 * array_keys(config('services')), which is what the two Blade copies did.
 * That was wrong twice over:
 *
 *   1. config/services.php declares `'client_id' => env(key: 'GOOGLE_CLIENT_ID',
 *      default: '')` for both google and discord, so the `google` and `discord`
 *      keys exist unconditionally. A deployment with no OAuth credentials at
 *      all still rendered both buttons, and the failure only surfaced at the
 *      provider's own callback.
 *   2. config('services') also holds postmark, ses, resend, slack and
 *      turnstile. The intersection is only harmless today because none of
 *      those names also appears in auth.social.providers; adding a mail
 *      service whose key collides with a provider name would render a button
 *      for it.
 */
final class ConfiguredProviders
{
    /**
     * @return array<string, array{label: string, hover: string, icon: string}>
     */
    public static function all(): array
    {
        /** @var array<string, array{label: string, hover: string, icon: string}> $providers */
        $providers = collect(Config::array('auth.social.providers'))
            ->filter(fn (array $meta, string $key): bool => filled(
                Config::get("services.{$key}.client_id"),
            ))
            ->all();

        return $providers;
    }
}
```

`Config::get()` rather than `Config::string()` here on purpose: the key is
legitimately absent for a provider nobody configured, and `Config::string()`
throws on a missing key rather than returning null.

Add an `'icon' => '…'` key to all five entries in `config/auth.php`'s
`auth.social.providers` (the account-manager view needs one; the login grid
uses `<x-dynamic-component :component="'icons.'.$key" />` and keeps doing so):

```php
'google'   => ['label' => 'Google',   'hover' => '…', 'icon' => 'heroicon-o-globe-alt'],
'github'   => ['label' => 'GitHub',   'hover' => '…', 'icon' => 'heroicon-o-code-bracket'],
'discord'  => ['label' => 'Discord',  'hover' => '…', 'icon' => 'heroicon-o-chat-bubble-left-right'],
'facebook' => ['label' => 'Facebook', 'hover' => '…', 'icon' => 'heroicon-o-globe-alt'],
'gitlab'   => ['label' => 'GitLab',   'hover' => '…', 'icon' => 'heroicon-o-code-bracket'],
```

Keep each existing `hover` value verbatim.

### 1.2 Feature class — `app/Features/Social/SocialLoginFeature.php`

```php
<?php

declare(strict_types=1);

namespace App\Features\Social;

use App\Contracts\NamedFeature;

/**
 * The whole OAuth surface: the oauth/oauth.callback routes, the button grid,
 * the connected-accounts manager, and the two SocialAccount* listeners.
 * Remove this class from config('numerosis.features') and a deployment has no
 * social login at all — no routes, no buttons, no listeners. The
 * socialite_logins table and any rows in it are untouched; disabling stops new
 * connections and hides the UI, it does not delete history.
 *
 * Per-provider extension packages are separate features (see
 * RegistersDiscordProvider) — a provider Socialite supports natively needs
 * only credentials in config/services.php.
 */
class SocialLoginFeature implements NamedFeature
{
    public const NAME = 'social';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Registration is declarative: routes and views ask
        // Features::enabled(self::NAME). Nothing to do at boot.
    }
}
```

### 1.3 Feature class — `app/Features/Social/RegistersDiscordProvider.php`

```php
<?php

declare(strict_types=1);

namespace App\Features\Social;

use App\Contracts\NamedFeature;
use Illuminate\Support\Facades\Event;
use SocialiteProviders\Discord\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

/**
 * Discord is not a Socialite core driver — it needs the
 * socialiteproviders/discord package registered against SocialiteWasCalled.
 * Google, GitHub, GitLab and Facebook are core drivers and need no feature
 * class: credentials in config/services.php plus a metadata entry in
 * config('auth.social.providers') is the whole opt-in.
 */
class RegistersDiscordProvider implements NamedFeature
{
    public const NAME = 'social.discord';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('discord', Provider::class);
        });
    }
}
```

Delete the `Event::listen(function (SocialiteWasCalled $event) …)` block from
`AppServiceProvider::boot()` along with its two now-unused imports
(`SocialiteProviders\Discord\Provider`, `SocialiteProviders\Manager\SocialiteWasCalled`).

### 1.4 Register both in `config/numerosis.php`

```php
'features' => [

    // Requires TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY — see .env.example.
    TurnstileFeature::class,

    // OAuth login. Providers are configured in config/auth.php
    // (auth.social.providers) + config/services.php (credentials).
    SocialLoginFeature::class,

    // Discord needs a Socialite extension package; core drivers do not.
    RegistersDiscordProvider::class,

],
```

### 1.5 Gate the routes — `routes/auth.php`

Wrap the two oauth routes:

```php
if (Features::enabled(SocialLoginFeature::NAME)) {
    Route::get('/oauth/{driver}/callback', Social\Login::class)
        ->name('oauth.callback');

    Route::get('/oauth/{driver}', Social\Redirect::class)
        ->domain(config('app.central.default'))
        ->name('oauth');
}
```

### 1.6 Gate the button grid — `resources/views/components/auth/buttons/grid.blade.php`

```blade
@props([
    'invitation' => null,
])

@php
    $providers = \App\Support\Features::enabled(\App\Features\Social\SocialLoginFeature::NAME)
        ? \App\Support\Social\ConfiguredProviders::all()
        : [];
@endphp

@if ($providers !== [])
    <div class="flex flex-col gap-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach ($providers as $key => $meta)
                <x-auth.buttons.social :provider="$key" :invitation="$invitation" class="{{ $meta['hover'] }}">
                    <div class="flex items-center">
                        <x-dynamic-component :component="'icons.'.$key" />
                        <span>{{ $meta['label'] }}</span>
                    </div>
                </x-auth.buttons.social>
            @endforeach
        </div>
    </div>
@endif
```

The `@if` matters: without it a disabled feature still renders an empty grid
wrapper, and the `<x-auth.social-divider />` next to it in
`email-form.blade.php` and `accept.blade.php` would sit above nothing. Also
wrap those two `<x-auth.social-divider />` tags in the same check.

### 1.7 Rewrite the account manager

`resources/views/filament/tenant-admin/components/social-accounts-manager.blade.php`
— replace the hardcoded `$providers = [...]` block with:

```php
$providers = \App\Support\Features::enabled(\App\Features\Social\SocialLoginFeature::NAME)
    ? \App\Support\Social\ConfiguredProviders::all()
    : [];
```

Then in the loop body use `$meta['label']` and `:icon="$meta['icon']"`
(rename the loop variable from `$provider` to `$meta`, or keep `$provider` and
change the keys — the existing markup reads `$provider['name']` and
`$provider['icon']`, so `'name'` becomes `'label'`).

**Same empty-wrapper problem as 1.6, and it needs the same `@if`.** Wrap the
whole "Connect an account" block — its heading, its description and the loop —
in `@if ($providers !== [])`. Without it, a deployment with social login off
renders a section header over nothing. The `$connectedProviders` list below it
is a *separate* block and must stay outside that `@if`: a user who connected
through a provider that was later disabled still has to be able to see and
disconnect it.

Leave the
`$connectedProviders` query and the disconnect action untouched: a user
connected through a provider that is later disabled keeps their
`socialite_logins` row and their ability to disconnect it.

### 1.8 Test — `tests/Feature/Features/SocialLoginFeatureTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Features;

use App\Features\Social\SocialLoginFeature;
use App\Support\Features;
use Tests\TestCase;

class SocialLoginFeatureTest extends TestCase
{
    public function test_it_registers_oauth_routes_when_enabled(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('oauth'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('oauth.callback'));
    }
}
```

and a second class for the disabled case — **it must force features before
`parent::setUp()`**, because routes are registered while the application boots:

```php
class SocialLoginDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        Features::forceForTesting([]);

        parent::setUp();
    }

    public function test_it_registers_no_oauth_routes_when_disabled(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('oauth'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('oauth.callback'));
    }
}
```

`TestCase`'s `beforeApplicationDestroyed` closure (Phase 0.4) resets the
override, including when `parent::setUp()` itself throws. This two-class shape
is the template for every route-gating test below — reuse it verbatim.

**Both classes assert nothing if the route table is cached** — the gate ran at
cache time, not at boot. Before trusting either result, confirm
`bootstrap/cache/routes-v7.php` is absent. A green `SocialLoginDisabledTest` on
a cached run is meaningless.

**Done when**: both tests pass, plus the existing
`vendor/bin/sail artisan test --compact --filter=Socialite` stays green.

---

## Phase 2 — Module marketplace and the module system

Two switches, not one, and **both are Feature classes** — same
`config('numerosis.features')` array as everything else in this document, per
the decision at the top of the file. No `MODULES_ENABLED` /
`MODULE_MARKETPLACE_ENABLED` env vars, no booleans in `config/modules.php`:
one place to look when asking "what is switched off in this deployment".

Both classes have an empty `bootstrap()`, which is fine and worth saying out
loud so nobody "fixes" it: every check the module system needs —
`canAccess()`, `shouldRegisterNavigation()`, the panel's `->pages([...])`
array, the action guards — is evaluated at call time, not at boot. These
classes exist to *be present in a list*, and `NamedFeature` is what gives that
presence a name other code can read. Turnstile's `bootstrap()` does work; these
do not; both are legitimate shapes of the same pattern.

The one thing a flat class-string list cannot express is that the two switches
**nest** — a marketplace with the module system off is meaningless. That rule
lives in exactly one place, `ModuleSwitches`, and every call site goes through
it rather than calling `Features::enabled()` directly.

**Verified current shape**: the Marketplace page is explicitly listed in
`TenantAdminPanelProvider::panel()`'s `->pages([Dashboard::class, ModulesMarketplace::class, Billing::class])`;
resources are **auto-discovered**
(`->discoverResources(in: app_path('Filament/TenantAdmin/Resources'), for: 'App\\Filament\\TenantAdmin\\Resources')`),
so `ModuleResource` cannot be gated by omitting it from a list. Module Filament
plugins already build conditionally through `enabledModulePlugins()` reading
`config('modules.plugins')`.

### 2.0 The two feature classes

`app/Features/Modules/ModuleSystemFeature.php`:

```php
<?php

declare(strict_types=1);

namespace App\Features\Modules;

use App\Contracts\NamedFeature;

/**
 * The per-tenant module system itself: the Filament plugins registered by
 * TenantAdminPanelProvider::enabledModulePlugins() and the tenants:*-module
 * Artisan commands. Remove this class from config('numerosis.features') and a
 * deployment runs no modules at all.
 *
 * bootstrap() is deliberately empty — nothing about modules is registered at
 * boot. Every consumer asks ModuleSwitches at call time. Presence in the
 * features array is the whole toggle.
 *
 * Purchased `modules` rows and every module's tenant tables are untouched by
 * disabling this; the module system is switched off, not uninstalled.
 */
class ModuleSystemFeature implements NamedFeature
{
    public const NAME = 'modules';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register — see the class docblock.
    }
}
```

`app/Features/Modules/ModuleMarketplaceFeature.php`, identical in shape with
`public const NAME = 'modules.marketplace'` and a docblock recording that it
covers **only** the self-serve purchase UI on top of the module system: a
deployment can run modules provisioned by an admin with no marketplace, but a
marketplace with the module system off is meaningless and is treated as off
(enforced by `ModuleSwitches`, not by this class).

Register both in `config/numerosis.php`, after the social entries from 1.4:

```php
    // The per-tenant module system. Comment out to run no modules at all.
    ModuleSystemFeature::class,

    // Self-serve module purchasing on top of it. Inert without the line above.
    ModuleMarketplaceFeature::class,
```

### 2.1 The nesting rule — `app/Support/Modules/ModuleSwitches.php`

```php
<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Features\Modules\ModuleMarketplaceFeature;
use App\Features\Modules\ModuleSystemFeature;
use App\Support\Features;

/**
 * The only place that reads the two module features, so the rule that a
 * marketplace implies a module system exists once rather than at each of the
 * five call sites that need it.
 */
final class ModuleSwitches
{
    public static function modulesEnabled(): bool
    {
        return Features::enabled(ModuleSystemFeature::NAME);
    }

    public static function marketplaceEnabled(): bool
    {
        return self::modulesEnabled() && Features::enabled(ModuleMarketplaceFeature::NAME);
    }
}
```

**Do not** call `Features::enabled(ModuleMarketplaceFeature::NAME)` anywhere
else — that is the flat read that loses the nesting.

`config/modules.php` gains no keys in this phase. `modules.plugins` and the
catalogue stay exactly as they are: they describe *which* modules exist, which
is configuration, not a toggle.

### 2.2 Panel page

In `TenantAdminPanelProvider::panel()`:

```php
            ->pages([
                Dashboard::class,
                ...(ModuleSwitches::marketplaceEnabled() ? [ModulesMarketplace::class] : []),
                Billing::class,
            ])
```

### 2.3 Panel plugins

In `enabledModulePlugins()`, first line of the method:

```php
        if (! ModuleSwitches::modulesEnabled()) {
            return [];
        }
```

### 2.4 `ModuleResource`

In `app/Filament/TenantAdmin/Resources/Modules/…` (the resource class):

```php
    public static function canAccess(): bool
    {
        return ModuleSwitches::marketplaceEnabled() && parent::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ModuleSwitches::marketplaceEnabled() && parent::shouldRegisterNavigation();
    }
```

**Do not** convert the panel to explicit `->resources([...])` registration to
gate this one class — that would mean hand-listing every other resource and
losing discovery for all of them.

### 2.5 Action guards

`.claude/rules/module-marketplace.md` records that `PurchaseModule` and
`CancelModule` must both assert the ambient tenant equals the argument tenant,
and that `CancelModule` was missing that guard once already. That much is
genuinely shared and should be extracted. **The marketplace switch is not** —
the two actions must gate on different switches, for a reason worth stating
plainly:

> **Disabling the marketplace must never block cancellation.** If
> `CancelModule` refused to run while `marketplace_enabled` is false, a
> deployment that turns the marketplace off would leave every existing
> subscriber unable to stop being billed — the Stripe subscription items stay
> on the subscription and keep charging, with no UI and no action able to
> remove them. Turning off a storefront must not trap customers inside a
> purchase. `PurchaseModule` gates on `marketplaceEnabled()`; `CancelModule`
> gates on `modulesEnabled()` only.

So: one shared trait for the tenant-equality check, and one *separate*
marketplace assertion called from `PurchaseModule` alone.

`app/Actions/Modules/Concerns/GuardsModuleBilling.php`:

```php
trait GuardsModuleBilling
{
    /**
     * Programmer error, not a domain refusal — this must stay a
     * LogicException so it escapes to the handler with full context rather
     * than being caught by a UI `catch (ShowsMessageToUser $e)` block
     * (.claude/rules/exception-handling.md).
     */
    protected function assertRunningInsideTenant(Tenant $tenant): void
    {
        if (tenant()?->getTenantKey() !== $tenant->getTenantKey()) {
            throw new LogicException(/* keep PurchaseModule's existing message verbatim */);
        }
    }

    /**
     * A domain refusal with customer-facing copy — PurchaseModule only.
     * CancelModule deliberately does not call this; see the plan.
     */
    protected function assertMarketplaceAvailable(): void
    {
        if (! ModuleSwitches::marketplaceEnabled()) {
            throw new MarketplaceDisabled;
        }
    }
}
```

Keeping the two exception kinds behind two separately-named methods is the
point: fusing them into one `assertModuleBillingAllowed()` means a call site
writing `catch (ShowsMessageToUser $e)` silently swallows one branch and not
the other, and neither reader nor PHPStan can see which.

`app/Exceptions/Modules/MarketplaceDisabled.php` extends
`App\Exceptions\DomainException` (which implements `ShowsMessageToUser`) with
customer-facing copy: *"The module marketplace is not available on this
workspace."*

Wiring:

- `PurchaseModule::handle()` — `assertMarketplaceAvailable()` then
  `assertRunningInsideTenant($tenant)`, replacing its existing inline
  tenant-equality check.
- `CancelModule::handle()` — `assertRunningInsideTenant($tenant)` only. If
  `ModuleSwitches::modulesEnabled()` is false the module system is not running at all and
  there is nothing to cancel; no guard is needed for that case, and adding one
  would recreate the trap above one level up.

Keep the existing tests
`PurchaseModuleTest::test_it_refuses_to_run_outside_the_tenant_it_is_purchasing_for`
and its `CancelModuleTest` mirror passing unchanged — if the message or
exception type they assert changes, the extraction is wrong.

### 2.6 Tests

**`config([...])` does not work for these — use `Features::forceForTesting()`.**
`Features::enabled()` reads the config array through a memoised name map
(Phase 0.2), and only `forceForTesting()` clears that memo. A test that writes
`config(['numerosis.features' => [...]])` directly changes the array the map was
already built from and sees no effect — a silently-passing test, which is the
worst outcome available here.

- Marketplace off, module system on:

  ```php
  Features::forceForTesting([ModuleSystemFeature::class]);
  ```

  then assert `ModuleResource::canAccess()` is false and
  `PurchaseModule::handle()` throws `MarketplaceDisabled`. Both read
  `ModuleSwitches` at call time, so no re-boot is needed and the plain
  single-class test shape is enough.
- **The counterpart test, which is the one that matters**: under that same
  forced list, `CancelModule::handle()` still succeeds. Write it first and
  confirm it fails against a `CancelModule` that calls
  `assertMarketplaceAvailable()` — that is the billing trap above, and a test
  written after the fix proves nothing.
- Nesting rule: `Features::forceForTesting([ModuleMarketplaceFeature::class])`
  (marketplace listed, module system not) must leave
  `ModuleSwitches::marketplaceEnabled()` **false**. This is the assertion that
  guards the one thing the flat class-string list cannot express by itself.
- Panel-page absence is a **boot-time** assertion — `->pages([...])` is
  evaluated while the panel provider boots — so it needs the two-class shape
  from 1.8: `Features::forceForTesting([ModuleSystemFeature::class])` in
  `setUp()` **before** `parent::setUp()`, then assert on
  `Filament::getPanel('tenantAdmin')->getPages()`. If that proves fiddly,
  assert the resource-level guard only and note the page case as untested
  rather than writing a test that passes vacuously.
- Panel tests must enter the panel through
  `Tests\TestCase::actingAsTenantPanelUser($tenant, $user)` — otherwise the
  missing `{tenant}` route parameter throws and reads like a feature-toggle
  failure when it is not (`.claude/rules/filament-tenancy.md`).

---

## Phase 3 — Invitations

**Verified current shape**, all unconditional: `routes/tenant.php`'s
`Route::get('invitation/{token}', Accept::class)->name('invitation.show')->middleware('invitation.status')`;
the `'invitation.status' => App\Http\Middleware\CheckInvitationStatus::class`
alias in `bootstrap/app.php`; `App\Livewire\Invitations\Accept`;
`App\Contracts\Invitations\CreatesInvitedUser` bound to `CreateInvitedUser` in
`AppServiceProvider::register()`; `App\Events\Invitations\InvitationIssued`;
`App\Listeners\Invitations\SendInvitationNotification`;
`App\Notifications\InvitationSent`; `app/Filament/TenantAdmin/Resources/Invitations`.

**Design**: one `App\Features\Invitations\InvitationsFeature` (`NAME = 'invitations'`),
shape D.

- `bootstrap()` — nothing to do; the listener is auto-discovered by Laravel's
  event discovery **or** registered explicitly. Check which: if
  `SendInvitationNotification` is discovered, gate it inside its own `handle()`
  with an early return (`if (! Features::enabled(InvitationsFeature::NAME)) { return; }`)
  rather than fighting discovery. This is the named exception to "a disabled
  feature must load nothing" declared in Ground rules — the listener stays
  registered and does nothing. Say so in its docblock so the next reader does
  not file it as a bug.
- `routes/tenant.php` — wrap the `invitation.show` route in
  `if (Features::enabled(InvitationsFeature::NAME))`.
- The `invitation.status` middleware alias stays registered in
  `bootstrap/app.php` — it is inert with no route using it, and gating it there
  would need config at pre-boot time (see flaw 3).
- Invitations Filament resource: `canAccess()` + `shouldRegisterNavigation()`
  as in 2.4.
- The `CreatesInvitedUser` binding stays unconditional — binding an unused
  contract costs nothing and keeps `register()` free of feature logic.
- `invitations` tenant table stays. Document in the feature class docblock.

**Test**: two-class shape from 1.8 asserting `Route::has('invitation.show')`
flips; plus a `canAccess()` assertion for the resource.

---

## Phase 4 — Registration wizard

**Verified current shape**: `AppServiceProvider::boot()` registers four
Livewire components by absolute `resource_path()` view path —
`tenant-registration`, `plan`, `technical-setup`, `company-info` — and
`routes/web.php` has `Route::livewire('/get-started', Tenant\Registration\Registration::class)->name('tenants.create')`.

**Design**: `App\Features\Tenancy\RegistrationWizardFeature` (`NAME = 'registration_wizard'`),
shape A **and** D — the `Livewire::addComponent()` calls are genuine boot-time
registrations, so they move into `bootstrap()`; the route is gated in the route
file.

```php
class RegistrationWizardFeature implements NamedFeature
{
    public const NAME = 'registration_wizard';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        Livewire::addComponent(
            name: 'tenant-registration',
            viewPath: resource_path('views/livewire/tenant/registration/wizard/index.blade.php'),
            class: Registration::class,
        );
        // …the other three, moved verbatim from AppServiceProvider::boot()…
    }
}
```

Cut all four `Livewire::addComponent(...)` calls out of
`AppServiceProvider::boot()` and their `App\Livewire\Tenant as Tenants` import.

**Note for the package split, do not act on it here**: `resource_path()` does
not exist for a package consumer — `.claude/plans/package-extraction.md` Phase
1.5 converts these to namespaced package views. Moving them into this class
first is deliberate: it puts all four in one place for that later rewrite.

**What must keep working with the wizard off**: tenant provisioning itself.
Everything funnels through `ProvisionsTenant::queue()`
(`.claude/rules/tenant-provisioning.md`), which the wizard only *calls* — so a
consumer creating tenants from an admin screen or a job is unaffected. Say so
in the class docblock.

**Test**: `Route::has('tenants.create')` flips; and with the feature off,
`Livewire::test('tenant-registration')` throws "Unable to find component".

---

## Phase 5 — Email verification and billing notifications (two shape-A features)

### 5.1 Email verification — ⛔ BLOCKED, do not execute as a route gate

The previous revision made this conditional on a grep. **The grep has been
run, and it comes back positive**, so the condition is already resolved:

```
app/Models/User.php:56
abstract class User extends Authenticatable implements FilamentUser, HasTenants, MustVerifyEmail, Syncable
```

The shared abstract base implements `MustVerifyEmail`, so **both** `CentralUser`
and `Tenant\User` are verification-requiring models, unconditionally. Gating
`verification.verify` (`routes/auth.php:31`) and `verification.notice`
(`routes/tenant.php:29`) away while that interface stays on the model leaves
Laravel with a model that demands verification and no route to perform it:
`VerifyEmail::createUrlUsing`'s `URL::temporarySignedRoute` throws
`RouteNotFoundException`, and any `verified`-middleware path redirects to a
route name that no longer resolves. That locks unverified users out with a 500
rather than a prompt.

(`'verified'` middleware is currently used on no route — checked — so the
immediate blast radius is the notification URL, not the panels. That is a
reason it fails loudly rather than a reason it is safe.)

**Do not execute this phase as written.** Doing it properly means deciding
what a verification-free deployment *is*, which is a product decision, not a
gate:

- Drop `MustVerifyEmail` from `App\Models\User` and reintroduce it per-model
  behind the feature — but the interface cannot be conditionally implemented,
  so this means an `isEmailVerificationRequired()` indirection through every
  caller (`SendEmailVerificationNotification`, `ResendVerificationNotification`,
  `SendsEmailVerificationNotification`'s binding, Filament's own
  `requiresEmailVerification()`), or
- keep the routes and the interface unconditional, and let the feature gate
  only the *sending* of the notification — a much smaller, safe change that
  does not remove a surface, or
- leave email verification out of the feature system entirely.

Pick one with the user before writing code. What **is** safe to do now, and is
the only part of 5.1 that should ship without that decision: move
`VerifyEmail::createUrlUsing(...)` out of `AppServiceProvider::boot()` into a
`EmailVerificationFeature::bootstrap()` verbatim (body and imports unchanged)
and list the class in `config('numerosis.features')` **unconditionally**, so
the registration lives with its siblings. Gate no routes.

### 5.2 `App\Features\Billing\BillingNotificationsFeature` (`NAME = 'billing_notifications'`)

Covers `App\Listeners\Billing\SendPaymentConfirmedNotification`,
`SendPaymentFailedNotification`, `SendTenantSuspendedNotification`. Three
independent registrations, one switch — the exact case the Feature-class
pattern is for.

Check how they are wired first (auto-discovery vs. explicit `Event::listen`).
If auto-discovered, the honest gate is an early return at the top of each
`handle()`:

```php
        if (! Features::enabled(BillingNotificationsFeature::NAME)) {
            return;
        }
```

and `bootstrap()` stays empty with a docblock saying why. Do not add a
`shouldQueue`/`shouldHandle` abstraction for three listeners. Same named
exception to "a disabled feature must load nothing" as Phase 3 — note it in
each listener's docblock.

---

## Phase 6 — Password reset

**Verified**: `routes/auth.php` wires `forgot-password` → `App\Livewire\Auth\ForgotPassword`
and `reset-password/{token}` → `ResetPassword`; `routes/web.php` wires
`settings/password` → `App\Livewire\Settings\Password`.

Login here is passwordless (OTP) — so password reset is a *secondary* surface,
and a deployment that only ever issues one-time codes wants it gone.

`App\Features\Auth\PasswordResetFeature` (`NAME = 'password_reset'`), shape D:
gate the two `routes/auth.php` routes and the `settings/password` route.
Leave `settings/profile` and `settings/appearance` alone (Phase 8 owns those).

**Do not** also gate `password.confirm` in `routes/tenant.php` in this phase —
password confirmation is used by Filament's own sensitive-action flow and needs
its own investigation.

---

## Phase 7 — Observability

Three independent, small, and each currently a package-blocker.

### 7.1 The scheduled prune that assumes Telescope exists

`routes/console.php` has `Schedule::command('telescope:prune')->daily();`
unconditionally. For any deployment without Telescope this is a crash at
schedule-run time — same shape as the `failed_jobs` incident in
`.claude/rules/exception-handling.md` (a support table gated by the wrong
thing). Fix:

```php
if (class_exists(\Laravel\Telescope\Telescope::class)) {
    Schedule::command('telescope:prune')->daily();
}
```

Also move `App\Providers\TelescopeServiceProvider` registration behind the same
`class_exists` check — it is currently unconditional in
`bootstrap/providers.php`. **`bootstrap/providers.php` is a plain array
returned before config loads**, so use `class_exists`, never `config()`.

**Know what this gate does and does not achieve here.** `laravel/telescope` is
in composer.json's `require`, not `require-dev` (verified: `"laravel/telescope":
"^5.16"` at line 30 of the `require` block). So `class_exists` is *always* true
in this repo, both gates are inert, and neither can be tested from the suite.
They are forward-compat scaffolding for a package consumer who does not install
Telescope, and nothing more.

Making them real means moving `laravel/telescope` to `require-dev` (plus a
`suggest` entry), which is a **composer dependency change and therefore needs
the user's approval** — Ground rules forbid doing it unasked, and it also
changes what a production deploy of *this* app ships with. Do the two
`class_exists` guards now, and raise the dependency move as a separate question
rather than silently doing it or silently claiming the gate works.

### 7.2 Schedule switches — booleans, not a frequency map

The previous revision proposed a `command => frequency` config map driven by
`Schedule::command($command)->{$frequency}()`. **Do not build that.** It is
worse than the three literal lines it replaces:

- `->{$frequency}()` is a dynamic method call on `PendingEventInvocation` —
  PHPStan level 9 cannot check it and will flag it, and a typo'd frequency
  becomes a runtime `BadMethodCallException` inside the scheduler.
- It only expresses zero-argument frequencies. `dailyAt('03:00')`,
  `everyFifteenMinutes()`, `->onOneServer()`, `->withoutOverlapping()`,
  `->timezone(...)` — all unreachable, so the first scheduling change forces
  the map to be unwound again.
- `$frequency === false` is unreachable given the array's own declared shape.

Gate each line with its own boolean instead. `routes/console.php` in full,
after this phase and 7.1 together:

```php
// routes/console.php
if (class_exists(\Laravel\Telescope\Telescope::class)) {
    Schedule::command('telescope:prune')->daily();
}

if (Config::boolean('numerosis.schedule.prune_orphaned_customers')) {
    Schedule::command('billing:prune-orphaned-customers')->daily();
}

if (Config::boolean('numerosis.schedule.prune_stalled_provisions')) {
    Schedule::command('tenancy:prune-stalled-provisions')->hourly();
}
```

```php
// config/numerosis.php
'schedule' => [
    'prune_orphaned_customers' => (bool) env('SCHEDULE_PRUNE_ORPHANED_CUSTOMERS', true),
    'prune_stalled_provisions' => (bool) env('SCHEDULE_PRUNE_STALLED_PROVISIONS', true),
],
```

Note `telescope:prune` is gated by 7.1's `class_exists`, **not** by a
`numerosis.schedule` key — one line, one owner. Do not also add it to the
config array; the two phases touch the same file and duplicating it there is
how one of them ends up deleted.

Keep `tenancy:prune-stalled-provisions`'s behaviour untouched — it deletes
`reserved` rows but only *logs* stale `provisioning` ones, and that asymmetry is
deliberate (`.claude/rules/tenant-provisioning.md`).

### 7.3 Activity log plugin

`AlizHarb\ActivityLog\ActivityLogPlugin::make()->navigationGroup('People')->cluster(TeamCluster::class)`
is hardcoded into the tenant panel's `->plugins([...])`, along with
`app/Filament/TenantAdmin/Resources/Activities`. A third-party audit-log
package forced on every consumer.

A `NamedFeature`, same as Phase 2's two — not a boolean. Same argument: the
plugin is a panel registration read at boot, the resource guard is read at call
time, and both want one toggle that lives where every other toggle lives.

`app/Features/Observability/ActivityLogFeature.php`:

```php
<?php

declare(strict_types=1);

namespace App\Features\Observability;

use App\Contracts\NamedFeature;

/**
 * The audit-log surface: AlizHarb\ActivityLog\ActivityLogPlugin in the tenant
 * panel and App\Filament\TenantAdmin\Resources\Activities on top of it.
 * Removing this class from config('numerosis.features') stops the panel
 * registering the plugin and hides the resource — a third-party audit-log
 * package is not something every consumer wants forced on them.
 *
 * bootstrap() is empty for the same reason ModuleSystemFeature's is: the panel
 * provider asks at boot, the resource asks at call time, and neither needs a
 * registration performed from here. Presence in the array is the toggle.
 *
 * Disabling does not stop spatie/laravel-activitylog from *writing* — models
 * composing LogsActivity keep recording, and existing `activity_log` rows are
 * untouched. This removes the UI, not the audit trail. Stopping the writes is
 * a separate decision, and a destructive one for compliance-shaped
 * deployments.
 */
class ActivityLogFeature implements NamedFeature
{
    public const NAME = 'activity_log';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register — see the class docblock.
    }
}
```

Register it in `config/numerosis.php` alongside the others, then gate the
plugin in the same conditional array the modules already build:

```php
            ->plugins([
                ...(Features::enabled(ActivityLogFeature::NAME) ? [
                    ActivityLogPlugin::make()
                        ->navigationGroup('People')
                        ->cluster(TeamCluster::class),
                ] : []),
                ...$this->enabledModulePlugins(),
            ])
```

and add `canAccess()`/`shouldRegisterNavigation()` to the Activities resource
per 2.4, both reading `Features::enabled(ActivityLogFeature::NAME)`.

**No `ModuleSwitches` equivalent here** — this switch nests under nothing, so
there is no rule to centralise and call sites read `Features::enabled()`
directly. Adding a one-line wrapper class for a single flat check would be
indirection for its own sake.

No `ACTIVITY_LOG_ENABLED` env var, and no `numerosis.activity_log` key.

**Test**: the resource guard needs only
`Features::forceForTesting([])` in the test body (call-time read). The plugin's
absence from the panel is a boot-time assertion — two-class shape from 1.8,
forcing before `parent::setUp()`, asserting on
`Filament::getPanel('tenantAdmin')->getPlugins()`, and entering the panel via
`actingAsTenantPanelUser()` per `.claude/rules/filament-tenancy.md`.

### 7.4 Litter

Delete `config/debugbar.php.bak`. It is not a feature; it is a stray backup
file that ships to every consumer.

---

## Phase 8 — First-party pages the consumer will replace

**Verified**: `routes/web.php` registers `/` (welcome view), `terms`, `privacy`,
`about`, `features`, the `settings` redirect, `settings/profile`,
`settings/appearance`, `/tenants/mine`, `/billing-portal`, and
`/user/invoice/{invoice}`.

None of this is framework — it is this product's marketing and account UI, and
must not load by default in a package.

Two features:

- `App\Features\Ui\MarketingPagesFeature` (`NAME = 'ui.marketing'`) — `/`,
  `terms`, `privacy`, `about`, `features`.
- `App\Features\Ui\AccountPagesFeature` (`NAME = 'ui.account'`) — the settings
  group, `/tenants/mine`, `/billing-portal`, `/user/invoice/{invoice}`.

**The trap**: `route('home')` and `route('tenants.mine')` are called from ~10
places, including `App\Http\Controllers\Socialite\Login::tenantDashboardUrl()`,
which specifically depends on `home` being central-only and always registered
(read its docblock before touching anything). Gating those routes without
indirecting their *names* turns every one of those calls into a
`RouteNotFoundException`.

So this phase is **two steps, in order**:

1. Move the route names into config (`numerosis.routes.names.home`,
   `…tenants_mine`, etc.) and change every `route('home')` /
   `route('tenants.mine')` call site to read the configured name — this is
   `.claude/plans/package-extraction.md` Phase 1.3, and it is a no-op change on
   its own, verifiable by the existing `tests/Feature/StaticPagesTest.php`.
2. Only then gate the route registrations.

If step 1 is not done, **do not do step 2**.

---

## Phase 9 — Delete the dead password-login surface

`app/Livewire/Auth/Login.php` (extends `Filament\Auth\Pages\Login`, composes
`ThrottlesLoginAttempts`) and `resources/views/livewire/auth/login.blade.php`
are reachable from nothing: `routes/auth.php` maps `login` to
`PasswordlessLogin`, and `AdminPanelProvider` calls Filament's own `->login()`
with no argument. Confirmed by grep — the only references are two docblock
mentions in `app/Concerns/Auth/ThrottlesLoginAttempts.php`.

Dead auth code is worse than unused code: `.claude/rules/auth-login.md` records
two separate incidents where protections drifted between parallel login
surfaces, one of them an account takeover. A third copy sitting unrouted is the
next one waiting to happen.

Steps: delete both files; update the two docblock references in
`ThrottlesLoginAttempts` (keep the `hitLoginThrottle()`/`clearLoginThrottle()`
naming note — it explains a real Filament collision and is still true for
`PasswordlessLogin`); **update `.claude/rules/auth-login.md`**, whose
naming-collision bullet is written in terms of "`Login` extends
`Filament\Auth\Pages\Login`, which composes `WithRateLimiting`" — that class
will no longer exist, so restate the rule as the general one it actually is
(any concern composed into a Filament page must be grepped against the parent
chain first) rather than leaving a rule that points at a deleted file; run
`vendor/bin/sail exec -T laravel.test bash -lc "vendor/bin/phpstan analyse"` to
catch anything the grep missed.

**STOP and ask first** if the grep finds any reference outside those two
docblocks — a login surface that turns out to be live must not be deleted on
this plan's say-so.

---

## Phase 10 — Chat (STOP: confirm the approach with the user before executing)

**Verified current shape**: chat lives in `app-modules/chat` (`nvade/chat`,
`Nvade\Chat`). `Nvade\Chat\Providers\ChatServiceProvider` registers three
Livewire components. `Nvade\Chat\ChatPlugin` **is registered nowhere** — the
tenant panel's `->plugins([...])` holds only `ActivityLogPlugin` and
`...$this->enabledModulePlugins()`, and `config('modules.plugins')` lists
tasks, notes, announcements, branding. So chat's UI is currently unreachable
while its migrations and seeder still run.

### 10.1 Chat is not wired anywhere — decide, then wire

`.claude/plans/chat-to-module.md` decided "free/core, plugin stays
unconditionally registered", but the tree registers it nowhere, so that
decision was never implemented. Two options; the second is better for a
package and is the recommendation:

- Billed module: add `'chat' => Nvade\Chat\ChatPlugin::class` to
  `config('modules.plugins')` and a `catalogue` entry.
- Free/core, self-registering: `ChatServiceProvider::boot()` pushes the plugin
  onto the tenant panel itself, so core holds no reference to the module. This
  is the same inversion `Nvade\Branding\BrandingPlugin` already uses for its
  middleware (see `App\Http\Middleware\ApplyDefaultBranding`'s docblock).

### 10.2 The real problem: core depends on the module

`app/Models/Tenant/User.php` imports `Nvade\Chat\Concerns\HasChatCapabilities`
and `Nvade\Chat\Enums\DisplayStatus`, composes the trait, casts
`display_status` to the module's enum, and lists `display_status` /
`custom_status_text` in its `#[Fillable]` attribute. **Removing
`app-modules/chat` from a deployment is a fatal error, not a disabled
feature.** This outranks every toggle in this document.

Recommended mechanism (needs user sign-off — it changes how a core model
resolves relations):

1. **Move the status vocabulary into core.** `DisplayStatus` describes a user's
   presence, and presence is already core (`last_seen_at`,
   `UpdateUserLastSeenMiddleware`, the `online` broadcast channel). Create
   `App\Enums\Tenant\DisplayStatus` with the identical cases, have
   `Nvade\Chat\Enums\DisplayStatus` become a thin alias (`class_alias` in the
   module's provider, or update the module's own references). `Tenant\User`
   then casts to the core enum and imports nothing from `Nvade\*`.
2. **Move the relations out of the model.** Drop `use HasChatCapabilities;`
   from `Tenant\User` and have `ChatServiceProvider::boot()` attach them:

   ```php
   User::resolveRelationUsing('channels', fn (User $user) => $user
       ->belongsToMany(Channel::class, 'channel_members')
       ->withPivot(['role', 'last_read_at', 'joined_at'])
       ->withTimestamps()
       ->using(ChannelMember::class));

   User::resolveRelationUsing('channelMemberships', fn (User $user) => $user
       ->hasMany(ChannelMember::class));
   ```

   `isOnlineInChat()` has no relation equivalent — move its call sites to a
   module-owned helper (`Nvade\Chat\Support\Presence::isOnline($user)`) rather
   than trying to graft a method onto the model.
3. **Keep the `#[Fillable]` entries** — they name columns, not module classes.
   Verified: `display_status` and `custom_status_text` are added by
   `database/migrations/tenant/2026_05_01_000002_add_chat_status_to_users_table.php`,
   which is **core**, on the path `config('tenancy.migration_parameters')`
   migrates. Removing `app-modules/chat` leaves the columns in place.

   **While verifying that, a live duplicate surfaced — deal with it in this
   phase.** The identical file also exists at
   `app-modules/chat/database/migrations/tenant/2026_05_01_000002_add_chat_status_to_users_table.php`
   — byte-for-byte identical (`diff` reports no differences), same filename,
   same timestamp. Two migration paths, one schema change. Today it is inert
   only because module migrations run through `tenants:migrate-module` and the
   core tenant path runs through stancl's own migrator, and no deployment has
   run both against the same database; whichever ran second would fail with
   `Duplicate column name 'display_status'`. Since this phase's whole purpose
   is deciding which side of the core/module boundary chat state lives on:
   keep the **core** copy (the columns are presence, and presence is core per
   step 1) and delete the module copy. If instead the user decides chat status
   is module-owned, delete the core copy and move the columns behind
   `tenants:migrate-module` — but do not leave both in the tree, which is the
   current state.

Trade-off to state when asking: `resolveRelationUsing` relations are invisible
to static analysis and to IDE completion, so PHPStan will not know
`$user->channels` exists inside the module. That is the price of removing a
hard core→module import. If the package split (`.claude/plans/package-extraction.md`
Phase 3.4, concrete models published into the consumer app) lands first, this
problem dissolves and none of step 2 is needed.

### 10.3 Guard the boundary with a test

Once 10.2 is done, add to `tests/Feature/ArchTest.php`:

```php
arch('core does not depend on app-modules')
    ->expect('App')
    ->not->toUse('Nvade');
```

Verified this will pass once `Tenant\User` is cleaned: the only `Nvade` imports
under `app/` are `app/Models/Tenant/User.php:21-22`. The four other `Nvade`
hits (`TenantAdminPanelProvider`, `Support\Cache\CacheKeys`,
`Contracts\Tenancy\ModulePlugin`, `Http\Middleware\ApplyDefaultBranding`) are
all inside docblocks, which `toUse()` does not count.

Write it **before** the fix, confirm it fails on `app/Models/Tenant/User.php`,
then fix. A test that only ever ran green against fixed code proves nothing —
same discipline `.claude/rules/auth-login.md` records for the passwordless-login
regression tests.

---

## Deferred — with the reason, so they are not re-proposed blind

### D1. Filament panels as plugins

Both panel providers are unconditional in `bootstrap/providers.php`. Converting
them to `Filament\Contracts\Plugin` implementations the consumer registers is
`.claude/plans/package-extraction.md` Phase 5. **Do not** add a
`panel_enabled` boolean in the meantime — it keeps the provider, keeps the
route registration risk described in `TenantAdminPanelProvider::register()`'s
docblock (the `{tenant}` pattern matching the central subdomain), and is
strictly worse than the plugin conversion it would have to be undone for.

### D2. Roles and permissions

Optional in principle; dangerous to toggle. Spatie throws
`PermissionDoesNotExist` for an unseeded permission — a **500, not a 403**
(`.claude/rules/auth-guards.md`). Disabling would have to neutralise every
`hasPermissionTo()` path, not merely skip seeding, and a half-disabled state is
worse than either end. Needs its own plan with a smoke test asserting that an
unseeded database returns false rather than throwing.

### D3. Realtime / presence

The intended gate — wrapping `->withBroadcasting()` in `bootstrap/app.php` —
**does not work**: that file runs before config and `.env` load, so neither
`config()` nor `env()` is reliable there. What *is* achievable today:

- gate the channel definitions inside `routes/channels.php` with
  `Features::enabled(...)` (that file is `require`d from an
  `$this->app->booted()` callback, so config is available);
- gate `UpdateUserLastSeenMiddleware` in the tenant panel's middleware array.

`Broadcast::routes()` still registers `/broadcasting/auth`, which is harmless
with no channels defined but means the feature is not fully removed. Full
removal belongs to the package split, where a feature provider owns the
registration. Do not claim "realtime off" until then.

### D4. VAT / billing-address collection

`AddVatNumber` (from `app/Filament/TenantAdmin/Pages/Billing.php`) and
`SyncBillingAddress` (from `Livewire\Billing\Checkout`,
`CompleteRedirectCheckout`, `WebhookController`) are worth a
`billing.collect_vat` flag, but the flag must gate **collection only**. Stripe
is the single source of truth for billing address — there is no local column
and none may be added (`.claude/rules/billing-checkout.md`). Needs its own pass
over the three call sites and the checkout form.

### D5. Suspension enforcement and the payment banner

`EnsureTenantSubscriptionActive` (tenant panel `authMiddleware`) plus the
`tenant.suspended` route, and the `PanelsRenderHook::CONTENT_START` payment
banner. Both are plausible booleans, but suspension interacts with
`SuspendTenant`/`RestoreTenant`, the `suspended_at` column, and
`WebhookController::handleCustomerSubscriptionDeleted`'s "is anything still
valid?" rule. Toggling the middleware without understanding that chain risks
handing access to unpaid tenants, which is the opposite of a safe default.
Separate plan.

---

## Suggested execution order

Phase 0 → 1 → 2 → 3 → 4 → 5 (**5.2 only**; 5.1 is blocked pending a product
decision — see the phase) → 6 → 7 → 8 (step 1 only unless step 2's
prerequisite is done) → 9 → 10 (after user sign-off).

Phases 1-9 are each independently shippable and independently revertible.
Phase 10 is the one with real architectural consequence, and the one that
actually decides whether this codebase can be installed as a package at all.

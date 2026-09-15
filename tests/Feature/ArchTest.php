<?php

declare(strict_types=1);

use Nvade\Numerosis\Boot\MiddlewareRegistrar;
use Nvade\Numerosis\Contracts\Subscribable;
use Nvade\Numerosis\Contracts\Tenancy\HasTenants;
use Nvade\Numerosis\Contracts\Tenancy\PersistsToProvisionColumns;
use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Policies\Concerns\ChecksContextPermissions;
use Nvade\Numerosis\Services\Billing\BillingService;
use Nvade\Numerosis\Services\Tenancy\AuthGuardBootstrapper;
use Nvade\Numerosis\Services\Tenancy\PasswordBrokerBootstrapper;
use Nvade\Numerosis\Services\Tenancy\PreservingPathTenantResolver;
use Nvade\Numerosis\Services\Tenancy\SpatiePermissionsBootstrapper;
use Symfony\Component\Finder\Finder;

/**
 * Keeps a contract typehinting this app's Eloquent models rather than the
 * container it belongs in honest: that nails every implementer to this
 * package's classes, which is exactly what a consumer installing this as a
 * package would have to fork around. Scoped to all of Nvade\Numerosis\Contracts,
 * with a named-exception list rather than a path scope — a path scope is how
 * ProvisioningStep, PersistsToProvisionColumns, TenantDatabaseManager and
 * NotifiesTenantOwner drifted outside the old two-contract scope unnoticed.
 *
 * Named exceptions: Subscribable and HasTenants predate the
 * billing-provisioning-clarity refactor and reference Nvade\Numerosis\Models\*
 * deliberately (see .claude/plans/archive/billing-provisioning-clarity-refactor.md
 * §2.1). ProvisioningStep and PersistsToProvisionColumns take
 * Models\Central\TenantProvision because a step's whole point is to receive
 * the provision row; narrowing that away would need the wider
 * ProvisioningContext DTO change recorded, and rejected for now, in phase 5
 * of the contract-seam-audit plan. UserResolver was removed entirely
 * (2026-07-31) — one implementation, nothing ever resolved it through the
 * interface, everyone called GetAuthenticatedUser::run() directly.
 */
arch('contracts do not depend on app models, except a named exception list')
    ->expect('Nvade\Numerosis\Contracts')
    ->not->toUse('Nvade\Numerosis\Models')
    ->ignoring([
        Subscribable::class,
        HasTenants::class,
        ProvisioningStep::class,
        PersistsToProvisionColumns::class,
    ]);

test('nothing reads the old cashier appendix keys', function (): void {
    $needles = ['cashier.billables', 'cashier.stripe_price_ids', 'cashier.redirect', 'cashier.features', 'cashier.brand'];

    // Not base_path(): under Testbench that is the skeleton app, whose
    // app/ and database/ hold zero PHP files — the loop below never ran an
    // assertion and PHPUnit reported the test risky rather than failing it,
    // so this has proved nothing since the code moved into src/.
    $roots = array_map(
        fn (string $directory): string => dirname(__DIR__, 2)."/{$directory}",
        ['src', 'config', 'database'],
    );

    $files = (new Finder)
        ->files()
        ->in($roots)
        ->name('*.php');

    expect(iterator_count($files))->toBeGreaterThan(0, 'Scanned no files — the paths above are wrong.');

    foreach ($files as $file) {
        $contents = $file->getContents();

        foreach ($needles as $needle) {
            expect($contents)->not->toContain($needle, "{$file->getRelativePathname()} still references {$needle}");
        }
    }
});

/**
 * One way to ask "who is signed in", not two.
 *
 * Both idioms were live until 2026-09-01: 16 call sites went through
 * `GetAuthenticatedUser::run()` and 9 called `Auth::user()` / `auth()->user()`
 * directly, so the answer to "how do I read the current user here?" depended
 * on which file you had open. The action wins because it is typed: it returns
 * `?Nvade\Numerosis\Models\User`, the package's own base model, where the
 * facade returns `Authenticatable` — which matters in an app with two user
 * models on two guards, and is what lets level-9 analysis see the real type
 * without a `@var` annotation at every call site.
 *
 * `IsUserAuthenticated` was deleted rather than kept: it wrapped
 * `Auth::check()` with no added type information, so it was pure vocabulary.
 * Call `->check()` on the guard directly.
 *
 * `Auth::` is still fine for everything that is not "read the current user" —
 * `Auth::guard(...)->check()`, `::login()`, `::logout()`, `::shouldUse()` —
 * which is why this scans for the two accessor spellings, not the facade.
 */
test('nothing reads the current user through the Auth facade', function (): void {
    $roots = array_map(
        fn (string $directory): string => dirname(__DIR__, 2)."/{$directory}",
        ['src', 'packages/ui/src'],
    );

    $files = (new Finder)
        ->files()
        ->in(array_filter($roots, is_dir(...)))
        ->name('*.php');

    expect(iterator_count($files))->toBeGreaterThan(0, 'Scanned no files — the paths above are wrong.');

    foreach ($files as $file) {
        // Numerosis::exceptions() is the one exemption: it builds Sentry's
        // context callback, which runs during exception reporting, where the
        // container may be mid-teardown and resolving an action is not safe.
        if ($file->getFilename() === 'Numerosis.php') {
            continue;
        }

        $contents = $file->getContents();

        foreach (['Auth::user()', 'auth()->user()'] as $needle) {
            expect($contents)->not->toContain(
                $needle,
                "{$file->getRelativePathname()} reads the current user through {$needle} — use GetAuthenticatedUser::run() instead.",
            );
        }
    }
});

/**
 * `src/Support/` was deleted on 2026-09-11 because "support" named nothing:
 * it was whatever no other folder had claimed, and it accumulated the
 * package's highest-traffic classes. Recreating it starts that over.
 */
test('no class lives in a folder named after the absence of a category', function (): void {
    $files = (new Finder)
        ->files()
        ->in(dirname(__DIR__, 2).'/src')
        ->name('*.php');

    expect(iterator_count($files))->toBeGreaterThan(0, 'Scanned no files — the path above is wrong.');

    foreach ($files as $file) {
        expect($file->getRelativePath())->not->toStartWith('Support');
    }
});

/**
 * `Numerosis::middleware()` runs from a host's `bootstrap/app.php`, before
 * `RegisterFacades`. Anything in these two methods that reads `Config` or a
 * facade fatals on a real boot — which is not reproducible under Testbench,
 * where the facade root happens to be set. `NumerosisSeamTest` covers the
 * behaviour; this covers the source, so the trap is visible where it is easy
 * to reintroduce.
 */
test('the middleware registry stays pure class-string literals', function (): void {
    $source = (string) file_get_contents(
        (string) new ReflectionClass(MiddlewareRegistrar::class)->getFileName()
    );

    $start = strpos($source, 'public static function aliases()');
    $end = strpos($source, 'public static function csrfExceptions()');

    expect($start)->not->toBeFalse()
        ->and($end)->not->toBeFalse();

    $registry = substr($source, (int) $start, (int) $end - (int) $start);

    foreach (['Config::', 'config(', 'Facade', 'app(', 'resolve('] as $needle) {
        expect($registry)->not->toContain(
            $needle,
            "MiddlewareRegistrar::aliases()/groups() reads {$needle} — it runs before RegisterFacades and will fatal on a real boot.",
        );
    }
});

/**
 * `Services/` means "a concrete implementation of one of our own contracts",
 * so that a contract and its implementation are findable from each other.
 * Filtered to `Nvade\Numerosis\Contracts\*` rather than "implements anything"
 * — `PreservingPathTenantResolver` and the three tenancy bootstrappers
 * satisfied the old, unfiltered check by inheriting or implementing a
 * stancl/tenancy contract, which this test was written to catch. The named
 * exceptions are deliberate stancl adapters, not contract-shaped classes;
 * adding a fifth should be an edit to this list, not something a new file
 * slips into.
 */
test('everything in Services implements one of our contracts, or is a named exception', function (): void {
    $exceptions = [
        BillingService::class,
        PreservingPathTenantResolver::class,
        AuthGuardBootstrapper::class,
        PasswordBrokerBootstrapper::class,
        SpatiePermissionsBootstrapper::class,
    ];

    $files = (new Finder)
        ->files()
        ->in(dirname(__DIR__, 2).'/src/Services')
        ->name('*.php');

    expect(iterator_count($files))->toBeGreaterThan(0, 'Scanned no files — the path above is wrong.');

    foreach ($files as $file) {
        $class = 'Nvade\\Numerosis\\Services\\'
            .str_replace('/', '\\', (string) preg_replace('/\.php$/', '', $file->getRelativePathname()));

        if (in_array($class, $exceptions, true)) {
            continue;
        }

        if (! class_exists($class)) {
            throw new RuntimeException(
                "{$file->getRelativePathname()} does not declare {$class} — its namespace and its path disagree.",
            );
        }

        $ownInterfaces = array_filter(
            new ReflectionClass($class)->getInterfaceNames(),
            fn (string $interface): bool => str_starts_with($interface, 'Nvade\\Numerosis\\Contracts\\'),
        );

        expect($ownInterfaces)->not->toBe(
            [],
            "{$class} implements none of our own contracts. Either give it one, or add it to this test's named exceptions.",
        );
    }
});

/**
 * `src/Boot/` is for classes that validate or normalize the *host
 * application*, which happens once per boot. A request-time read belongs
 * somewhere a reader expects one: `Boot\UserModels` sat here reading live
 * tenancy state until its two callers moved to `Enums\Tenancy\Context`.
 */
test('nothing in Boot reads live tenancy state', function (): void {
    $files = (new Finder)
        ->files()
        ->in(dirname(__DIR__, 2).'/src/Boot')
        ->name('*.php');

    expect(iterator_count($files))->toBeGreaterThan(0, 'Scanned no files — the path above is wrong.');

    foreach ($files as $file) {
        $contents = $file->getContents();

        foreach (['tenancy()', 'tenant()'] as $needle) {
            expect($contents)->not->toContain(
                $needle,
                "Boot/{$file->getRelativePathname()} reads {$needle}, which is request-time state — Boot/ normalizes the host application.",
            );
        }

        expect(str_contains($contents, 'final class'))->toBeTrue(
            "Boot/{$file->getRelativePathname()} is not final; nothing here is an extension point.",
        );
    }
});

/** `src/Routing/` is route loading and route naming, and nothing else moves in beside them. */
test('Routing holds only route classes', function (): void {
    $files = (new Finder)
        ->files()
        ->in(dirname(__DIR__, 2).'/src/Routing')
        ->name('*.php');

    expect(iterator_count($files))->toBeGreaterThan(0, 'Scanned no files — the path above is wrong.');

    foreach ($files as $file) {
        expect($file->getFilenameWithoutExtension())->toStartWith(
            'Route',
            "Routing/{$file->getRelativePathname()} is not a route class.",
        );
    }
});

/**
 * `Services/` mirrors `Contracts/` flatly, so an implementation is findable
 * from its interface. `Services/Billing/` carried `Checkout/`, `Plans/`,
 * `Subscriptions/` and `Resolvers/` until 2026-09-11.
 */
test('Services is one domain folder deep, like Contracts', function (): void {
    foreach (['Services', 'Contracts'] as $directory) {
        $files = (new Finder)
            ->files()
            ->in(dirname(__DIR__, 2).'/src/'.$directory)
            ->name('*.php');

        expect(iterator_count($files))->toBeGreaterThan(0, 'Scanned no files — the path above is wrong.');

        foreach ($files as $file) {
            expect(substr_count($file->getRelativePathname(), '/'))->toBeLessThan(
                2,
                "{$directory}/{$file->getRelativePathname()} nests below its domain folder.",
            );
        }
    }
});

/**
 * `Stancl\Tenancy\Database\Concerns\TenantRun::run()` has no `try`/`finally`
 * — a throw inside its callback leaves the process initialized against that
 * tenant, and the next queued job on the same worker runs in the wrong
 * tenant's context. `Concerns\Tenancy\RunsInTenant::runInTenant()` restores
 * or ends tenancy unconditionally and is the only sanctioned spelling.
 */
test('nothing calls ->run( on a tenant outside RunsInTenant', function (): void {
    $files = (new Finder)
        ->files()
        ->in(dirname(__DIR__, 2).'/src')
        ->name('*.php');

    expect(iterator_count($files))->toBeGreaterThan(0, 'Scanned no files — the path above is wrong.');

    foreach ($files as $file) {
        if ($file->getFilename() === 'RunsInTenant.php') {
            continue;
        }

        // Narrowed to a tenant receiver and to the closure form: `/->run\(/`
        // matched every action's own `->run(` and still missed a
        // `$tenant->run(` whose closure started on the next line.
        foreach (['/\$\w*[Tt]enant\w*\s*->\s*run\s*\(/', '/tenant\(\)\s*->\s*run\s*\(/', '/->run\(\s*function/'] as $pattern) {
            expect($file->getContents())->not->toMatch(
                $pattern,
                "{$file->getRelativePathname()} calls ->run( on a tenant directly — use Concerns\\Tenancy\\RunsInTenant::runInTenant() instead.",
            );
        }
    }
});

/**
 * `PermissionContext` is core's own vocabulary; a policy's
 * `permissionContext()` may still name a context a host added
 * (`.ai/rules/package-boundaries.md`: a *missing* context throws
 * `PermissionDoesNotExist`, not this test's problem), but every context this
 * package itself seeds should resolve to a case, or the enum and the seeders
 * have silently drifted apart.
 */
test('every core policy context resolves to a PermissionContext case', function (): void {
    $files = (new Finder)
        ->files()
        ->in(dirname(__DIR__, 2).'/src/Policies')
        ->name('*.php');

    expect(iterator_count($files))->toBeGreaterThan(0, 'Scanned no files — the path above is wrong.');

    $checked = 0;

    foreach ($files as $file) {
        if (! str_contains($file->getContents(), 'use ChecksContextPermissions;')) {
            continue;
        }

        $class = 'Nvade\\Numerosis\\Policies\\'.str_replace(
            ['/', '.php'],
            ['\\', ''],
            $file->getRelativePathname(),
        );

        if (! class_exists($class)) {
            expect(false)->toBeTrue("{$class} does not exist — namespace/path mismatch.");

            continue;
        }

        expect(in_array(ChecksContextPermissions::class, class_uses_recursive($class), true))->toBeTrue();

        $method = new ReflectionClass($class)->getMethod('permissionContext');
        $context = $method->invoke(new $class);

        if (! is_string($context)) {
            expect(false)->toBeTrue("{$class}::permissionContext() did not return a string.");

            continue;
        }

        expect(PermissionContext::tryFrom($context))->not->toBeNull(
            "{$class}::permissionContext() returns '{$context}', which is not a PermissionContext case.",
        );

        $checked++;
    }

    expect($checked)->toBeGreaterThan(0, 'No policy composing ChecksContextPermissions was found.');
});

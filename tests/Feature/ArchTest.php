<?php

declare(strict_types=1);

use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Symfony\Component\Finder\Finder;

/**
 * Keeps the boundary between the billing/tenancy contracts introduced by the
 * billing-provisioning-clarity refactor and this app's Eloquent models
 * honest: a contract that typehints Nvade\Numerosis\Models\* nails every implementer to
 * this app's classes, which is exactly what a consumer installing this as a
 * package would have to fork around.
 *
 * Scoped to Nvade\Numerosis\Contracts\Billing plus the two new tenancy contracts rather
 * than all of Nvade\Numerosis\Contracts: Subscribable and HasTenants predate this
 * refactor, reference Nvade\Numerosis\Models\* deliberately, and are kept as-is (see
 * .claude/plans/archive/billing-provisioning-clarity-refactor.md §2.1). UserResolver
 * was removed entirely (2026-07-31) — one implementation, nothing ever
 * resolved it through the interface, everyone called
 * GetAuthenticatedUser::run() directly.
 */
arch('billing contracts do not depend on app models')
    ->expect('Nvade\Numerosis\Contracts\Billing')
    ->not->toUse('Nvade\Numerosis\Models');

arch('new tenancy contracts do not depend on app models')
    ->expect([ProvisionsTenant::class, TenantDomainPolicy::class])
    ->not->toUse('Nvade\Numerosis\Models');

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
        ->in(array_filter($roots, 'is_dir'))
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

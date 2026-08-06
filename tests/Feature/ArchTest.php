<?php

declare(strict_types=1);

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
 * .claude/plans/billing-provisioning-clarity-refactor.md §2.1). UserResolver
 * was removed entirely (2026-07-31) — one implementation, nothing ever
 * resolved it through the interface, everyone called
 * GetAuthenticatedUser::run() directly.
 */
arch('billing contracts do not depend on app models')
    ->expect('Nvade\Numerosis\Contracts\Billing')
    ->not->toUse('Nvade\Numerosis\Models');

arch('new tenancy contracts do not depend on app models')
    ->expect(['Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant', 'Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy'])
    ->not->toUse('Nvade\Numerosis\Models');

/**
 * Guards the Phase 10 fix (.claude/plans/opt-in-feature-classes.md):
 * Nvade\Numerosis\Models\Tenant\User used to import Nvade\Chat\Concerns\HasChatCapabilities
 * and Nvade\Chat\Enums\DisplayStatus directly, which meant removing
 * app-modules/chat was a fatal error rather than a disabled feature. Written
 * before the fix landed and confirmed to fail against the unfixed model —
 * a test that only ever ran green against fixed code proves nothing, same
 * discipline .claude/rules/auth-login.md records for the passwordless-login
 * regression tests.
 */
arch('core does not depend on app-modules')
    ->expect('App')
    ->not->toUse('Nvade');

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

    $files = (new Symfony\Component\Finder\Finder)
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

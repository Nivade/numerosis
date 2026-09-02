<?php

declare(strict_types=1);

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use InterNACHI\Modular\Support\ModuleConfig;
use InterNACHI\Modular\Support\ModuleRegistry;
use Nvade\Numerosis\Models\Central\ModuleOffering;
use Nvade\Numerosis\Models\Central\Tenant as TenantModel;
use Nvade\Numerosis\Tests\Browser\PathModeTestCase;
use Pest\Browser\Playwright\Playwright;

uses(PathModeTestCase::class, RefreshDatabase::class);

/**
 * The module marketplace, rendered through a real request.
 *
 * `Marketplace::getModules()` is an **intersection**: the catalogue
 * (`ModuleCatalog::available()`, central rows) narrowed to the modules
 * `internachi/modular` reports as installed on this node. Nothing else tests
 * it, and each half alone looks fine — an offering with no matching module
 * silently vanishes from the grid rather than erroring, which is the failure
 * this covers.
 *
 * **The registry is faked rather than a real module being scaffolded on
 * disk.** `ModuleRegistry` takes its loader as a constructor closure and
 * memoizes it, and `ModuleConfig` has a plain public constructor, so a
 * container instance is enough — no `workbench/app-modules/` directory, no
 * composer autoload regeneration, and nothing left behind for the next test.
 * It has to be an `instance()` on the container: the `Modules` facade
 * resolves `ModuleRegistry::class`, and the real one is registered as a
 * singleton by modular's own provider.
 */
function fakeModuleRegistryWith(string ...$slugs): void
{
    app()->instance(ModuleRegistry::class, new ModuleRegistry(
        '/tmp/numerosis-test-modules',
        fn (): Collection => collect($slugs)->mapWithKeys(
            fn (string $slug): array => [$slug => new ModuleConfig($slug, '/tmp/numerosis-test-modules/'.$slug)],
        ),
    ));
}

function bootTenantWithSignedInTenantUser(): TenantModel
{
    $tenant = Tenant::factory()->create();

    $tenant->run(function (): void {
        Auth::guard(Config::string('numerosis.auth.guards.tenant'))
            ->login(TenantUser::factory()->create());
    });

    Playwright::setHost('central.numerosistest.test');

    return $tenant;
}

/**
 * As above, but the signed-in tenant user is the tenant's **owner**.
 *
 * `ModulePolicy::purchase()` short-circuits to true for the owner
 * (`isTenantOwner()` matches `$tenant->owner()?->global_id` against the
 * actor's), so this reaches `canPurchaseModules()` without seeding tenant
 * permissions at all. `global_id` is the shared identity across the two user
 * tables, which is why the central and tenant rows are created as a pair.
 */
function bootTenantWithSignedInOwner(): TenantModel
{
    $tenant = Tenant::factory()->create();

    $owner = CentralUser::factory()->create();
    $tenant->users()->attach($owner->global_id, ['role' => 'owner', 'joined_at' => now()]);

    $tenant->run(function () use ($owner): void {
        $user = TenantUser::create([
            'global_id' => $owner->global_id,
            'name' => $owner->name,
            'email' => $owner->email,
        ]);

        Auth::guard(Config::string('numerosis.auth.guards.tenant'))->login($user);
    });

    Playwright::setHost('central.numerosistest.test');

    return $tenant;
}

it('lists only modules that are both offered and installed on this node', function (): void {
    $tenant = bootTenantWithSignedInTenantUser();

    // Offered and installed — must appear.
    ModuleOffering::factory()->create([
        'slug' => 'alerts',
        'name' => 'Alerts',
        'available' => true,
    ]);

    // Offered, but not installed on this node — must not appear. This is the
    // half that fails silently rather than loudly.
    ModuleOffering::factory()->create([
        'slug' => 'reporting',
        'name' => 'Reporting',
        'available' => true,
    ]);

    fakeModuleRegistryWith('alerts');

    $content = (string) visit("/{$tenant->id}/marketplace")->content();

    expect($content)->toContain('Alerts');
    expect($content)->not->toContain('Reporting');
    expect(str_contains($content, 'Server Error'))->toBeFalse();
});

/**
 * The control for the test above, and not merely a duplicate of it.
 *
 * "Reporting is absent" is a claim about the registry filter only if the same
 * page shows Reporting when the registry reports it installed. Without this,
 * the assertion above passes just as well against a marketplace that renders
 * nothing at all, or one that lost its catalogue query — the exact vacuous
 * shape `.claude/rules/testing.md` records for directory scans.
 */
it('shows a module once the registry reports it installed', function (): void {
    $tenant = bootTenantWithSignedInTenantUser();

    ModuleOffering::factory()->create([
        'slug' => 'reporting',
        'name' => 'Reporting',
        'available' => true,
    ]);

    fakeModuleRegistryWith('alerts', 'reporting');

    $content = (string) visit("/{$tenant->id}/marketplace")->content();

    expect($content)->toContain('Reporting');
    expect(str_contains($content, 'Server Error'))->toBeFalse();
});

/**
 * An offering the operator has retired stays out of the grid even while the
 * module is still installed on the node — `EloquentModuleCatalog::available()`
 * scopes on `available`, and this is the only test that reaches that scope
 * through a rendered page rather than a direct call.
 */
it('hides an unavailable offering even when the module is installed', function (): void {
    $tenant = bootTenantWithSignedInTenantUser();

    ModuleOffering::factory()->create([
        'slug' => 'reporting',
        'name' => 'Reporting',
        'available' => false,
    ]);

    fakeModuleRegistryWith('reporting');

    $content = (string) visit("/{$tenant->id}/marketplace")->content();

    expect($content)->not->toContain('Reporting');
    expect(str_contains($content, 'Server Error'))->toBeFalse();
});

/**
 * The purchase affordance is gated on who is looking:
 * `purchaseAction()->visible(fn () => $this->canPurchaseModules())`, which for
 * an owner short-circuits through `ModulePolicy::purchase()`.
 *
 * **The confirmation modal now opens.** It didn't for a reason that had
 * nothing to do with this page: `Nvade\Numerosis\Http\Middleware\
 * InitializeLivewireTenancyByPath`'s docblock has the full story — path
 * mode's Livewire commits were silently failing tenancy identification, so
 * `mountAction` never ran, on *every* Livewire interaction under this
 * identification mode, not just this one. Publishing Filament's assets and
 * setting `tenancy.filesystem.asset_helper_tenancy` to false (see
 * `FilesystemTenancyBootstrapper`) is the other half this test needs, and is
 * done here rather than globally to avoid the order-coupling with
 * `InstallNumerosisCommandTest::test_it_fails_when_filament_assets_ran_but_the_numerosis_theme_did_not_land`,
 * whose teardown deletes `public_path('css'|'js')`.
 *
 * A click-through purchase is still out of reach past the modal: confirming
 * calls `PurchaseModule`, which needs a billing address, an active
 * subscription or a Stripe one-time charge, and real Cashier calls — there is
 * no module-purchase equivalent of `LocalCheckoutGateway`. Its guard clauses
 * are covered offline instead, in `PurchaseModuleTest`.
 */
it('offers the purchase affordance to a tenant owner, and its modal opens', function (): void {
    // A private public_path(), not the shared one `InstallNumerosisCommandTest`
    // asserts against — under `--parallel`, that test's file runs in a
    // different worker process but the same physical filesystem, so
    // publishing into the real `public/css`/`public/js` and deleting them in
    // a `finally` races that test's own use of the same paths. See
    // `Nvade\Numerosis\Http\Middleware\InitializeLivewireTenancyByPath`'s
    // docblock for why this test needs real assets at all.
    $publicPath = sys_get_temp_dir().'/numerosis-marketplace-test-public-'.uniqid();
    File::ensureDirectoryExists($publicPath);
    $originalPublicPath = app()->publicPath();

    // The harness's own `build/manifest.json` (the workbench app's Vite
    // build) lives under the real public path — Numerosis::assetTags()
    // reads it at render time regardless of which assets this test is
    // publishing, so it has to exist under the private path too.
    if (File::isDirectory($originalPublicPath.'/build')) {
        File::copyDirectory($originalPublicPath.'/build', $publicPath.'/build');
    }

    app()->usePublicPath($publicPath);

    Artisan::call('vendor:publish', ['--tag' => 'numerosis-assets', '--force' => true]);
    Artisan::call('filament:assets');
    Config::set('tenancy.filesystem.asset_helper_tenancy', false);

    try {
        $tenant = bootTenantWithSignedInOwner();

        ModuleOffering::factory()->create([
            'slug' => 'alerts',
            'name' => 'Alerts',
            'available' => true,
        ]);

        fakeModuleRegistryWith('alerts');

        $page = visit("/{$tenant->id}/marketplace");

        $content = (string) $page->content();
        expect($content)->toContain('Alerts');
        expect($content)->toContain('Purchase');
        expect(str_contains($content, 'Server Error'))->toBeFalse();

        $page->click('Purchase');
        $page->wait(2);

        expect((string) $page->content())->toContain('Purchase Alerts?');
    } finally {
        app()->usePublicPath($originalPublicPath);
        File::deleteDirectory($publicPath);
    }
});

/**
 * The control for the visibility half: a tenant user who is not the owner and
 * holds no `purchase modules` permission sees the module but no way to buy it.
 */
it('hides the purchase affordance from a non-owner without permission', function (): void {
    $tenant = bootTenantWithSignedInTenantUser();

    ModuleOffering::factory()->create([
        'slug' => 'alerts',
        'name' => 'Alerts',
        'available' => true,
    ]);

    fakeModuleRegistryWith('alerts');

    $content = (string) visit("/{$tenant->id}/marketplace")->content();

    expect($content)->toContain('Alerts');
    expect($content)->not->toContain('Purchase');
});

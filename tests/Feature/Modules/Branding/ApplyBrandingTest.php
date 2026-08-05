<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Modules\Branding;

use App\Models\Central\Tenant;
use App\Models\Tenant\Module;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nvade\Branding\Http\Middleware\ApplyBranding;
use Nvade\Branding\Models\BrandingSettings;
use Nvade\Branding\Support\BrandingCache;
use Nvade\Numerosis\Actions\Modules\MigrateModules;
use Nvade\Numerosis\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The middleware now lives in the branding module and is registered by
 * BrandingPlugin, so it only runs for tenants that have the module enabled.
 * It therefore *overrides* panel defaults rather than setting them: with no
 * branding to apply it registers nothing at all, and the panel's own
 * ->colors()/->brandLogo() stand. These tests assert the decision it reaches
 * (the cached payload) rather than re-reading FilamentColor, because
 * Filament's ColorManager::getColors() memoises on first call for the life of
 * the container and ignores every later register().
 *
 * Tests the nvade/branding app-side module package — not a numerosis
 * dependency (decision #6), thin-app owns it.
 */
#[Group('thin-app')]
class ApplyBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function runMiddleware(): void
    {
        (new ApplyBranding)->handle(Request::create('/'), fn ($request) => $request);
    }

    /**
     * @return array{color: array<int|string, string>|null, logo: string|null, favicon: string|null}
     */
    private function cachedBranding(): array
    {
        /** @var array{color: array<int|string, string>|null, logo: string|null, favicon: string|null} $branding */
        $branding = Cache::get(BrandingCache::panelBranding());

        return $branding;
    }

    public function test_it_applies_nothing_outside_tenant_context(): void
    {
        $this->runMiddleware();

        $this->assertFalse(Cache::has(BrandingCache::panelBranding()));
    }

    public function test_an_enabled_branding_module_with_a_colour_overrides_the_panel_default(): void
    {
        // Module row created before MigrateModules dispatches — its own
        // migrated_at stamp (Nvade\Numerosis\Actions\Modules\MigrateModules) is an UPDATE keyed on
        // name, so the row has to exist first or the stamp is a silent no-op.
        $tenant = Tenant::factory()->create();

        $tenant->run(function () {
            Module::create(['name' => 'branding', 'description' => 'Branding', 'enabled' => true]);
        });

        MigrateModules::dispatchSync($tenant, 'branding');

        $tenant->run(function () {
            BrandingSettings::current()->update(['primary_color' => '#123456']);

            $this->runMiddleware();

            $this->assertSame(Color::generatePalette('#123456'), FilamentColor::getColor('primary'));
        });
    }

    public function test_it_applies_a_stored_logo_and_favicon_to_the_current_panel(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->run(function () {
            Module::create(['name' => 'branding', 'description' => 'Branding', 'enabled' => true]);
        });

        MigrateModules::dispatchSync($tenant, 'branding');

        $tenant->run(function () {
            BrandingSettings::current()->update([
                'logo_path' => 'branding/logo.png',
                'favicon_path' => 'branding/favicon.png',
            ]);

            $panel = Filament::getPanel('tenantAdmin');
            Filament::setCurrentPanel($panel);

            $this->runMiddleware();

            // Served through stancl's tenant asset route, not a public path —
            // the `public` disk's root is tenant-suffixed.
            $this->assertSame(tenant_asset('branding/logo.png'), $panel->getBrandLogo());
            $this->assertSame(tenant_asset('branding/favicon.png'), $panel->getFavicon());
        });
    }

    public function test_a_disabled_branding_module_applies_nothing_even_with_a_stored_colour(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->run(function () {
            // Purchased once, then cancelled — the row survives per
            // .claude/rules/billing-checkout.md ("cancelling must never
            // destroy tenant data"), but a disabled module must not keep
            // painting the panel for free.
            Module::create(['name' => 'branding', 'description' => 'Branding', 'enabled' => false]);
        });

        MigrateModules::dispatchSync($tenant, 'branding');

        $tenant->run(function () {
            BrandingSettings::current()->update(['primary_color' => '#123456']);

            $this->runMiddleware();

            $this->assertSame(
                ['color' => null, 'logo' => null, 'favicon' => null],
                $this->cachedBranding(),
            );
        });
    }

    public function test_an_enabled_but_not_yet_migrated_branding_module_applies_nothing(): void
    {
        // No MigrateModules dispatch — enabled=true only means the purchase
        // succeeded, not that branding_settings exists yet. Reading migrated_at
        // (Nvade\Numerosis\Models\Tenant\Module) rather than enabled alone is the fix.
        $tenant = Tenant::factory()->create();

        $tenant->run(function () {
            Module::create(['name' => 'branding', 'description' => 'Branding', 'enabled' => true]);

            $this->runMiddleware();

            $this->assertSame(
                ['color' => null, 'logo' => null, 'favicon' => null],
                $this->cachedBranding(),
            );
        });
    }

    public function test_the_resolved_branding_is_cached_and_invalidated_when_settings_change(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->run(function () {
            Module::create(['name' => 'branding', 'description' => 'Branding', 'enabled' => true]);
        });

        MigrateModules::dispatchSync($tenant, 'branding');

        $tenant->run(function () {
            BrandingSettings::current()->update(['primary_color' => '#123456']);

            $this->runMiddleware();
            $this->assertSame(Color::generatePalette('#123456'), $this->cachedBranding()['color']);

            // Mutate the row without going through Eloquent, so
            // BrandingSettings::booted()'s invalidator never fires — proves
            // the second call reads the cache, not the database.
            DB::table('branding_settings')->update(['primary_color' => '#111111']);

            $this->runMiddleware();
            $this->assertSame(Color::generatePalette('#123456'), $this->cachedBranding()['color']);

            // A real save fires the invalidator.
            BrandingSettings::current()->update(['primary_color' => '#abcdef']);
            $this->assertNull(Cache::get(BrandingCache::panelBranding()));

            $this->runMiddleware();
            $this->assertSame(Color::generatePalette('#abcdef'), $this->cachedBranding()['color']);
        });
    }

    public function test_a_query_exception_is_cached_so_the_fallback_does_not_repeat_every_request(): void
    {
        // migrated_at set without actually running the branding migration —
        // simulates the race MigrateModules can lose: enabled and "migrated"
        // per the flag, but branding_settings does not exist yet.
        $tenant = Tenant::factory()->create();

        $tenant->run(function () {
            Module::create([
                'name' => 'branding',
                'description' => 'Branding',
                'enabled' => true,
                'migrated_at' => now(),
            ]);

            DB::enableQueryLog();

            $this->runMiddleware();

            $queriesAfterFirstCall = count(DB::getQueryLog());
            $this->assertTrue(Cache::has(BrandingCache::panelBranding()));

            $this->runMiddleware();

            // Cached negative result — no new queries, so no repeated
            // report() either.
            $this->assertCount($queriesAfterFirstCall, DB::getQueryLog());
        });
    }
}

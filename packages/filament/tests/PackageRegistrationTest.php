<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Tests;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Illuminate\View\FileViewFinder;
use Nvade\Numerosis\Support\Features;
use Nvade\NumerosisFilament\Features\ActivityLogFeature;
use Nvade\NumerosisFilament\Features\AdminPanelFeature;
use Nvade\NumerosisFilament\Features\TenantPanelFeature;
use Nvade\NumerosisFilament\NumerosisFilamentServiceProvider;
use Nvade\NumerosisFilament\Providers\NumerosisAdminPanelProvider;
use Nvade\NumerosisFilament\Providers\NumerosisTenantPanelProvider;

/**
 * This package's own half of the wiring. What the panels *do* is covered by
 * core's suite, which has the tenancy harness; what is proven here is only
 * that this package plugs into core's seams and does not have to be named by
 * core to do it.
 */
class PackageRegistrationTest extends TestCase
{
    public function test_it_registers_its_three_features_without_core_naming_them(): void
    {
        $registered = Features::registered();

        foreach ([AdminPanelFeature::class, TenantPanelFeature::class, ActivityLogFeature::class] as $feature) {
            $this->assertContains($feature, $registered);
        }

        // The other half of D2, and the one that actually matters: core's own
        // config must not name a class that lives here, or a host without this
        // package boots straight into `$this->app->make()` on a missing class.
        /** @var list<string> $configured */
        $configured = Config::array('numerosis.features');

        foreach ([AdminPanelFeature::class, TenantPanelFeature::class, ActivityLogFeature::class] as $feature) {
            $this->assertNotContains($feature, $configured);
        }
    }

    public function test_it_serves_the_shared_numerosis_view_namespace(): void
    {
        $finder = view()->getFinder();

        $this->assertInstanceOf(FileViewFinder::class, $finder);

        /** @var array<string, list<string>> $hints */
        $hints = $finder->getHints();

        $this->assertTrue(
            array_any(
                $hints['numerosis'] ?? [],
                static fn (string $path): bool => is_file($path.'/filament/admin/pages/register-tenant.blade.php'),
            ),
            'This package does not serve numerosis::filament.* — addNamespace() appends rather than replaces, so both packages should own the same namespace.'
        );
    }

    public function test_it_registers_both_panels(): void
    {
        $panels = array_keys(Filament::getPanels());

        $this->assertContains('admin', $panels);
        $this->assertContains('tenantAdmin', $panels);
    }

    /**
     * A host naming its own provider in core's config is what core registers;
     * this package must then stand down for that panel rather than register a
     * competing provider for the same panel id.
     */
    public function test_it_stands_down_for_a_host_supplied_panel_provider(): void
    {
        $this->assertSame(
            [NumerosisAdminPanelProvider::class, NumerosisTenantPanelProvider::class],
            NumerosisFilamentServiceProvider::panelProvidersToRegister(),
        );

        Config::set('numerosis.panels.admin.provider', 'App\\Providers\\Filament\\HostAdminPanelProvider');

        $this->assertSame(
            [NumerosisTenantPanelProvider::class],
            NumerosisFilamentServiceProvider::panelProvidersToRegister(),
        );

        Config::set('numerosis.panels.tenant.provider', 'App\\Providers\\Filament\\HostTenantPanelProvider');

        $this->assertSame([], NumerosisFilamentServiceProvider::panelProvidersToRegister());
    }
}

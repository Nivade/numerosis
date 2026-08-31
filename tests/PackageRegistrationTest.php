<?php

declare(strict_types=1);

namespace Nvade\NumerosisAuthUi\Tests;

use Illuminate\Support\Facades\Config;
use Illuminate\View\FileViewFinder;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Social\ConfiguredProviders;
use Nvade\NumerosisAuthUi\Features\SocialLoginFeature;
use Nvade\NumerosisAuthUi\Livewire\PasswordlessLogin;
use Nvade\NumerosisAuthUi\NumerosisAuthUiServiceProvider;

/**
 * Everything this package does at boot, asserted here rather than in core.
 *
 * Core's suite proves the *result* — that `login` resolves, that the tenant
 * panel gets a login page — because that is where a real host's wiring is
 * exercised. This file proves the package still does its half when core is
 * not the one arranging the test, which is the difference between "the split
 * composes" and "core happens to compensate".
 */
class PackageRegistrationTest extends TestCase
{
    public function test_it_registers_its_feature_rather_than_relying_on_cores_config(): void
    {
        $this->assertContains(SocialLoginFeature::class, Features::registered());
        $this->assertNotContains(
            SocialLoginFeature::class,
            Config::array('numerosis.features'),
            'Core config must not name this class: core would then boot against a class that may not be installed.'
        );
        $this->assertTrue(Features::enabledClass(SocialLoginFeature::class));
    }

    /**
     * The feature name is core's constant, not a literal, because three core
     * views gate on it and cannot reference this class — a constant fetch
     * autoloads, unlike a bare `use` import, so reading
     * `SocialLoginFeature::NAME` from core would turn an absent optional
     * package into a fatal on those three screens.
     */
    public function test_its_feature_name_is_the_constant_core_owns(): void
    {
        $this->assertSame(ConfiguredProviders::FEATURE, SocialLoginFeature::NAME);
    }

    public function test_it_serves_the_shared_numerosis_view_namespace(): void
    {
        $finder = view()->getFinder();

        $this->assertInstanceOf(FileViewFinder::class, $finder);

        /** @var array<string, list<string>> $hints */
        $hints = $finder->getHints();

        $this->assertArrayHasKey('numerosis', $hints);
        $this->assertTrue(
            array_any(
                $hints['numerosis'],
                static fn (string $path): bool => is_file($path.'/livewire/auth/register.blade.php'),
            ),
            'This package registered no path serving numerosis::livewire.auth.*.'
        );
    }

    public function test_it_fills_cores_tenant_panel_login_seam(): void
    {
        $this->assertSame(PasswordlessLogin::class, Config::get('numerosis.panels.tenant.login'));
    }

    /**
     * A host that already named its own login component must win — this
     * package fills the seam, it does not own it.
     */
    public function test_it_leaves_a_host_configured_login_component_alone(): void
    {
        $this->assertSame(PasswordlessLogin::class, Config::get('numerosis.panels.tenant.login'));

        Config::set('numerosis.panels.tenant.login', 'App\\Livewire\\MyLogin');

        $this->app?->register(NumerosisAuthUiServiceProvider::class, force: true);

        $this->assertSame('App\\Livewire\\MyLogin', Config::get('numerosis.panels.tenant.login'));
    }
}

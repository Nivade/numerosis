<?php

declare(strict_types=1);

namespace Nvade\NumerosisOnboarding\Tests;

use Illuminate\Support\Facades\Config;
use Illuminate\View\FileViewFinder;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Tenancy\SelfServeRegistration;
use Nvade\NumerosisOnboarding\Features\RegistrationWizardFeature;
use Nvade\NumerosisOnboarding\Livewire\Steps\CompanyInfo;
use Nvade\NumerosisOnboarding\NumerosisOnboardingServiceProvider;

/**
 * Everything this package does at boot, asserted here rather than in core.
 *
 * Core's suite proves the *result* — that `/get-started` resolves, that the
 * wizard advances — because that is where a real host's wiring is exercised.
 * This file proves the package still does its half when core is not the one
 * arranging the test, which is the difference between "the split composes"
 * and "core happens to compensate".
 */
class PackageRegistrationTest extends TestCase
{
    public function test_it_registers_its_feature_rather_than_relying_on_cores_config(): void
    {
        $this->assertContains(RegistrationWizardFeature::class, Features::registered());
        $this->assertNotContains(
            RegistrationWizardFeature::class,
            Config::array('numerosis.features'),
            'Core config must not name this class: core would then boot against a class that may not be installed.'
        );
        $this->assertTrue(Features::enabledClass(RegistrationWizardFeature::class));
    }

    /**
     * The feature name is core's constant, not a literal. Four core views and
     * `CompleteRedirectCheckout` gate on it and cannot reference this class —
     * a constant fetch autoloads, unlike a bare `use` import or `::class`, so
     * reading `RegistrationWizardFeature::NAME` from core would turn an
     * absent optional package into a fatal on those surfaces.
     */
    public function test_its_feature_name_is_the_constant_core_owns(): void
    {
        $this->assertSame(SelfServeRegistration::FEATURE, RegistrationWizardFeature::NAME);
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
                static fn (string $path): bool => is_file($path.'/livewire/tenant/registration/wizard/index.blade.php'),
            ),
            'This package registered no path serving numerosis::livewire.tenant.registration.*.'
        );
    }

    /**
     * Core's config no longer names the four step classes, so this package
     * has to supply them or the wizard boots with nothing to walk through.
     */
    public function test_it_fills_the_default_step_list_core_no_longer_carries(): void
    {
        $this->assertContains(CompanyInfo::class, Config::array('numerosis.tenancy.registration.steps'));
    }

    /**
     * A host that already configured its own steps must win — this package
     * fills the key, it does not own it.
     */
    public function test_it_leaves_a_host_configured_step_list_alone(): void
    {
        Config::set('numerosis.tenancy.registration.steps', ['App\\Livewire\\OnlyStep']);

        $this->app?->register(NumerosisOnboardingServiceProvider::class, force: true);

        $this->assertSame(['App\\Livewire\\OnlyStep'], Config::array('numerosis.tenancy.registration.steps'));
    }
}

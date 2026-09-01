<?php

declare(strict_types=1);

namespace Nvade\NumerosisAccount\Tests;

use Illuminate\Support\Facades\Config;
use Illuminate\View\FileViewFinder;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Ui\AccountPages;
use Nvade\NumerosisAccount\Features\AccountPagesFeature;

/**
 * Everything this package does at boot, asserted here rather than in core.
 *
 * Core's suite proves the *result* — that `/settings/profile` renders, that
 * the workspace list lists workspaces — because that is where the tenancy
 * harness lives. This file proves the package still does its half when core is
 * not the one arranging the test, which is the difference between "the split
 * composes" and "core happens to compensate".
 */
class PackageRegistrationTest extends TestCase
{
    public function test_it_registers_its_feature_rather_than_relying_on_cores_config(): void
    {
        $this->assertContains(AccountPagesFeature::class, Features::registered());
        $this->assertNotContains(
            AccountPagesFeature::class,
            Config::array('numerosis.features'),
            'Core config must not name this class: core would then boot against a class that may not be installed.'
        );
        $this->assertTrue(Features::enabledClass(AccountPagesFeature::class));
    }

    /**
     * The feature name is core's constant, not a literal. Six core and
     * satellite call sites gate a post-login redirect on it and cannot
     * reference this class — a constant fetch autoloads, unlike a bare `use`
     * import or `::class`, so an absent package would fatal all six.
     */
    public function test_its_feature_name_is_the_constant_core_owns(): void
    {
        $this->assertSame(AccountPages::FEATURE, AccountPagesFeature::NAME);
    }

    /**
     * Views join core's shared `numerosis::` namespace rather than claiming
     * one of their own — `addNamespace()` appends, so the moved screens keep
     * rendering under the view strings they always had.
     */
    public function test_it_serves_the_shared_numerosis_view_namespace(): void
    {
        $finder = view()->getFinder();

        $this->assertInstanceOf(FileViewFinder::class, $finder);

        $paths = $finder->getHints()['numerosis'] ?? [];

        $this->assertContains(
            realpath(dirname(__DIR__).'/resources/views'),
            array_map(static fn (string $path): string => (string) realpath($path), $paths),
        );
    }

    /**
     * Single-file Livewire pages need a namespace of this package's own:
     * `livewire.component_namespaces` maps a prefix to exactly one directory,
     * so core's `pages::` cannot be appended to the way a Blade view namespace
     * can.
     */
    public function test_it_registers_its_own_livewire_page_namespace(): void
    {
        $this->assertSame(
            realpath(dirname(__DIR__).'/resources/views/pages'),
            realpath(Config::string('livewire.component_namespaces.account-pages')),
        );
    }

    /*
     * There is deliberately no route assertion here. This harness never calls
     * `Numerosis::routes()` — a host's `bootstrap/app.php` does that, through
     * `withRouting()` — so a contributed callback is queued but never run, and
     * `Numerosis` exposes no reader for queued route contributions (the gap
     * `docs/extending.md` records). Core's suite asserts the resulting routes
     * against a real routing harness instead.
     */
}

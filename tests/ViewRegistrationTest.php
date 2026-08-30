<?php

declare(strict_types=1);

namespace Nvade\NumerosisUi\Tests;

use Illuminate\Support\Facades\View;

/**
 * This package ships views and nothing else, so what there is to test is that
 * they are reachable by the names the rest of the Numerosis packages use, and
 * that this package genuinely stands alone.
 */
class ViewRegistrationTest extends TestCase
{
    public function test_it_serves_its_views_under_the_shared_numerosis_namespace(): void
    {
        $this->assertTrue(View::exists('numerosis::components.ui.card'));
        $this->assertTrue(View::exists('numerosis::components.icons.google'));
        $this->assertTrue(View::exists('numerosis::components.placeholder-pattern'));
    }

    public function test_its_components_render_by_the_tag_name_other_packages_use(): void
    {
        $this->blade('<x-numerosis::ui.card>content</x-numerosis::ui.card>')
            ->assertSee('content')
            ->assertSee('rounded-xl', false);
    }

    /**
     * The lucide icons Flux does not ship. Registered from `booted()` so Flux's
     * own paths are searched first — a Flux-supplied icon of the same name
     * must keep winning over this package's fallback.
     */
    public function test_it_registers_its_flux_icon_fallbacks(): void
    {
        $this->assertTrue(View::exists('numerosis::flux.icon.layout-grid'));

        // Registered as an *anonymous component path* under the `flux` prefix
        // too, which is how the views actually address them (`<flux:icon.layout-grid />`).
        $this->blade('<flux:icon.layout-grid />')->assertSee('svg', false);
    }

    /**
     * The property that decides what may live here at all. `layouts/` and
     * `partials/` were moved into this package and moved straight back out
     * for failing exactly this: they name Nvade\Numerosis classes and call
     * `tenancy()`, so shipping them here would invert the dependency.
     */
    public function test_no_view_here_references_the_core_package_or_tenancy(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match('/Nvade\\\\Numerosis(?!Ui)|tenancy\(|\broute\(/', $contents) === 1) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders);
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $root = dirname(__DIR__).'/resources/views';

        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files, 'Scanned no view files — the resources/views path is wrong.');

        return $files;
    }
}

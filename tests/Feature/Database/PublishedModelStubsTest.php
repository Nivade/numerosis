<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Database;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Symfony\Component\Finder\Finder;

/**
 * `NumerosisServiceProvider` publishes `stubs/Models/{$relative}.stub` to
 * `app_path("Models/{$relative}.php")`, and composer's `autoload-dev` maps
 * `App\Models\` to `workbench/app/Models/` — so workbench stands in for a real
 * host's published output, and identical content is the invariant.
 *
 * Nothing enforced it, and two stubs shipped without a twin: `Numerosis::model()`
 * falls back to the package class when the host one is absent, which is correct
 * behaviour and is also what hid the gap.
 */
class PublishedModelStubsTest extends BaseTestCase
{
    public function test_every_published_stub_has_an_identical_workbench_model(): void
    {
        $root = dirname(__DIR__, 3);

        $stubs = iterator_to_array((new Finder)->files()->in($root.'/stubs/Models')->name('*.stub'), false);

        $this->assertNotEmpty($stubs, 'Scanned no stubs — the path above is wrong, so this guard is measuring nothing.');

        foreach ($stubs as $stub) {
            $relative = str_replace([$root.'/stubs/Models/', '.stub'], '', $stub->getPathname());
            $twin = $root.'/workbench/app/Models/'.$relative.'.php';

            $this->assertFileExists($twin, "stubs/Models/{$relative}.stub publishes a model workbench never exercises as a host class.");
            $this->assertSame($stub->getContents(), (string) file_get_contents($twin), "workbench/app/Models/{$relative}.php has drifted from the stub that publishes it.");
        }
    }
}

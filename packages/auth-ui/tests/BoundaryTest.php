<?php

declare(strict_types=1);

namespace Nvade\NumerosisAuthUi\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The property this package exists to have: it depends on `nvade/numerosis`
 * and `nvade/numerosis-ui`, and on nothing else in the set.
 *
 * The Filament edge is the one that matters. `NumerosisTenantPlugin` serves
 * this package's login component, and D1 of the package-split plan made that
 * a config seam (`numerosis.panels.tenant.login`) specifically so the
 * dependency runs one way only. A `use Filament\...` appearing here would
 * close that loop, and nothing would fail at boot — a bare `use` import is
 * lazy, so the cycle would only surface as a Composer resolution problem in
 * whichever host installs one package without the other.
 *
 * Deliberately not an `arch()` test: this package does not depend on
 * pest-plugin-arch, and a scan that reads files off disk keeps working if
 * that ever changes.
 */
class BoundaryTest extends BaseTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function forbiddenNamespaces(): array
    {
        return [
            'filament' => ['Filament\\'],
            'numerosis-filament' => ['Nvade\\NumerosisFilament\\'],
            'numerosis-onboarding' => ['Nvade\\NumerosisOnboarding\\'],
            'numerosis-modules' => ['Nvade\\NumerosisModules\\'],
        ];
    }

    #[DataProvider('forbiddenNamespaces')]
    public function test_no_file_here_references_a_package_this_one_must_not_depend_on(string $namespace): void
    {
        foreach ($this->sourceFiles() as $file) {
            $this->assertStringNotContainsString(
                $namespace,
                $file->getContents(),
                "{$file->getRelativePathname()} references [{$namespace}], which this package must not depend on."
            );
        }
    }

    /**
     * Carried over from core's own `ArchTest`, because these files used to be
     * covered by it and stopped being the moment they moved — a scan does not
     * fail when what it guards leaves, it just runs fewer times.
     */
    public function test_nothing_reads_the_old_cashier_appendix_keys(): void
    {
        $needles = ['cashier.billables', 'cashier.stripe_price_ids', 'cashier.redirect', 'cashier.features', 'cashier.brand'];

        foreach ($this->sourceFiles() as $file) {
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $file->getContents(), "{$file->getRelativePathname()} still references {$needle}");
            }
        }
    }

    /**
     * @return list<SplFileInfo>
     */
    private function sourceFiles(): array
    {
        $files = iterator_to_array(
            (new Finder)->files()->in([dirname(__DIR__).'/src', dirname(__DIR__).'/routes'])->name('*.php'),
            false
        );

        $this->assertNotEmpty($files, 'Scanned no files — the paths above are wrong.');

        return $files;
    }
}

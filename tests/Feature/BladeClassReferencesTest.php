<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use Nvade\Numerosis\Tests\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * PHPStan does not analyse Blade, so a class a view names survives a rename
 * only until something renders that view — and the suite renders 14 of the
 * package's 22 class-naming views, so a move can land green and break a page.
 * This closes that gap without needing every view to be reachable in a test.
 */
class BladeClassReferencesTest extends TestCase
{
    public function test_every_package_class_a_blade_view_names_exists(): void
    {
        $missing = [];
        $checked = 0;

        foreach ($this->bladeFiles() as $path => $contents) {
            foreach ($this->referencedClasses($contents) as $class) {
                $checked++;

                if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
                    continue;
                }

                $missing[] = "{$path} names {$class}, which does not exist";
            }
        }

        $this->assertGreaterThan(0, $checked, 'Scanned no class references — the Finder paths below are wrong.');
        $this->assertSame([], $missing);
    }

    /**
     * `@use(\Fully\Qualified\Name)`, a plain `use Fully\Qualified\Name;` inside
     * a view's PHP block, and any leftover inline `\Fully\Qualified\Name::`.
     *
     * @return list<class-string>
     */
    private function referencedClasses(string $contents): array
    {
        preg_match_all(
            '/@use\(\\\\?(Nvade\\\\Numerosis\\\\[A-Za-z0-9_\\\\]+)\)'
            .'|^\s*use\s+(Nvade\\\\Numerosis\\\\[A-Za-z0-9_\\\\]+);'
            .'|\\\\(Nvade\\\\Numerosis\\\\[A-Za-z0-9_\\\\]+)/m',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        $classes = [];

        foreach ($matches as $match) {
            foreach (array_slice($match, 1) as $group) {
                if ($group === '') {
                    continue;
                }

                /** @var class-string $group */
                $classes[] = $group;

                break;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @return array<string, string>
     */
    private function bladeFiles(): array
    {
        $root = dirname(__DIR__, 2);

        $roots = array_filter([
            $root.'/resources/views',
            $root.'/packages/ui/resources/views',
        ], is_dir(...));

        $files = [];

        foreach ((new Finder)->files()->in($roots)->name('*.blade.php') as $file) {
            $files[str_replace($root.'/', '', (string) $file->getRealPath())] = $file->getContents();
        }

        return $files;
    }
}

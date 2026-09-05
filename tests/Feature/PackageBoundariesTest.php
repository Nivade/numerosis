<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The boundary between the two packages left after
 * `.claude/plans/archive/humming-nibbling-flame.md`'s Phase 3 folded `auth-ui`,
 * `onboarding` and `account` into core: `nvade/numerosis` (core) and
 * `nvade/numerosis-ui` (the reusable Flux component library core itself
 * `require`s).
 *
 * While each satellite lived in its own repo, "does this file compile
 * without that package installed" was answered by the repo it sat in. In one
 * repo everything autoloads from everywhere, so the only thing left holding
 * the dependency graph in the shape a consumer actually installs is this
 * file.
 *
 * Every violation here is silent at runtime, which is why it needs a test at
 * all: a bare `use` import is lazy and `Foo::class` on an imported name never
 * autoloads, so a cycle surfaces only as a Composer resolution failure in
 * whichever host installs one package without the other — or as a fatal on
 * the first request that reaches the file.
 *
 * Deliberately a filesystem scan rather than `arch()`: `Nvade\NumerosisUi` is
 * prefixed by `Nvade\Numerosis`, so a namespace-prefix matcher cannot express
 * "ui may not reference core" at all, and half these rules are about strings
 * (`tenancy(`, `route(`) rather than namespaces.
 */
class PackageBoundariesTest extends BaseTestCase
{
    /**
     * @return array<string, array{0: string, 1: list<string>, 2: array<string, string>}>
     */
    public static function packages(): array
    {
        return [
            // The only satellite left. It exists to be installable with
            // nothing else in the set, so it may not name core, tenancy, or
            // a named route. `layouts/` and `partials/` were moved in here
            // once and moved straight back out for failing exactly this.
            'ui' => [
                'ui',
                ['src', 'resources'],
                [
                    'the core package' => '/Nvade\\\\Numerosis(?!Ui)/',
                    'filament' => '/Filament\\\\/',
                    'tenancy' => '/tenancy\(/',
                    'named routes' => '/\broute\(/',
                ],
            ],
        ];
    }

    /**
     * Phase 1 of `.claude/plans/archive/humming-nibbling-flame.md` deleted
     * `packages/filament` and `filament/filament` with it, so core naming a
     * `Filament\` symbol is no longer a lazy reference to an optional
     * package — it is a reference to a class that cannot be installed at all.
     *
     * Nothing enforced that until this test. The old guard was the *type* of
     * reference (`class_exists()`-guarded and type hints allowed, `implements`
     * and `use <Trait>` not — `.ai/rules/optional-dependencies.md`), which is
     * now simply "none", and a stray `use Filament\...` autoloads nothing
     * until the first request that reaches the line.
     *
     * Scans `workbench/` too: the dev harness boots the same providers a host
     * does, so a Filament reference there fatals `composer serve` rather than
     * the test suite, which is the slower way to find out.
     */
    public function test_core_names_no_filament_symbol(): void
    {
        $root = dirname(__DIR__, 2);

        $roots = array_values(array_filter([
            $root.'/src',
            $root.'/config',
            $root.'/routes',
            $root.'/resources',
            $root.'/database',
            $root.'/workbench',
        ], is_dir(...)));

        $files = iterator_to_array(
            (new Finder)->files()->in($roots)->name(['*.php', '*.blade.php']),
            false
        );

        $this->assertNotEmpty($files, 'Scanned no core files — the path list above is wrong, so this guard is measuring nothing.');

        foreach ($files as $file) {
            $this->assertSame(
                0,
                preg_match('/Filament\\\\/', self::codeOf($file)),
                self::path($file).' references Filament, which was deleted from this repo in Phase 1 and cannot be installed.'
            );
        }
    }

    /**
     * @param  list<string>  $directories
     * @param  array<string, string>  $forbidden
     */
    #[DataProvider('packages')]
    public function test_a_package_references_nothing_it_must_not_depend_on(string $package, array $directories, array $forbidden): void
    {
        foreach (self::filesIn($package, $directories) as $file) {
            foreach ($forbidden as $label => $pattern) {
                $this->assertSame(
                    0,
                    preg_match($pattern, self::codeOf($file)),
                    self::path($file)." references {$label}, which this package must not depend on."
                );
            }
        }
    }

    /**
     * Carried over from core's own `ArchTest`, which scans `src` only. These
     * files used to be covered by it and stopped being the moment they moved
     * — a directory scan does not fail when what it guards leaves, it just
     * runs fewer times.
     *
     * @param  list<string>  $directories
     * @param  array<string, string>  $forbidden
     */
    #[DataProvider('packages')]
    public function test_nothing_reads_the_old_cashier_appendix_keys(string $package, array $directories, array $forbidden): void
    {
        $needles = ['cashier.billables', 'cashier.stripe_price_ids', 'cashier.redirect', 'cashier.features', 'cashier.brand'];

        foreach (self::filesIn($package, $directories) as $file) {
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $file->getContents(),
                    self::path($file)." still references {$needle}"
                );
            }
        }
    }

    private static function path(SplFileInfo $file): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $file->getPathname());
    }

    /**
     * Comments are stripped before matching, so that a docblock *explaining*
     * why a package must not reach for something does not itself trip the
     * guard — `NumerosisUiServiceProvider`'s own class comment names the core
     * classes that got `layouts/` evicted from that package, and it should.
     * Blade files are matched raw: they are not tokenizable PHP, and a
     * `{{-- --}}` comment naming a forbidden symbol is not a case worth
     * building for.
     */
    private static function codeOf(SplFileInfo $file): string
    {
        $contents = $file->getContents();

        if (str_ends_with($file->getFilename(), '.blade.php')) {
            return $contents;
        }

        $code = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * @param  list<string>  $directories
     * @return list<SplFileInfo>
     */
    private static function filesIn(string $package, array $directories): array
    {
        $root = dirname(__DIR__, 2)."/packages/{$package}";

        $roots = array_values(array_filter(
            array_map(static fn (string $directory): string => "{$root}/{$directory}", $directories),
            is_dir(...),
        ));

        $files = iterator_to_array(
            (new Finder)->files()->in($roots)->name(['*.php', '*.blade.php']),
            false
        );

        self::assertNotEmpty($files, "Scanned no files under packages/{$package} — the directory list is wrong, so this guard is measuring nothing.");

        return $files;
    }
}

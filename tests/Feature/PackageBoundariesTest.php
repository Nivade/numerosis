<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The boundaries between the packages, now that a filesystem boundary no
 * longer exists to enforce them.
 *
 * While each package lived in its own repo, "does this file compile without
 * that package installed" was answered by the repo it sat in. In one repo
 * everything autoloads from everywhere, so the only thing left holding the
 * dependency graph in the shape a consumer actually installs is this file.
 * Each rule below was a per-package `BoundaryTest` before the collapse; they
 * are collected here rather than duplicated three ways, because three
 * near-identical scans in three directories is exactly how one of them
 * silently stops covering what it names.
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
            // The leaf. It exists to be installable with nothing else in the
            // set, so it may not name core, tenancy, or a named route.
            // `layouts/` and `partials/` were moved in here once and moved
            // straight back out for failing exactly this.
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
            // Depends on core and ui, and on nothing else. The Filament edge
            // is the one that matters: the tenant panel reaches this package's
            // login component through `numerosis.panels.tenant.login`, a
            // core-owned config key, precisely so the dependency runs one way.
            'auth-ui' => [
                'auth-ui',
                ['src', 'routes', 'resources'],
                [
                    'filament' => '/Filament\\\\/',
                    'numerosis-onboarding' => '/Nvade\\\\NumerosisOnboarding\\\\/',
                ],
            ],
            // `Filament\` is of course allowed here — this package *is* the
            // Filament layer. What must not appear are the other satellites:
            // the tenant panel's login page and the registration wizard are
            // both named through core config keys, never as classes.
            //
            // The module marketplace and module resource live here for good:
            // decision D-C (`.claude/plans/numerosis-consolidation.md`) kept
            // the module system in core behind `class_exists()` guards rather
            // than extracting it, so there is no numerosis-modules package for
            // this UI to belong to and no cycle for it to create.
            'filament' => [
                'filament',
                ['src', 'resources'],
                [
                    'numerosis-auth-ui' => '/Nvade\\\\NumerosisAuthUi\\\\/',
                    'numerosis-onboarding' => '/Nvade\\\\NumerosisOnboarding\\\\/',
                ],
            ],
        ];
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

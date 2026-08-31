<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\View;

use Illuminate\View\FileViewFinder;
use Nvade\Numerosis\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pins the one mechanism the whole package split rests on: every Numerosis
 * package registers its views under the **same** `numerosis::` namespace, and
 * `FileViewFinder::addNamespace()` appends to a namespace's path list rather
 * than replacing it, so they compose.
 *
 * Worth a test of its own because the failure is silent in both directions.
 * If a satellite's provider stops registering (a bad `extra.laravel.providers`
 * entry, a discovery cache, a renamed class), its views simply stop resolving
 * — and the scanning guards that would otherwise notice
 * (`DesignLanguageGuardTest`, `RegisteredComponentTagsTest`) read their roots
 * from these same hints, so they go *vacuous* rather than red: fewer
 * assertions, no failure. This asserts on the hint list directly, which is
 * the only thing that cannot silently shrink to nothing unnoticed.
 */
class SatelliteViewNamespaceTest extends TestCase
{
    /**
     * The first value is the package's directory under `packages/`, not its
     * Composer name: the packages are path-installed and symlinked into
     * `vendor/nvade/*`, but PHP resolves `__FILE__` through the symlink, so
     * every hint a satellite registers names its real `packages/<dir>` path.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function satellites(): array
    {
        return [
            'numerosis-ui' => ['packages/ui', 'components/ui/card.blade.php'],
            'numerosis-auth-ui' => ['packages/auth-ui', 'livewire/auth/register.blade.php'],
            'numerosis-filament' => ['packages/filament', 'filament/admin/pages/register-tenant.blade.php'],
        ];
    }

    #[DataProvider('satellites')]
    public function test_each_installed_satellite_serves_the_shared_view_namespace(string $package, string $sampleView): void
    {
        $finder = view()->getFinder();

        $this->assertInstanceOf(FileViewFinder::class, $finder);

        /** @var array<string, list<string>> $hints */
        $hints = $finder->getHints();
        $paths = $hints['numerosis'] ?? [];

        $matching = array_values(array_filter(
            $paths,
            static fn (string $path): bool => str_contains($path, "/{$package}/"),
        ));

        $this->assertNotEmpty(
            $matching,
            "No path under [{$package}] serves the numerosis:: view namespace. Its service provider is not registering, so every view it owns has silently stopped resolving."
        );

        // The hint alone would still pass if the package shipped an empty
        // directory, so name a file the package is actually responsible for.
        $this->assertTrue(
            array_any(
                $matching,
                static fn (string $path): bool => is_file($path.'/'.$sampleView),
            ),
            "[{$package}] registers a view path but does not contain [{$sampleView}]."
        );
    }
}
